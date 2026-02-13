<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('marketplaces:sync')->twiceDaily(1, 13);
Schedule::command('map:geocode-missing --limit=250')->dailyAt('02:30');
Schedule::command('orders:sync --days=7 --max-pages=5 --items-limit=50 --address-limit=50')->hourly();
Schedule::command('sqs:process')->everyMinute()->withoutOverlapping();
