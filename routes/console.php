<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wellness:send-activity-reminders')->dailyAt('08:00')->timezone('America/Bogota')->withoutOverlapping()->onOneServer();
Schedule::command('model:prune')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-failed --hours=336')->dailyAt('02:40')->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-batches --hours=168')->dailyAt('02:50')->withoutOverlapping()->onOneServer();

// Automatically register all commands in app/Console/Commands
$this->load(__DIR__.'/../app/Console/Commands');
