<?php

namespace App\Console\Commands;

use App\Models\Products;
use Carbon\Carbon;
use App\Models\WorkOrder;
use Illuminate\Console\Command;

class MoveWorkOrdersAfterFiveDays extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workorders:move-after-5days';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move work orders with more than 5 days to the Quality Area';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Entra al schedule at " .Carbon::now());
        $originWc_id = 3; // origin Waiting Area
        $targetWc_id = 4; // target Quality
        // Asumiendo que tienes un campo como `wc_id` y `wc_changed_at` en la tabla
        $workOrders = WorkOrder::where('wc_id', $originWc_id)
            ->where('wc_changed_at', '<=', Carbon::now()->subDays(5))
            ->get();
            $this->info("Se crearon {$workOrders->count()} at " .Carbon::now());
        foreach ($workOrders as $workOrder) {
            $workOrder->wc_id = $targetWc_id;
            $workOrder->wc_changed_at = now(); // registra el nuevo cambio
            $this->info("Se modifico el wo {$workOrder->id} at " .Carbon::now());
            foreach ($workOrder->products as $product) {
                $product->location_id = 3;
                $this->info("Se modifico el product {$product->id} at " .Carbon::now());
                $product->save();
            }
            $workOrder->save();

            $this->info("WorkOrder #{$workOrder->id} move to work center {$targetWc_id} at " .Carbon::now());
        }
    }
}
