<x-filament-panels::page>
    <div class="flex flex-row">
        @foreach ($this->workOrders as $order)
            <div class="p-4 bg-white rounded shadow card" x-data="{ open: false }">
                <h2 class="text-xl font-bold mb-2">
                    {{ $order->name }}
                    (<button type="button"
                        class="underline decoration-dotted text-left"
                        @click="open = true">
                        {{ $order->asset_name }}
                    </button>)
                </h2>

                <div
                    x-cloak
                    x-show="open"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                    @click.self="open = false"
                >
                    <div class="bg-white rounded shadow-lg max-w-2xl w-full p-6 max-h-[80vh] overflow-y-auto">
                        <div class="flex items-start justify-between mb-4">
                            <h3 class="text-lg font-semibold">
                                {{ $order->asset_name }}
                            </h3>
                            <button type="button" class="text-gray-500" @click="open = false">
                                &#10005;
                            </button>
                        </div>
                        <div class="text-sm text-gray-700">
                            {!! optional($order->asset)->description !!}
                        </div>
                    </div>
                </div>

                <livewire:work-order-assemble-editor :work-order="$order" :wire:key="'wo-'.$order->id" />
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
