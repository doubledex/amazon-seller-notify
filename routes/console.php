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
Schedule::command('orders:sync --days=7 --max-pages=5 --items-limit=50 --address-limit=50')->hourly();
Schedule::command('orders:sync --days=30 --max-pages=20 --items-limit=300 --address-limit=300')->dailyAt('03:50')->withoutOverlapping();
Schedule::command('sqs:process')->everyMinute()->withoutOverlapping();
Schedule::command('ads:queue-reports')->dailyAt('04:40')->withoutOverlapping();
Schedule::command('ads:poll-reports --limit=200')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('metrics:refresh')->dailyAt('05:00')->withoutOverlapping();
