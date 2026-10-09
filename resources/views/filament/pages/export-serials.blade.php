<x-filament-panels::page>
    <form action="{{ route('serials.export') }}" method="POST" target="_blank" class="space-y-6">
        @csrf
        <p>Exporta seriales Reserved y Free pendientes de imprimir. Se marcarán como impresos al abrir las etiquetas.</p>
        <div>
            <label for="quantity" class="block text-sm font-medium mb-2">Cantidad de seriales</label>
            <x-filament::input.wrapper>
                <x-filament::input id="quantity" name="quantity" type="number" min="1" step="1" required value="{{ old('quantity', 1) }}" />
            </x-filament::input.wrapper>
            @error('quantity')
                <p class="text-sm text-danger-600 mt-2">{{ $message }}</p>
            @enderror
        </div>
        <x-filament::button type="submit" icon="heroicon-o-printer">Abrir etiquetas para imprimir</x-filament::button>
    </form>
</x-filament-panels::page>
