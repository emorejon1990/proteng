<x-filament-panels::page>
    <form wire:submit="generate" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="generate">Generate Serials</x-filament::button>
    </form>
</x-filament-panels::page>
