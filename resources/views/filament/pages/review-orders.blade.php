<x-filament-panels::page>
    <x-filament::button tag="a" :href="\App\Filament\Pages\Review::getUrl()">Abrir estación de revisión</x-filament::button>
    @if ($availabilityError)
        <p role="alert" class="text-red-600">{{ $availabilityError }}</p>
    @endif
    @error('review') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @error('workOrder') <p role="alert" class="text-red-600">{{ $message }}</p> @enderror
    @if ($pendingRejections->isNotEmpty())
        <section class="p-4 bg-white rounded shadow space-y-3">
            <h2 class="text-lg font-bold">Rechazos pendientes de incorporación</h2>
            @foreach ($pendingRejections as $cycle)
                <div class="flex items-center gap-4" wire:key="pending-review-{{ $cycle->id }}">
                    <span>{{ $cycle->product->serial }} · {{ $cycle->product->asset?->name }}</span>
                    <x-filament::button wire:click="mountAction('incorporateRejection', { cycleId: {{ $cycle->id }} })">Incorporar a Review</x-filament::button>
                </div>
            @endforeach
        </section>
    @endif
    @forelse ($reviewOrders as $order)
        @php($members = $order->productAssociations->where('purpose', 'review'))
        <section class="p-4 bg-white rounded shadow space-y-3" wire:key="review-order-{{ $order->id }}">
            <h2 class="text-xl font-bold">{{ $order->name }} · {{ $members->count() }}/50 productos</h2>
            <p>Estado: {{ $order->status?->name }} · {{ $order->review_receiving_open ? 'Recibiendo productos' : 'Recepción cerrada' }}</p>
            @if (! $order->review_receiving_open && (int) $order->status_id === $newStatus)
                <p>Pendiente de aprobación.</p>
            @endif
            <div class="flex gap-3">
                @if ($order->review_receiving_open)
                    <x-filament::button wire:click="mountAction('closeReview', { orderId: {{ $order->id }} })">Cerrar y enviar a aprobación</x-filament::button>
                @elseif ((int) $order->status_id === $newStatus)
                    <x-filament::button wire:click="mountAction('approveReview', { orderId: {{ $order->id }} })">Aprobar</x-filament::button>
                @elseif ((int) $order->status_id === $approvedStatus)
                    <x-filament::button wire:click="mountAction('startReview', { orderId: {{ $order->id }} })">Iniciar</x-filament::button>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead><tr><th class="p-2">Serial</th><th class="p-2">Modelo</th><th class="p-2">Orden original</th></tr></thead>
                    <tbody>
                        @foreach ($members as $member)
                            <tr><td class="p-2">{{ $member->product->serial }}</td><td class="p-2">{{ $member->product->asset?->name }}</td><td class="p-2">{{ $member->product->productionWorkOrder?->name }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        @if (! $availabilityError)<p>No hay órdenes Review.</p>@endif
    @endforelse
</x-filament-panels::page>
