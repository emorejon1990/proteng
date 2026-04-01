<?php

namespace App\Filament\Worker\Pages;

use App\Models\Installation;
use App\Models\InstallationStep;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use App\Services\InstallationProgressService;

class WorkerInstallationWizard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'My Installation';
    protected static ?string $navigationGroup = 'Installations';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'installations/current';
    protected static string $view = 'filament.worker.pages.worker-installation-wizard';

    public ?Installation $installation = null;
    public array $steps = [];
    public int $currentStepIndex = 0;
    public bool $currentStepDone = false;
    public ?string $currentStepNotes = null;
    public bool $completed = false;

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRol(['Worker']) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->installation = Installation::query()
            ->with(['customer', 'equipment', 'steps'])
            ->where('worker_user_id', Auth::id())
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->latest('id')
            ->first();

        if (! $this->installation) {
            return;
        }

        app(InstallationProgressService::class)->startIfNeeded($this->installation);
        $this->installation->refresh()->load(['customer', 'equipment', 'steps']);

        $this->steps = $this->installation->steps->sortBy('sort_order')->values()->toArray();
        $this->currentStepIndex = $this->firstPendingStepIndex();
        $this->hydrateCurrentStepState();
    }

    public function previousStep(): void
    {
        $this->saveCurrentStep();

        if ($this->currentStepIndex > 0) {
            $this->currentStepIndex--;
            $this->hydrateCurrentStepState();
        }
    }

    public function nextStep(): void
    {
        $this->saveCurrentStep();

        $step = $this->currentStep();
        if (! $step) {
            return;
        }

        if (($step['is_required'] ?? false) && ! $step['is_done']) {
            Notification::make()
                ->title('Debes marcar este paso como Done para continuar')
                ->warning()
                ->send();
            return;
        }

        if ($this->currentStepIndex >= (count($this->steps) - 1)) {
            $this->finishInstallation();
            return;
        }

        $this->currentStepIndex++;
        $this->hydrateCurrentStepState();
    }

    public function saveCurrentStep(): void
    {
        $step = $this->currentStep();
        if (! $step || ! $this->installation) {
            return;
        }

        $stepModel = InstallationStep::query()
            ->where('installation_id', $this->installation->id)
            ->find($step['id']);

        if (! $stepModel) {
            return;
        }

        app(InstallationProgressService::class)->markStep(
            $stepModel,
            $this->currentStepDone,
            $this->currentStepNotes
        );

        $this->reloadStepsKeepingIndex();
    }

    public function finishInstallation(): void
    {
        if (! $this->installation) {
            return;
        }

        $this->saveCurrentStep();

        $completed = app(InstallationProgressService::class)->completeIfAllDone($this->installation->refresh());

        if (! $completed) {
            Notification::make()
                ->title('Aún faltan pasos por completar')
                ->danger()
                ->send();
            return;
        }

        $this->completed = true;
        $this->installation->refresh();

        Notification::make()
            ->title('Installation completed')
            ->success()
            ->send();
    }

    public function progressLabel(): string
    {
        $total = count($this->steps);
        $current = $total > 0 ? $this->currentStepIndex + 1 : 0;

        return "Step {$current} of {$total}";
    }

    public function currentStep(): ?array
    {
        return $this->steps[$this->currentStepIndex] ?? null;
    }

    protected function firstPendingStepIndex(): int
    {
        foreach ($this->steps as $index => $step) {
            if (! ($step['is_done'] ?? false)) {
                return $index;
            }
        }

        return 0;
    }

    protected function hydrateCurrentStepState(): void
    {
        $step = $this->currentStep();

        if (! $step) {
            $this->currentStepDone = false;
            $this->currentStepNotes = null;
            return;
        }

        $this->currentStepDone = (bool) ($step['is_done'] ?? false);
        $this->currentStepNotes = $step['notes'] ?? null;
    }

    protected function reloadStepsKeepingIndex(): void
    {
        if (! $this->installation) {
            return;
        }

        $this->installation->refresh()->load('steps');
        $this->steps = $this->installation->steps->sortBy('sort_order')->values()->toArray();

        if ($this->currentStepIndex > count($this->steps) - 1) {
            $this->currentStepIndex = max(count($this->steps) - 1, 0);
        }

        $this->hydrateCurrentStepState();
    }
}
