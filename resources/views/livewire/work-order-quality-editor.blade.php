<div class="p-4 border rounded bg-white shadow space-y-4"
     x-data="{ scaleActive: false }"
     x-on:quality-product-selected.window="scaleActive = Number($event.detail.orderId) === {{ $workOrder->id }}"
     x-on:quality-selection-cleared.window="if (Number($event.detail.orderId) === {{ $workOrder->id }}) scaleActive = false"
     x-on:quality-saved.window="if (Number($event.detail.orderId) === {{ $workOrder->id }}) { scaleActive = false; $nextTick(() => $refs.serial?.focus()); }"
     x-on:quality-weigh-again.window="if (Number($event.detail.orderId) === {{ $workOrder->id }}) $nextTick(() => $refs.weight?.focus())">
    <h3 class="text-lg font-bold">Work Order: {{ $workOrder->name }}</h3>
    <p>Productos: {{ $total }} · Revisados: {{ $completedCount }} · Pendientes: {{ $pendingCount }}</p>
    @error('workOrder') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @if ($pendingCount > 0)
        <form wire:submit="scanSerial" class="space-y-2">
            <label for="quality-serial-{{ $workOrder->id }}" class="block text-sm font-medium">Serial</label>
            <x-filament::input.wrapper>
                <x-filament::input id="quality-serial-{{ $workOrder->id }}" x-ref="serial" type="text" wire:model="serialInput" maxlength="255" autocomplete="off" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit" wire:loading.attr="disabled">Identificar tubo</x-filament::button>
        </form>
    @endif
    @error('serialInput') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @if ($this->currentProduct)
        <form wire:submit="evaluateWeight" class="space-y-3" wire:key="quality-product-{{ $selectedProductId }}">
            <p class="font-medium">Producto: {{ $this->currentProduct->asset?->name }} · Serial: {{ $selectedSerial }}</p>
            <p>Peso de llenado: {{ $measurement['fill_weight'] ?? $this->currentProduct->cycles()->reorder()->orderByDesc('cycle_number')->first()?->fill_weight ?? $this->currentProduct->weight }} g · Tolerancia: ±5 g</p>
            <label for="quality-weight-{{ $workOrder->id }}" class="block text-sm font-medium">Peso de calidad (g)</label>
            <x-filament::input.wrapper>
                <x-filament::input id="quality-weight-{{ $workOrder->id }}" x-ref="weight" type="number" step="0.0001" min="0.0001" required
                    wire:model.live.debounce.300ms="currentProductData.weight" data-quality-weight data-product-id="{{ $selectedProductId }}"
                    x-bind:data-scale-active="scaleActive ? 'true' : 'false'" />
            </x-filament::input.wrapper>
            @error('currentProductData.weight') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            <p x-show="scaleActive" class="text-sm text-gray-600">La báscula está vinculada a este tubo.</p>
            <x-filament::button type="submit" wire:loading.attr="disabled">Evaluar peso</x-filament::button>
            @if ($measurement === null)
                <x-filament::button type="button" color="gray" wire:click="activateScale" wire:loading.attr="disabled">Usar báscula para este tubo</x-filament::button>
            @endif
        </form>
        @if ($measurement)
            <div role="status" class="space-y-2 {{ $measurement['approved'] ? 'text-green-700' : 'text-red-600' }}">
                <p>Peso actual: {{ $measurement['quality_weight'] }} g · Diferencia: {{ $measurement['difference_g'] }} g</p>
                <p class="font-medium">{{ $measurement['approved'] ? 'APROBADO: cumple la tolerancia de ±5 g.' : 'ADVERTENCIA: el producto supera la diferencia permitida de ±5 g.' }}</p>
                @if ($measurement['approved'])
                    <x-filament::button wire:click="saveAndNext" wire:loading.attr="disabled">Guardar aprobación</x-filament::button>
                @else
                    <x-filament::button color="danger" wire:click="requestReview" wire:loading.attr="disabled">Enviar a revisión</x-filament::button>
                @endif
                <x-filament::button color="gray" wire:click="weighAgain" wire:loading.attr="disabled">Volver a pesar</x-filament::button>
            </div>
        @endif
        @if ($confirmingRejection)
            <div role="alertdialog" aria-modal="true" aria-labelledby="quality-confirm-{{ $workOrder->id }}" class="p-4 border border-red-500 rounded space-y-3">
                <p id="quality-confirm-{{ $workOrder->id }}">¿Confirmas el rechazo del serial {{ $selectedSerial }} y su incorporación a una orden de revisión?</p>
                <p>Se guardará el peso de {{ $measurement['quality_weight'] }} g y la diferencia de {{ $measurement['difference_g'] }} g.</p>
                <x-filament::button color="danger" wire:click="confirmReview" wire:loading.attr="disabled">Confirmar envío a revisión</x-filament::button>
                <x-filament::button color="gray" wire:click="cancelReview" wire:loading.attr="disabled">Cancelar</x-filament::button>
            </div>
        @endif
    @endif
    @error('confirmation') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @if ($notice) <p role="status" class="text-green-600">{{ $notice }}</p> @endif
</div>
