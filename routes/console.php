<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('marketplaces:sync')->twiceDaily(1, 13);
Schedule::command('listings:queue-reports')->dailyAt('03:30')->withoutOverlapping();
Schedule::command('listings:poll-reports --limit=200')->everyTenMinutes()->withoutOverlapping();
Schedule::command('map:geocode-missing --limit=250')->dailyAt('02:30');
Schedule::command('map:geocode-missing-cities --limit=250 --older-than-days=14')->dailyAt('02:35')->withoutOverlapping();
Schedule::command('orders:sync --days=1 --max-pages=3 --items-limit=20 --address-limit=20')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('orders:sync --days=30 --max-pages=20 --items-limit=300 --address-limit=300')->dailyAt('03:50')->withoutOverlapping();
Schedule::command('inventory:sync-us-fc')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('sqs:process')->everyMinute()->withoutOverlapping();
Schedule::command('ads:queue-reports')->dailyAt('04:40')->withoutOverlapping();
Schedule::call(function () {
    Artisan::call('ads:queue-reports', [
        '--from' => now()->toDateString(),
        '--to' => now()->toDateString(),
    ]);
})->everyFiveMinutes()->name('ads:queue-reports-today-5m')->withoutOverlapping();
Schedule::call(function () {
    Artisan::call('ads:queue-reports', [
        '--from' => now()->subDay()->toDateString(),
        '--to' => now()->subDay()->toDateString(),
    ]);
})->hourly()->name('ads:queue-reports-yesterday-hourly')->withoutOverlapping();
Schedule::call(function () {
    Artisan::call('ads:queue-reports', [
        '--from' => now()->subDays(7)->toDateString(),
        '--to' => now()->subDays(2)->toDateString(),
    ]);
})->everyEightHours()->name('ads:queue-reports-2to7d-8h')->withoutOverlapping();
Schedule::call(function () {
    Artisan::call('ads:queue-reports', [
        '--from' => now()->subDays(30)->toDateString(),
        '--to' => now()->subDays(7)->toDateString(),
    ]);
})->daily()->name('ads:queue-reports-7to30d-daily')->withoutOverlapping();
Schedule::command('ads:poll-reports --limit=200')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('metrics:refresh')->dailyAt('05:00')->withoutOverlapping();
