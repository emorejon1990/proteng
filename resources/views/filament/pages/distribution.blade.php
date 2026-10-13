<x-filament-panels::page>
    @if ($availabilityError)<p role="alert" class="text-red-600">{{ $availabilityError }}</p>@endif
    @error('serialInput')<p role="alert">{{ $message }}</p>@enderror
    @error('workOrder')<p role="alert">{{ $message }}</p>@enderror
    <livewire:qr-scanner-modal />
    <div class="flex flex-row">
        @foreach ($this->workOrders as $order)
            <div class="p-4 bg-white rounded shadow card">
                <h2 class="text-xl font-bold mb-2">
                    {{ $order->name }} ({{ $order->asset_name }})
                </h2>
                <x-filament::button wire:click="openScanner({{ $order->id }})">Escanear con cámara</x-filament::button>

                <livewire:work-order-distribution-editor :work-order="$order" :wire:key="'wo-'.$order->id" />
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
