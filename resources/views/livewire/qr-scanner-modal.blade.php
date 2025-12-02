<div
    x-data="{ isOpen: @entangle('isOpen') }"
    x-init="() => {
        $watch('isOpen', value => {
            if (value) {
                setTimeout(() => {
                    const html5QrCode = new Html5Qrcode('reader');
                    const qrConfig = {
                        fps: 10,
                        qrbox: 250,
                        formatsToSupport: [
                            Html5QrcodeSupportedFormats.QR_CODE,
                            Html5QrcodeSupportedFormats.CODE_39
                        ]
                    };

                    html5QrCode.start(
                        { facingMode: 'environment' },
                        qrConfig,
                        (decodedText, decodedResult) => {
                            Livewire.dispatch('code-scanned', { code: decodedText });
                            html5QrCode.stop().catch(console.error);
                        },
                        (errorMessage) => {
                            // Ignorar errores menores
                        }
                    );
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



