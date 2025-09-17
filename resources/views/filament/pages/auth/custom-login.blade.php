<x-filament-panels::page.simple>
    <div class="flex flex-col items-center space-y-4 mb-6">
        <img src="{{ asset('/proteng.png') }}" alt="Logo" class="h-16">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
            {{ config('app.name', 'PROTENG LAB') }}
        </h1>
    </div>

    {{-- Formulario de login --}}
    <x-filament-panels::form wire:submit="authenticate">
        {{ $this->form }}

        {{-- Botones de acción (ej. Sign in) --}}
        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
            class="mt-6"
        />
    </x-filament-panels::form>
</x-filament-panels::page.simple>
