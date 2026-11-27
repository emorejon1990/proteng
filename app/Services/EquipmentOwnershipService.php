<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentModel;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EquipmentOwnershipService
{
    public function create(array $data, User $actor): Equipment
    {
        Gate::forUser($actor)->authorize('create', Equipment::class);

        return DB::transaction(function () use ($data, $actor) {
            $owner = Customer::findOrFail($data['customer_id']);
            $equipment = Equipment::create($this->equipmentData($data) + ['customer_id' => $owner->id]);
            $this->recordTransfer($equipment, null, $owner->id, $actor, null);

            return $equipment;
        });
    }

    public function update(Equipment $equipment, array $data, User $actor): Equipment
    {
        return DB::transaction(function () use ($equipment, $data, $actor) {
            $locked = Equipment::whereKey($equipment->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            $data = $this->equipmentData($data, $locked);
            $locked->fill($data);
            $hasHistory = $locked->installations()->exists() || $locked->installedProducts()->exists();
            if (($locked->isDirty('identifier') || ($locked->getOriginal('equipment_model_id') && $locked->isDirty('equipment_model_id')))
                && $hasHistory) {
                $field = $locked->isDirty('equipment_model_id') ? 'equipment_model_id' : 'identifier';
                throw ValidationException::withMessages(['data.'.$field => 'Equipment identity is fixed once installation history exists.']);
            }
            if ($locked->isDirty('equipment_model_id') && $locked->manual()->whereNotNull('equipment_model_id')->exists()) {
                throw ValidationException::withMessages(['data.equipment_model_id' => 'Review the shared manuals before changing this equipment model.']);
            }
            if (! $locked->getOriginal('equipment_model_id') && $hasHistory) {
                $locked->brand = $locked->getOriginal('brand');
                $locked->model = $locked->getOriginal('model');
            }
            $locked->save();

            return $locked;
        });
    }

    public function transfer(Equipment $equipment, int $customerId, User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($equipment, $customerId, $actor, $reason) {
            $locked = Equipment::whereKey($equipment->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('transfer', $locked);
            if ((int) $locked->customer_id === $customerId) {
                throw ValidationException::withMessages(['customer_id' => 'This customer already owns the equipment.']);
            }
            if ($locked->installations()->whereNotIn('status', [Installation::STATUS_COMPLETED, Installation::STATUS_CANCELED])
                ->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['customer_id' => 'Finish or review active installations before transferring equipment.']);
            }

            $newOwner = Customer::findOrFail($customerId);
            $previous = $locked->customer_id;
            $locked->update(['customer_id' => $newOwner->id]);
            $this->recordTransfer($locked, $previous, $newOwner->id, $actor, $reason);
        }, 3);
    }

    public function delete(Equipment $equipment, User $actor): bool
    {
        return DB::transaction(function () use ($equipment, $actor) {
            $locked = Equipment::whereKey($equipment->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $locked);

            return (bool) $locked->delete();
        }, 3);
    }

    private function equipmentData(array $data, ?Equipment $existing = null): array
    {
        $model = EquipmentModel::with('brand')->whereKey($data['equipment_model_id'] ?? null)
            ->where('equipment_brand_id', $data['equipment_brand_id'] ?? null)->first();
        if (! $model) {
            throw ValidationException::withMessages(['data.equipment_model_id' => 'Select a model belonging to the selected brand.']);
        }
        $data = Arr::only($data, ['equipment_model_id', 'identifier', 'company', 'physical_location', 'operational_status', 'technical_notes']);
        if (! $existing || (int) $existing->equipment_model_id !== $model->id) {
            $data['brand'] = $model->brand->name;
            $data['model'] = $model->name;
        }

        return $data;
    }

    private function recordTransfer(Equipment $equipment, ?int $previous, int $new, User $actor, ?string $reason): void
    {
        $equipment->ownershipTransfers()->create(['previous_customer_id' => $previous, 'new_customer_id' => $new,
            'user_id' => $actor->id, 'reason' => $reason, 'transferred_at' => now()]);
    }
}
