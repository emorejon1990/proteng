@php
    $asset = $this->getRecord();
@endphp
<x-filament-panels::page>
    {{-- Título: Brand - Model --}}
    <h1 class="text-3xl font-bold text-gray-800 mb-6">
        {{ $asset->name }}
    </h1>
    <x-filament::card>
        {{-- Contenedor responsive con CSS para imágenes --}}
        <div class="prose max-w-none">
            Weight - {!! $asset->weight !!}</br>
            Weight Tolerance - {!! $asset->weight_tolerance !!}</br>
            {!! $asset->description !!}
        </div>
    </x-filament::card>

</x-filament-panels::page>
