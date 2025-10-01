<div class="p-4 border rounded bg-white shadow space-y-4"
    x-data="{
        allChecked: false,
        updateAllChecked() {
            const values = Object.values($wire.checkedProducts);
            this.allChecked = values.length && values.every(v => v === true);
        }
    }"
    x-init="updateAllChecked()"
    x-effect="updateAllChecked()"
    >
    <h3 class="text-lg font-bold">
        {{-- Products {{ $current }}/{{ $total }} --}}
    </h3>

    @if ($products)
        {{-- @dump($this->isAllChecked) --}}
        @foreach ($products as $product)
            <div class="space-y-4">
                <div class="flex items-center gap-x-3 justify-between">
                    <label for="product-distri-{{ $product['id'] }}" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            {{ $product['name'] }} ({{ $product['quantity'] }})({{ $product['dispo'] }})
                        </span>
                        {{-- Mostrar botón WO solo si hay faltante y no existe una WO en BD --}}
                        {{-- @if ($product['quantity'] != $product['dispo'] && empty($product['work_orders'])) --}}
                        @if ($product['quantity'] > $product['dispo'] && ! $product['hasWO'])
                            <x-filament::button
                                wire:click="CreateWO({{ $product['id'] }}, {{ $product['quantity'] - $product['dispo'] }})"
                                color="danger">
                                WO
                            </x-filament::button>
                        @endif
                        {{-- @if ($product['quantity']>$product['dispo'])
                            <x-filament::button wire:click="CreateWO({{ $product['id'] }}, {{ $product['quantity'] - $product['dispo'] }})"  color="danger">WO</x-filament::button>
                        @endif --}}
                        <input id="product-distri-{{ $product['id'] }}" type="checkbox" {{ $product['quantity']>$product['dispo'] ? 'disabled' : '' }} wire:model.live="checkedProducts.{{ $product['id'] }}" class="fi-checkbox-input rounded border-none bg-white shadow-sm ring-1 transition duration-75 checked:ring-0 focus:ring-2 focus:ring-offset-0 disabled:pointer-events-none disabled:bg-gray-50 disabled:text-gray-50 disabled:checked:bg-gray-400 disabled:checked:text-gray-400 dark:bg-white/5 dark:disabled:bg-transparent dark:disabled:checked:bg-gray-600 text-primary-600 ring-gray-950/10 focus:ring-primary-600 checked:focus:ring-primary-500/50 dark:text-primary-500 dark:ring-white/20 dark:checked:bg-primary-500 dark:focus:ring-primary-500 dark:checked:focus:ring-primary-400/50 dark:disabled:ring-white/10" />

                    </label>
                    @error('currentProduct.qualified')
                        <div class="text-red-500 text-sm">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        @endforeach

        <button wire:click="saveAndNext" x-bind:disabled="!allChecked"
                style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none disabled:pointer-events-none disabled:bg-gray-50 disabled:text-gray-50 transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-primary fi-color-primary fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 fi-ac-action fi-ac-btn-action">
            {{-- {{ $current < $total ? 'Next' : 'Done' }} --}}Done
        </button>
    @else
        <div class="text-gray-500">No hay producto para mostrar.</div>
    @endif

    @if (session()->has('done'))
        <div class="text-green-600 mt-4 font-medium fi-color-custom fi-color-success" style="--c-500:var(--success-500);--c-400:var(--success-400);--c-600:var(--success-600);">
            {{ session('done') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="text-red-600 mt-4 font-medium">
            {{ session('error') }}
        </div>
    @endif
</div>
