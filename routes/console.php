<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('slotlock:expire-holds')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('slotlock:prune-idempotency')
    ->hourly()
    ->withoutOverlapping();
