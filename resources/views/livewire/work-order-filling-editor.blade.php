<div class="p-4 border rounded bg-white shadow space-y-4"
     x-data="{ scaleActive: false }"
     x-on:filling-product-selected.window="scaleActive = Number($event.detail.orderId) === {{ $workOrder->id }}"
     x-on:filling-selection-cleared.window="if (Number($event.detail.orderId) === {{ $workOrder->id }}) scaleActive = false"
     x-on:filling-saved.window="if (Number($event.detail.orderId) === {{ $workOrder->id }}) { scaleActive = false; $nextTick(() => $refs.serial?.focus()); }">
    <h3 class="text-lg font-bold">Work Order: {{ $workOrder->name }}</h3>
    <p>Productos: {{ $total }} · Llenados: {{ $filledCount }} · Pendientes: {{ $pendingCount }}</p>
    @error('workOrder')
        <p role="alert" class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @if ($pendingCount > 0)
        <form wire:submit="scanSerial" class="space-y-2">
            <label for="filling-serial-{{ $workOrder->id }}" class="block text-sm font-medium">Serial</label>
            <x-filament::input.wrapper>
                <x-filament::input id="filling-serial-{{ $workOrder->id }}" x-ref="serial" type="text"
                    wire:model="serialInput" maxlength="255" autocomplete="off" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="scanSerial,saveAndNext">Identificar tubo</x-filament::button>
        </form>
    @endif
    @error('serialInput')
        <p role="alert" class="text-sm text-red-600">{{ $message }}</p>
    @enderror
    @if ($this->currentProduct)
        <form wire:submit="saveAndNext" class="space-y-4" wire:key="filling-product-{{ $selectedProductId }}">
            <p class="font-medium">Producto: {{ $this->currentProduct->asset?->name }} · Serial: {{ $selectedSerial }}</p>
            <p class="text-sm text-gray-600">Peso permitido: {{ $this->currentProduct->asset?->weight - $this->currentProduct->asset?->weight_tolerance }}–{{ $this->currentProduct->asset?->weight + $this->currentProduct->asset?->weight_tolerance }} g</p>
            <label for="filling-weight-{{ $workOrder->id }}" class="block text-sm font-medium">Peso (g)</label>
            <x-filament::input.wrapper>
                <x-filament::input id="filling-weight-{{ $workOrder->id }}" type="number" step="0.0001" min="0.0001"
                    wire:model="currentProductData.weight" required data-filling-weight data-product-id="{{ $selectedProductId }}"
                    x-bind:data-scale-active="scaleActive ? 'true' : 'false'" />
            </x-filament::input.wrapper>
            @error('currentProductData.weight')
                <p role="alert" class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            <p x-show="scaleActive" class="text-sm text-gray-600">La báscula está vinculada a este tubo.</p>
            <x-filament::button type="button" color="gray" wire:click="activateScale">Usar báscula para este tubo</x-filament::button>
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="scanSerial,saveAndNext,receiveScaleWeight">Guardar llenado</x-filament::button>
        </form>
    @endif
    @if ($notice)
        <p role="status" class="text-green-600">{{ $notice }}</p>
    @endif
</div>
