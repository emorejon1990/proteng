<?php

namespace App\Services;

use App\Models\Installation;
use Illuminate\Support\Facades\DB;

class InstallationService
{
    public function create(array $data): Installation
    {
        return DB::transaction(function () use ($data) {
            $installation = Installation::create([
                ...$data,
                'customer_quickbooks_id' => $data['customer_quickbooks_id']
                    ?? optional(\App\Models\Customer::find($data['customer_id']))->quickbooks_id,
            ]);

            $this->syncAssignment($installation);
            $this->cloneTemplateSteps($installation);

            return $installation;
        });
    }

    public function syncAssignment(Installation $installation): void
    {
        $installation->assignment()->updateOrCreate(
            ['installation_id' => $installation->id],
            [
                'customer_id' => $installation->customer_id,
                'equipment_id' => $installation->equipment_id,
            ]
        );
    }

    public function cloneTemplateSteps(Installation $installation): void
    {
        $templates = $installation->equipment
            ->installationTemplateSteps()
            ->orderBy('sort_order')
            ->get();

        $installation->steps()->delete();

        foreach ($templates as $template) {
            $installation->steps()->create([
                'title' => $template->title,
                'description' => $template->description,
                'sort_order' => $template->sort_order,
                'is_required' => $template->is_required,
                'img' => $template->img,
                'is_done' => false,
                'done_at' => null,
                'notes' => null,
            ]);
        }
    }
}
