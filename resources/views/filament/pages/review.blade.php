<x-filament-panels::page>
    @if ($availabilityError)<p role="alert" class="text-red-600">{{ $availabilityError }}</p>@endif
    @error('serialInput') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @error('workOrder') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    <livewire:qr-scanner-modal wire:key="review-qr-scanner" />
    @forelse ($workOrders as $order)
        <section class="space-y-3" wire:key="review-station-{{ $order->id }}">
            <x-filament::button wire:click="openScanner({{ $order->id }})">Escanear tubo con cámara</x-filament::button>
            <livewire:work-order-review-editor :work-order="$order" :wire:key="'review-editor-'.$order->id" />
        </section>
    @empty
        @if (! $availabilityError)<p>No hay órdenes pendientes en revisión.</p>@endif
    @endforelse
</x-filament-panels::page>
