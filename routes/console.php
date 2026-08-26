<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('anaya:copy-yesterday-assignments')->dailyAt('00:05');
Schedule::command('anaya:freeze-payroll')->dailyAt('00:10');
Schedule::command('anaya:complete-due-sessions')->everyMinute();
