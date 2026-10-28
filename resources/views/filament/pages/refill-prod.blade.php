<x-filament-panels::page>
    {{ $this->form }}

    <form wire:submit="scan" class="space-y-2" x-data x-on:refill-scan-focus.window="$nextTick(() => $refs.serial.focus())">
        <label for="refill-serial" class="font-medium">Scan Serial</label>
        <x-filament::input.wrapper :valid="! $errors->has('serialInput')">
            <x-filament::input id="refill-serial" x-ref="serial" wire:model="serialInput" autocomplete="off" autofocus :disabled="$this->operationClosed" />
        </x-filament::input.wrapper>
        @error('serialInput') <p class="text-danger-600" role="alert">{{ $message }}</p> @enderror
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="scan" :disabled="$this->operationClosed">Register scan</x-filament::button>
        @if ($this->operationClosed)<p>This operation is closed. Start a New operation to scan more products.</p>@endif
    </form>

    @if ($operationId)<p>Operation #{{ $operationId }}</p>@endif
    <p>Products registered: {{ $this->entries->count() }}</p>
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead><tr><th>Product Type</th><th>Serial</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse ($this->entries as $entry)
                    <tr wire:key="refill-entry-{{ $entry->id }}">
                        <td>{{ $entry->product?->asset?->name }}</td>
                        <td>{{ $entry->serial }}</td>
                        <td><x-filament::button color="danger" wire:click="mountAction('deleteProduct', { entry: {{ $entry->id }} })">Delete</x-filament::button></td>
                    </tr>
                @empty
                    <tr><td colspan="3">No products registered in this operation.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
