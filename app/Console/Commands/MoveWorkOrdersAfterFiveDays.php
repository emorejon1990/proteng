<?php

namespace App\Console\Commands;

use App\Services\ProductionWaitingService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class MoveWorkOrdersAfterFiveDays extends Command
{
    protected $signature = 'workorders:move-after-5days';

    protected $description = 'Move eligible production and repair orders to Quality after five calendar days';

    public function handle(ProductionWaitingService $waiting): int
    {
        try {
            $orders = $waiting->orders()->pluck('id');
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }
        foreach ($orders as $orderId) {
            try {
                if ($waiting->advance($orderId)) {
                    $this->info("WorkOrder #{$orderId}: espera completada; pasó a Quality.");
                }
            } catch (ValidationException $exception) {
                $this->warn("WorkOrder #{$orderId}: ".collect($exception->errors())->flatten()->first());
            }
        }

        return self::SUCCESS;
    }
}
