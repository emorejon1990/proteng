<?php

namespace App\Livewire;

use Livewire\Component;

class QrScannerModal extends Component
{
    public bool $isOpen = false;

    public string|null $scannedCode = null;

    protected $listeners = ['open-qr-modal' => 'open'];

    public function open()
    {
        $this->isOpen = true;

        // Lanza evento JS para inicializar el escáner
        $this->dispatch('start-scanner')->self();
    }

    public function close()
    {
        $this->isOpen = false;
    }

    public function updatedScannedCode($value)
    {
        // Aquí notificamos al padre con el código escaneado
        $this->dispatch('code-scanned', code: $value);
        $this->close();
    }

    public function handleScannedCode($code)
    {
        // Emitir correctamente el evento al componente padre
        $this->dispatch('code-scanned', code: $code);
        $this->close();
    }

    public function render()
    {
        return view('livewire.qr-scanner-modal');
    }
}
