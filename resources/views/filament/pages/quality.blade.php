<x-filament-panels::page>
    <div >
        <button id="butConnect" type="button" style="--c-400:var(--info-400);--c-500:var(--info-500);--c-600:var(--info-600);" class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg fi-color-custom fi-btn-color-info fi-color-info fi-size-md fi-btn-size-md gap-1.5 px-3 py-2 text-sm inline-grid shadow-sm bg-custom-600 text-white hover:bg-custom-500 focus-visible:ring-custom-500/50 dark:bg-custom-500 dark:hover:bg-custom-400 dark:focus-visible:ring-custom-400/50 fi-ac-action fi-ac-btn-action">
            Connect
        </button>
    </div>
    <div>
        <livewire:qr-scanner-modal wire:key="qr-scanner" />

        <x-filament::button wire:click="dispatch('open-qr-modal')">
            Escanear código
        </x-filament::button>
    </div>

    @push('scripts')
        <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" type="text/javascript"></script>
        <script>
            document.addEventListener('livewire:load', function () {
                Livewire.on('start-scanner', () => {
                    const html5QrCode = new Html5Qrcode("reader");
                    const qrConfig = { fps: 10, qrbox: 250, formatsToSupport:[
                        Html5QrcodeSupportedFormats.QR_CODE,
                        Html5QrcodeSupportedFormats.CODE_39
                    ] };

                    html5QrCode.start(
                        { facingMode: "environment" },
                        qrConfig,
                        (decodedText, decodedResult) => {
                            Livewire.find('qr-scanner').scannedCode = decodedText;
                            html5QrCode.stop().catch(err => console.error("Error deteniendo escáner:", err));
                        },
                        (errorMessage) => {
                            // Puedes ocultar errores menores si quieres
                        }
                    );
                });
            });
        </script>
    @endpush

    @if ($scannedCode)
        <div class="mt-4 p-4 bg-blue-100 rounded">
            Código escaneado: <strong>{{ $scannedCode }}</strong>
        </div>
    @endif
    <div class="flex flex-row"
        x-data="{}"
        x-load-js="[@js(\Filament\Support\Facades\FilamentAsset::getScriptSrc('weightscale'))]">
        @foreach ($this->workOrders as $order)
            <div class="p-4 bg-white rounded shadow card">
                <h2 class="text-xl font-bold mb-2">
                    {{ $order->name }} ({{ $order->asset_name }})
                </h2>
                <livewire:work-order-quality-editor :work-order="$order" :wire:key="'wo-'.$order->id" />
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
