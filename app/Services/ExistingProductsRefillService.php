<?php

namespace App\Services;

use App\Models\History;
use App\Models\Products;
use App\Models\RefillEntry;
use App\Models\RefillOperation;
use App\Models\Serial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExistingProductsRefillService
{
    public function authorize(?User $actor): void
    {
        abort_unless($actor?->hasRole('Admin') && ! $actor->hasRole('Customer'), 403);
    }

    public function operations(User $actor)
    {
        $this->authorize($actor);

        return RefillOperation::where('user_id', $actor->id)->orderByDesc('id');
    }

    public function operation(int $id, User $actor): RefillOperation
    {
        return $this->operations($actor)->findOrFail($id);
    }

    public function close(int $id, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($id, $actor) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $operation = $this->operations($actor)->whereKey($id)->lockForUpdate()->firstOrFail();
            $operation->update(['active_user_id' => null, 'closed_at' => $operation->closed_at ?? now()]);
        }, 3);
    }

    public function scan(array $data, string $input, ?int $operationId, User $actor): RefillEntry
    {
        $this->authorize($actor);
        $data = $this->validated($data);
        $input = strtoupper(trim($input));
        Validator::make(['serialInput' => $input], ['serialInput' => ['required', 'regex:/^[A-Z][0-9]{7}[A-Z]$/D']])->validate();

        return DB::transaction(function () use ($data, $input, $operationId, $actor) {
            // One open operation per user, even when two tabs start scanning together.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $operation = $operationId ? $this->operations($actor)->whereKey($operationId)->lockForUpdate()->firstOrFail()
                : $this->operations($actor)->where('active_user_id', $actor->id)->lockForUpdate()->first();
            if ($operation && ($operation->closed_at || $operation->data != $data)) {
                $this->fail('data.asset_id', 'This operation is closed or its form has changed. Select the open operation or start a new one.');
            }
            $serial = Serial::where('serial', $input)->lockForUpdate()->first();
            if (! $serial || $serial->status !== Serial::STATUS_FREE || $serial->products_id !== null
                || Products::where('serial', $input)->exists()
                || History::where('metadata->serial', $input)->exists()) {
                $this->fail('serialInput', 'Serial must exist, be Free, never previously used and have no product or Production reservation.');
            }
            $operation ??= RefillOperation::create(['user_id' => $actor->id, 'active_user_id' => $actor->id, 'data' => $data]);
            $product = Products::create([...$data, 'serial' => $serial->serial,
                'assambly_date' => now(), 'fill_date' => now(), 'quality_date' => now(), 'f_weight' => $data['weight']]);
            $serial->update(['status' => Serial::STATUS_USED, 'products_id' => $product->id]);
            $history = $product->history()->create(['process' => 'Refill registered', 'date' => now(), 'user_id' => $actor->id,
                'location' => 'Stock', 'description' => 'Existing finished product registered by individual serial scan.',
                'metadata' => ['refill_operation_id' => $operation->id, 'serial' => $serial->serial]]);

            return RefillEntry::create(['refill_operation_id' => $operation->id, 'product_id' => $product->id,
                'original_product_id' => $product->id, 'serial_id' => $serial->id, 'serial' => $serial->serial,
                'origin_history_id' => $history->id, 'snapshot' => $product->fresh()->getRawOriginal(),
                'origin_history' => $history->fresh()->getRawOriginal()]);
        }, 3);
    }

    public function delete(int $operationId, int $entryId, User $actor): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($operationId, $entryId, $actor) {
            $operation = $this->operations($actor)->whereKey($operationId)->lockForUpdate()->firstOrFail();
            $entry = $operation->entries()->whereKey($entryId)->lockForUpdate()->firstOrFail();
            if ($entry->deleted_at) {
                return; // Repeated confirmation is harmless.
            }
            // All operational flows also lock the product before reserving or moving it.
            $product = Products::whereKey($entry->product_id)->lockForUpdate()->firstOrFail();
            $serial = Serial::whereKey($entry->serial_id)->lockForUpdate()->firstOrFail();
            $catalog = app(ProductionCatalog::class);
            if ((int) $product->location_id !== $catalog->id('stock_location')
                || (int) $product->status_id !== $catalog->id('ready_product_status')
                || $product->work_order_id !== null || $product->production_work_order_id !== null
                || $product->getRawOriginal() != $entry->snapshot
                || $product->orderAssociations()->exists() || $product->cycles()->exists()
                || $product->repairs()->exists() || $product->activeAssignment()->exists()
                || (Schema::hasTable('installed_products') && DB::table('installed_products')->where('product_id', $product->id)->exists())) {
                $this->fail('selection', 'Cannot delete: this product has changed, left Stock or has subsequent movements or operational associations.');
            }
            $histories = $product->history()->lockForUpdate()->get();
            if ($histories->count() !== 1 || $histories->first()->id !== $entry->origin_history_id
                || $histories->first()->getRawOriginal() != $entry->origin_history
                || $serial->status !== Serial::STATUS_USED || (int) $serial->products_id !== $product->id
                || $serial->serial !== $entry->serial || Serial::where('products_id', $product->id)->count() !== 1) {
                $this->fail('selection', 'Cannot delete: product history or serial assignment has changed.');
            }
            // Reverse ONLY this operation's untouched creation event; retain its full audit snapshot.
            $histories->first()->delete();
            $serial->update(['status' => Serial::STATUS_FREE, 'products_id' => null]);
            $entry->update(['product_id' => null, 'deleted_at' => now(), 'deleted_by' => $actor->id]);
            $product->delete();
        }, 3);
    }

    private function validated(array $data): array
    {
        $data = Validator::make($data, [
            'asset_id' => ['required', 'integer', 'exists:asset,id'],
            'assambly_by' => ['required', 'integer', 'exists:users,id'],
            'fill_by' => ['required', 'integer', 'exists:users,id'],
            'quality_by' => ['required', 'integer', 'exists:users,id'],
            'assambled' => ['required', 'accepted'], 'filled' => ['required', 'accepted'], 'qualified' => ['required', 'accepted'],
            'status_id' => ['required', 'integer', 'exists:status,id'], 'location_id' => ['required', 'integer', 'exists:locations,id'],
            'weight' => ['required', 'numeric', 'gt:0'],
        ])->validate();
        $catalog = app(ProductionCatalog::class);
        if ((int) $data['status_id'] !== $catalog->id('ready_product_status') || (int) $data['location_id'] !== $catalog->id('stock_location')) {
            $this->fail('data.status_id', 'Existing finished products must be Ready and located in Stock.');
        }

        return $data;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
