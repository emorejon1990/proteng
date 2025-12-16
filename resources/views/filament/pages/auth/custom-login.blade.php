<x-filament-panels::page.simple>
    <div class="flex flex-col items-center space-y-4 mb-6">
        <img src="{{ asset('/proteng.png') }}" class="h-16">
        <h1 class="text-2xl font-bold">
            {{ config('app.name', 'PROTENG LAB') }}
        </h1>
    </div>

    <x-filament-panels::form>
        {{ $this->form }}

        <x-filament::button
            wire:click="authenticate"
            type="button"
            class="w-full mt-4"
        >
            Login
        </x-filament::button>
    </x-filament-panels::form>
</x-filament-panels::page.simple>


