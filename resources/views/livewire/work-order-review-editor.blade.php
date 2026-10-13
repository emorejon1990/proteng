<div class="p-4 bg-white border rounded shadow space-y-4"
     x-data="{}" x-on:review-saved.window="if (Number($event.detail.orderId) === {{ $workOrder->id }}) $nextTick(() => $refs.serial?.focus())">
    <h3 class="text-lg font-bold">Work Order: {{ $workOrder->name }}</h3>
    <p>Productos: {{ $total }} · Revisados: {{ $reviewedCount }} · Pendientes: {{ $pendingCount }}</p>
    @error('workOrder') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @if ($pendingCount > 0)
        <form wire:submit="scanSerial" class="space-y-2">
            <label for="review-serial-{{ $workOrder->id }}">Serial</label>
            <x-filament::input.wrapper>
                <x-filament::input id="review-serial-{{ $workOrder->id }}" x-ref="serial" wire:model="serialInput" maxlength="255" autocomplete="off" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit" wire:loading.attr="disabled">Identificar tubo</x-filament::button>
        </form>
    @endif
    @error('serialInput') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @if ($this->currentProduct)
        <form wire:submit="save" wire:key="review-product-{{ $selectedProductId }}" class="space-y-3">
            <p class="font-medium">{{ $this->currentProduct->asset?->name }} · Serial: {{ $selectedSerial }}</p>
            <label for="review-diagnosis-{{ $workOrder->id }}" class="block">Diagnóstico</label>
            <textarea id="review-diagnosis-{{ $workOrder->id }}" wire:model="decision.diagnosis" maxlength="5000" required class="w-full border rounded"></textarea>
            @error('decision.diagnosis') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            <label for="review-result-{{ $workOrder->id }}" class="block">Resultado</label>
            <select id="review-result-{{ $workOrder->id }}" wire:model.live="decision.result" class="w-full border rounded">
                <option value="repaired">Reparable: reparación completada</option>
                <option value="recycled">Irreparable: reciclar</option>
            </select>
            @error('decision.result') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            @if (($decision['result'] ?? null) === 'repaired')
                <label for="review-repair-{{ $workOrder->id }}" class="block">Reparación realizada</label>
                <textarea id="review-repair-{{ $workOrder->id }}" wire:model="decision.repair_description" maxlength="5000" required class="w-full border rounded"></textarea>
                @error('decision.repair_description') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            @else
                <label for="review-reason-{{ $workOrder->id }}" class="block">Motivo de reciclaje</label>
                <textarea id="review-reason-{{ $workOrder->id }}" wire:model="decision.recycling_reason" maxlength="5000" required class="w-full border rounded"></textarea>
                @error('decision.recycling_reason') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
            @endif
            <x-filament::button type="submit" wire:loading.attr="disabled">{{ ($decision['result'] ?? null) === 'repaired' ? 'Guardar reparación' : 'Solicitar reciclaje' }}</x-filament::button>
        </form>
    @endif
    @if ($recyclingConfirmation)
        <div role="alertdialog" aria-modal="true" aria-labelledby="review-confirm-{{ $workOrder->id }}" class="p-4 border border-red-500 rounded space-y-3">
            <p id="review-confirm-{{ $workOrder->id }}">¿Confirmas reciclar el tubo {{ $selectedSerial }}?</p>
            <p>{{ $recyclingConfirmation['recycling_reason'] }}</p>
            <x-filament::button color="danger" wire:click="confirmRecycling" wire:loading.attr="disabled">Confirmar reciclaje</x-filament::button>
            <x-filament::button color="gray" wire:click="cancelRecycling">Cancelar</x-filament::button>
        </div>
    @endif
    @error('confirmation') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @if ($notice)<p role="status" class="text-green-600">{{ $notice }}</p>@endif
</div>
