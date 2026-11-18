<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('feedback:recover-exports')->everyTenMinutes()->withoutOverlapping();
Schedule::command('feedback:prune-exports')->hourly()->withoutOverlapping();
