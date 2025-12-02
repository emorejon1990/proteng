<div class="p-4 border rounded bg-white shadow space-y-4">
    <h3 class="text-lg font-bold">
        Products {{ $current }}/{{ $total }}
    </h3>

    @if ($currentProduct)
        {{-- @dump($currentProduct) --}}
        @php
            $hasWeightError = $errors->has('currentProductData.weight');
        @endphp
        <div class="grid gap-y-3">
            <div class="flex items-center gap-x-3 justify-between ">
                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3" for="product-weight">
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                        Weight<sup class="text-danger-600 dark:text-danger-400 font-medium">*</sup>
                    </span>
                </label>
            </div>
            <div class="grid auto-cols-fr gap-y-2">
                <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 {{ $hasWeightError ? 'fi-invalid ring-danger-600' : 'ring-gray-950/10' }} [&amp;:not(:has(.fi-ac-action:focus))]:focus-within:ring-2 dark:ring-white/20 [&amp;:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-600 dark:[&amp;:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-500 fi-fo-text-input overflow-hidden ">
                    <div class="fi-input-wrp-input min-w-0 flex-1">
                        <input id="product-weight" type="number" step="0.01" wire:model.debounce.500ms="currentProductData.weight"
                                wire:change="$emit('pesoManualCambiado', $event.target.value)"
                                class="fi-input block w-full border-none py-1.5 text-base text-gray-950 transition duration-75 placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.400)] dark:text-white dark:placeholder:text-gray-500 dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] dark:disabled:placeholder:[-webkit-text-fill-color:theme(colors.gray.500)] sm:text-sm sm:leading-6 bg-white/0 ps-3 pe-3" disabled required="required">

                    </div>
                </div>
            </div>
            @error('currentProductData.weight')
                <div class="fi-fo-field-wrp-error-message text-sm text-danger-600 dark:text-danger-400">{{ $message }}</div>
            @enderror
        </div>
        <div class="space-y-4">
            {{-- <div>
                <label for="product-weight" class="block text-sm font-medium">Weight</label>
                <input id="product-weight" type="number" step="0.01" wire:model.debounce.500ms="currentProductData.weight"
                        wire:change="$emit('pesoManualCambiado', $event.target.value)"
                        class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white
                                {{ $hasWeightError ? 'fi-invalid ring-danger-600' : 'border-gray-300' }}" disabled />
                @error('currentProductData.weight')
                    <div class="fi-fo-field-wrp-error-message text-sm text-danger-600 dark:text-danger-400">{{ $message }}</div>
                @enderror
            </div> --}}

            <div class="flex items-center gap-x-3 justify-between">
                <label for="product-filled" class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                    <input id="product-filled" type="checkbox" wire:model.defer="filled" class="fi-checkbox-input rounded border-none bg-white shadow-sm ring-1 transition duration-75 checked:ring-0 focus:ring-2 focus:ring-offset-0 disabled:pointer-events-none disabled:bg-gray-50 disabled:text-gray-50 disabled:checked:bg-gray-400 disabled:checked:text-gray-400 dark:bg-white/5 dark:disabled:bg-transparent dark:disabled:checked:bg-gray-600 text-primary-600 ring-gray-950/10 focus:ring-primary-600 checked:focus:ring-primary-500/50 dark:text-primary-500 dark:ring-white/20 dark:checked:bg-primary-500 dark:focus:ring-primary-500 dark:checked:focus:ring-primary-400/50 dark:disabled:ring-white/10" />
                    <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Done</span>
                </label>
                @error('currentProduct.filled')
                    <div class="text-red-500 text-sm">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <button wire:click="saveAndNext"
                style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-primary fi-color-primary fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 fi-ac-action fi-ac-btn-action">
            {{ $current < $total ? 'Next' : 'Done' }}
        </button>
    @else
        <div class="text-gray-500">No hay producto para mostrar.</div>
    @endif

    @if (session()->has('done'))
        <div class="text-green-600 mt-4 font-medium">
            {{ session('done') }}
        </div>
    @endif
</div>
<script>
    document.addEventListener('reload-page', () => {
        location.reload();
    });
</script>
