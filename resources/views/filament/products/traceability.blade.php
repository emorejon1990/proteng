<div class="space-y-4">
    <dl class="grid grid-cols-2 gap-2 text-sm">
        <dt>Product</dt><dd>{{ $product->asset?->name }}</dd>
        <dt>Serial</dt><dd>{{ $product->serial }}</dd>
        <dt>Assembly date</dt><dd>{{ $product->assambly_date }}</dd>
        <dt>Production Work Order</dt><dd>{{ $product->productionWorkOrder?->name }}</dd>
        <dt>Current status</dt><dd>{{ $product->status?->name }}</dd>
        <dt>Current location</dt><dd>{{ $product->location?->name }}</dd>
    </dl>

    <x-filament::section>
        <x-slot name="heading">Installation history</x-slot>
        @include('filament.installations.installed-products')
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Movement history</x-slot>
        <ul class="space-y-2 text-sm">
            @forelse ($movements as $movement)
                <li>
                    <div class="font-medium">{{ $movement->date }} · {{ $movement->process }}</div>
                    <div>{{ $movement->description }}</div>
                    <div>{{ implode(' · ', array_filter([$movement->location, $movement->workOrder?->name, $movement->user?->name])) }}</div>
                </li>
            @empty
                <li>No movements recorded.</li>
            @endforelse
        </ul>
    </x-filament::section>
</div>
