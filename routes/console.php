<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// If something stays pending too long, tell the people responsible; if it
// stays longer, tell their seniors. Once per level, never twice.
Schedule::command('erp:escalate')->hourly()->withoutOverlapping();
