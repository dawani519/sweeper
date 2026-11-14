<?php

use Illuminate\Support\Facades\Schedule;

// Scan deposit addresses and advance in-flight sweeps every minute.
Schedule::command('sweeper:tick')->everyMinute()->withoutOverlapping(10);
