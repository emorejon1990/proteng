<x-filament-panels::page>
    <div >
        <button id="butConnect" type="button" style="--c-400:var(--info-400);--c-500:var(--info-500);--c-600:var(--info-600);" class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-info fi-color-info fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 fi-ac-action fi-ac-btn-action">
            Connect
        </button>
    </div>
    <div class="flex flex-row"
        x-data="{}"
        x-load-js="[@js(\Filament\Support\Facades\FilamentAsset::getScriptSrc('weightscale'))]">
        @foreach ($this->workOrders as $order)
            <div class="p-4 bg-white rounded shadow card">
                <h2 class="text-xl font-bold mb-2">
                    {{ $order->name }} ({{ $order->asset_name }})
                </h2>
                <livewire:work-order-filling-editor :work-order="$order" :wire:key="'wo-'.$order->id" />
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
