<div
    x-data="{ isOpen: @entangle('isOpen') }"
    x-init="() => {
        let activeScanner = null;
        let starting = false;
        const stopScanner = async () => {
            const scanner = activeScanner;
            activeScanner = null;
            if (scanner) await scanner.stop().catch(console.error);
        };
        $watch('isOpen', value => {
            if (!value) {
                stopScanner();
                return;
            }
            if (value) {
                setTimeout(() => {
                    if (!isOpen || starting || activeScanner) return;
                    starting = true;
                    const html5QrCode = new Html5Qrcode('reader');
                    let reading = false;
                    const qrConfig = {
                        fps: 10,
                        qrbox: 250,
                        formatsToSupport: [
                            {{-- Html5QrcodeSupportedFormats.QR_CODE, --}}
                            Html5QrcodeSupportedFormats.CODE_39
                        ]
                    };

                    html5QrCode.start(
                        { facingMode: 'environment' },
                        qrConfig,
                        (decodedText, decodedResult) => {
                            if (reading) return;
                            reading = true;
                            stopScanner().then(() => $wire.handleScannedCode(decodedText)).catch(console.error);
                        },
                        (errorMessage) => {
                            // Ignorar errores menores
                        }
                    ).then(() => {
                        activeScanner = html5QrCode;
                        if (!isOpen) stopScanner();
                    }).catch(console.error).finally(() => { starting = false; });
                }, 300); // Espera a que el DOM esté listo
            }
        })
    }"
>
    <template x-if="isOpen">
        <div class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
            <div class="bg-white p-4 rounded shadow w-[340px]">
                <p class="mb-2 text-center text-gray-800 font-medium">
                    Escanea un código QR o de barras
                </p>

                <div id="reader" style="width:300px; margin: auto;"></div>

                <button wire:click="close" class="mt-4 block mx-auto px-4 py-2 bg-red-500 rounded hover:bg-red-600">
                    Cerrar
                </button>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
@endpush

