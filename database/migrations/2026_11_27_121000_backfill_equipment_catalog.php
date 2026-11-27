<?php

use App\Models\EquipmentBrand;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $timestamp = now();
            foreach (DB::table('equipment')->orderBy('id')->cursor() as $equipment) {
                $brand = Str::squish($equipment->brand ?? '');
                $model = Str::squish($equipment->model ?? '');
                if ($brand === '' || $model === '' || mb_strlen($brand) > 255 || mb_strlen($model) > 255) {
                    continue;
                }

                $brandKey = EquipmentBrand::nameKey($brand);
                $brandId = DB::table('equipment_brands')->where('name_key', $brandKey)->value('id')
                    ?? DB::table('equipment_brands')->insertGetId([
                        'name' => $brand, 'name_key' => $brandKey, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                    ]);
                $modelKey = EquipmentBrand::nameKey($model);
                $modelId = DB::table('equipment_models')->where('equipment_brand_id', $brandId)->where('name_key', $modelKey)->value('id')
                    ?? DB::table('equipment_models')->insertGetId([
                        'equipment_brand_id' => $brandId, 'name' => $model, 'name_key' => $modelKey,
                        'created_at' => $timestamp, 'updated_at' => $timestamp,
                    ]);

                DB::table('equipment')->where('id', $equipment->id)->update(['equipment_model_id' => $modelId]);
            }

            foreach (DB::table('equipment_models')->orderBy('id')->cursor() as $model) {
                $canonical = null;
                $divergent = false;
                foreach (DB::table('equipment')->where('equipment_model_id', $model->id)->orderBy('id')->pluck('id') as $equipmentId) {
                    $guide = DB::table('equipment_installation_steps')->where('equipment_id', $equipmentId)
                        ->orderBy('sort_order')->orderBy('id')->get(['title', 'description', 'sort_order', 'is_required', 'img'])
                        ->map(fn ($row) => (array) $row)->all();
                    if ($guide === []) {
                        continue;
                    }
                    $canonical ??= $guide;
                    if ($guide !== $canonical) {
                        $divergent = true;
                        break;
                    }
                }
                if ($divergent) {
                    DB::table('equipment_models')->where('id', $model->id)->update(['guide_needs_review' => true]);
                } elseif ($canonical) {
                    DB::table('equipment_model_steps')->insert(array_map(fn (array $row) => $row + [
                        'equipment_model_id' => $model->id, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                    ], $canonical));
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The catalog backfill requires a data-preserving rollback plan.');
    }
};
