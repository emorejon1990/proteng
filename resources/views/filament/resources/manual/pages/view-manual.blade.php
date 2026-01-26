@php
    $manual = $this->getRecord();
    $equipment = $manual->equipment;
@endphp

<x-filament-panels::page>
    {{-- Título: Brand - Model --}}
    <h1 class="text-3xl font-bold text-gray-800 mb-6">
        {{ $equipment->brand }} - {{ $equipment->model }}
    </h1>

    {{-- SECCIÓN: Goods y Assets en 2 columnas --}}
    <div style="--cols-default: repeat(1, minmax(0, 1fr)); --cols-lg: repeat(2, minmax(0, 1fr));" class="grid grid-cols-[--cols-default] lg:grid-cols-[--cols-lg] fi-wi gap-6">
        {{-- Columna 1: Goods --}}
        <x-filament::card>
            <x-slot name="heading">
                Goods del Equipo
            </x-slot>
            @if($equipment->goods->count() > 0)
                <ul class="space-y-4">
                    @foreach($equipment->goods as $goods)
                        <li class="flex items-center space-x-4">
                            {{-- Imagen del good --}}
                            @if($goods->picture || $goods->picture_url)
                                <img src="{{ $goods->picture ? asset('storage/' . $goods->picture) : $goods->picture_url }}"
                                     alt="{{ $goods->name }}" style="height: 7rem; width: 7rem;"
                                     class="w-7 h-7 object-cover rounded-md border">
                            @else
                                <div class="w-7 h-7 bg-gray-200 rounded-md flex items-center justify-center text-gray-400">
                                    N/A
                                </div>
                            @endif

                            {{-- Nombre y cantidad --}}
                            <div class="flex-1 text-sm">
                                <p class="font-semibold">{{ $goods->name }}</p>
                                <p class="text-gray-500">Units: {{ $goods->pivot->quantity ?? '—' }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-gray-500 text-sm">No hay goods asociados a este equipo.</p>
            @endif
        </x-filament::card>

        {{-- Columna 2: Assets --}}
        <x-filament::card>
            <x-slot name="heading">
                Assets del Equipo
            </x-slot>
            @if($equipment->asset->count() > 0)
                <ul class="space-y-2 text-sm">
                    @foreach($equipment->asset as $asset)
                        <li class="flex items-center space-x-2">
                            <strong>{{ $asset->name }}</strong>
                            (Units: {{ $asset->pivot->quantity ?? '—' }})
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-gray-500 text-sm">No hay assets asociados a este equipo.</p>
            @endif
        </x-filament::card>
    </div>

    {{-- SECCIÓN: Contenido del Manual --}}
    {{-- SECCIÓN: Contenido del Manual --}}
    <x-filament::card>
        <x-slot name="heading">
            Contenido del Manual
        </x-slot>

        {{-- Contenedor responsive con CSS para imágenes --}}
        <div class="prose max-w-none">
            <style>
                .prose img {
                    width: auto;      /* No se salga del contenedor */
                    max-height: 600px;         /* Mantiene proporción */
                    display: block;
                    margin-top: 0px;
                    margin-bottom: 0px;
                    margin-right: 50px;
                }
                .attachment-gallery {
                    display: flex;
                    flex-direction: column;
                }
                .prose .embed-video {
                    position: relative;
                    padding-bottom: 56.25%;
                    height: 0;
                    overflow: hidden;
                }
                .prose .embed-video iframe {
                    position: absolute;
                    inset: 0;
                    width: 100%;
                    height: 100%;
                }
            </style>

            {!! $manual->descript !!}
        </div>
    </x-filament::card>
</x-filament-panels::page>
