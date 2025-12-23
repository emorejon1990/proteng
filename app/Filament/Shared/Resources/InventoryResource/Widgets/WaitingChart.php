<?php

namespace App\Filament\Shared\Resources\InventoryResource\Widgets;

use App\Models\Products;
use Filament\Widgets\ChartWidget;

class WaitingChart extends ChartWidget
{
    protected static ?string $heading = 'Waiting Area';

    protected static ?int $sort = 3;

    protected function getData(): array
    {
        $locationId = 2; // ← Cambia esto según tu necesidad o hazlo dinámico

        $data = Products::query()
            ->whereHas('asset', fn($query) => $query->where('location_id', $locationId))
            ->selectRaw('asset_id, COUNT(*) as total')
            ->groupBy('asset_id')
            ->with('asset')
            ->get();

        $labels = $data->map(fn ($item) => $item->asset?->name ?? 'Sin Asset');
        $values = $data->pluck('total');

        // 🎨 Colores fijos (puedes agregar más si tenés más assets)
        $fixedColors = [
            'rgba(255, 99, 132, 0.7)',   // rojo rosado
            'rgba(54, 162, 235, 0.7)',   // azul
            'rgba(255, 206, 86, 0.7)',   // amarillo
            'rgba(75, 192, 192, 0.7)',   // turquesa
            'rgba(153, 102, 255, 0.7)',  // violeta
            'rgba(255, 159, 64, 0.7)',   // naranja
            'rgba(100, 181, 246, 0.7)',  // celeste claro
            'rgba(129, 199, 132, 0.7)',  // verde claro
        ];

        // Asignar colores en orden (con repetición si hay más labels que colores)
        $colors = $labels->map(
            fn ($label, $index) => $fixedColors[$index % count($fixedColors)]
        );

        return [
            'datasets' => [
                [
                    'label' => 'Total',
                    'data' => $values,
                    'backgroundColor' => $colors,
                    'borderRadius' => 5,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
