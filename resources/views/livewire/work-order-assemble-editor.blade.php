<div class="p-4 border rounded bg-white shadow space-y-4">

    <h3 class="text-lg font-bold">
        Products {{ $current }}/{{ $total }}
    </h3>

    @if ($currentProduct)
        <div class="space-y-2">
            {{-- <div>
                <label class="block text-sm font-medium">Serial</label>
                <input type="text" wire:model="currentProduct.serial"
                       class="border rounded w-full px-2 py-1 text-sm" />
            </div>

            <div>
                <label class="block text-sm font-medium">Estado</label>
                <input type="text" wire:model="currentProduct.status"
                       class="border rounded w-full px-2 py-1 text-sm" />
            </div> --}}
            @if ($currentProduct)
            <div class="flex">
                <label class="block text-sm font-medium px-2">Done</label>
                <input type="checkbox" wire:model.defer="assambled">
                <!-- ... -->
            </div>
            @else
                <div class="text-gray-500">No hay producto para mostrar.</div>
            @endif
            {{-- <div>
                <label class="block text-sm font-medium">Done</label>
                <input type="checkbox" wire:model="currentProduct.assambled"/>
            </div> --}}
        </div>

        <button wire:click="saveAndNext"
                style="--c-400:var(--primary-400);--c-500:var(--primary-500);--c-600:var(--primary-600);"
                class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-primary fi-color-primary fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 fi-ac-action fi-ac-btn-action">
            {{ $current < $total ? 'Next' : 'Done' }}
        </button>
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
