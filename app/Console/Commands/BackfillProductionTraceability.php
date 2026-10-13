<?php

namespace App\Console\Commands;

use App\Services\ProductionTraceabilityBackfill;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class BackfillProductionTraceability extends Command
{
    protected $signature = 'production:backfill-traceability
        {--production-type= : ID verificado del tipo Production en la base de datos destino}
        {--apply : Escribir asociaciones y snapshots; por defecto solo diagnostica}';

    protected $description = 'Diagnostica e incorpora trazabilidad existente sin sobrescribir pesos, seriales ni historial.';

    public function handle(ProductionTraceabilityBackfill $backfill): int
    {
        $type = filter_var($this->option('production-type'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($type === false) {
            $this->error('Indica --production-type con el ID real verificado. No se asume un ID del seeder.');

            return self::FAILURE;
        }
        try {
            $report = $backfill->run($type, (bool) $this->option('apply'));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info($this->option('apply') ? 'Incorporación completada.' : 'Diagnóstico: no se modificaron registros.');
        $this->table(['Comprobación', 'Cantidad'], collect($report)->map(fn ($count, $key) => [$key, $count])->values()->all());

        return self::SUCCESS;
    }
}
