<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\MoveWorkOrdersAfterFiveDays;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(MoveWorkOrdersAfterFiveDays::class)->everyMinute()->appendOutputTo(storage_path('logs/schedul.log'));//->daily();
//* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
