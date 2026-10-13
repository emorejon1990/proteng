<div class="p-4 border rounded bg-white shadow space-y-4" x-data="{}"
     x-on:distribution-selection-changed.window="if (Number($event.detail.orderId) === {{ $workOrder->id }}) $nextTick(() => $refs.serial?.focus())">
    <table class="w-full text-left">
        <thead><tr><th>Modelo</th><th>Solicitados</th><th>Escaneados</th><th>Pendientes</th><th>Disponibles</th><th>Faltantes</th></tr></thead>
        <tbody>
        @foreach ($models as $model)
            <tr wire:key="distribution-model-{{ $workOrder->id }}-{{ $model['id'] }}">
                <td>{{ $model['name'] }}</td><td>{{ $model['quantity'] }}</td><td>{{ $model['selected'] }}</td>
                <td>{{ $model['pending'] }}</td><td>{{ $model['available'] }}</td><td>{{ $model['shortage'] }}
                @if ($open && $model['shortage'] > 0 && ! $model['hasWO'])
                    <x-filament::button wire:click="CreateWO({{ $model['id'] }})" size="sm">Crear WO por faltantes</x-filament::button>
                @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @if ($open)
        <form wire:submit="scanSerial" class="space-y-3">
            <label for="distribution-model-{{ $workOrder->id }}">Modelo a escanear</label>
            <select id="distribution-model-{{ $workOrder->id }}" wire:model.live="assetId" class="border rounded">
                @foreach ($models as $model)<option value="{{ $model['id'] }}">{{ $model['name'] }}</option>@endforeach
            </select>
            <label for="distribution-serial-{{ $workOrder->id }}">Serial</label>
            <x-filament::input.wrapper><x-filament::input id="distribution-serial-{{ $workOrder->id }}" x-ref="serial" wire:model="serialInput" maxlength="255" autocomplete="off" /></x-filament::input.wrapper>
            <x-filament::button type="submit" wire:loading.attr="disabled">Escanear y reservar</x-filament::button>
        </form>
    @endif
        <ul class="space-y-2">
            @foreach ($selections as $selection)
                <li wire:key="distribution-selection-{{ $selection->id }}">
                    {{ $selection->distribution_serial }} · {{ $selection->distributionAsset?->name }}
                    @if ($open)<x-filament::button color="gray" size="sm" wire:click="removeSelection({{ $selection->id }})" wire:loading.attr="disabled">Retirar</x-filament::button>@endif
                </li>
            @endforeach
        </ul>
    @if ($open)
        <x-filament::button wire:click="saveAndNext" :disabled="! $complete" wire:loading.attr="disabled">Confirmar distribución</x-filament::button>
    @endif
    @foreach (['serialInput', 'assetId', 'selection', 'workOrder'] as $errorKey)
        @error($errorKey)<p role="alert" class="text-red-600">{{ $message }}</p>@enderror
    @endforeach
    @if ($notice)<p role="status" class="text-green-600">{{ $notice }}</p>@endif
</div>
