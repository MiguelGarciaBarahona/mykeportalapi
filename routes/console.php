<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 | Tareas programadas. En el servidor se necesita una sola entrada de cron:
 |   * * * * * cd /ruta/al/api && php artisan schedule:run >> /dev/null 2>&1
 | En local: php artisan schedule:work
 */
Schedule::command('leads:notify-stale')->hourly()->withoutOverlapping();
Schedule::command('finance:notify-month-end')->dailyAt('17:00')->timezone((string) config('mykesystems.admin_api.timezone', 'America/Tegucigalpa'));
