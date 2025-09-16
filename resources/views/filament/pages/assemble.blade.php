<x-filament-panels::page>
    <div class="flex flex-row">
        @foreach ($this->workOrders as $order)
            <div class="p-4 bg-white rounded shadow card">
                <h2 class="text-xl font-bold mb-2">
                    {{ $order->name }} ({{ $order->asset_name }})
                </h2>

                <livewire:work-order-assemble-editor :work-order="$order" :wire:key="'wo-'.$order->id" />
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
