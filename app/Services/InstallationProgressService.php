<?php

namespace App\Services;

use App\Models\Installation;
use App\Models\InstallationStep;
use Illuminate\Support\Carbon;

class InstallationProgressService
{
    public function startIfNeeded(Installation $installation): void
    {
        if (in_array($installation->status, ['draft', 'scheduled'], true)) {
            $installation->update(['status' => 'in_progress']);
        }
    }

    public function markStep(InstallationStep $step, bool $isDone, ?string $notes = null): void
    {
        $step->update([
            'is_done' => $isDone,
            'done_at' => $isDone ? Carbon::now() : null,
            'notes' => $notes,
        ]);
    }

    public function completeIfAllDone(Installation $installation): bool
    {
        $pending = $installation->steps()->where('is_done', false)->exists();

        if ($pending) {
            return false;
        }

        $installation->update([
            'status' => 'completed', // "Done" en UI mapea aquí
            'performed_at' => Carbon::now(),
        ]);

        return true;
    }
}
