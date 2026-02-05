<?php

use App\Console\Commands\CleanUpRemovedListingsCommand;
use App\Console\Commands\ScrapeListingsDailyCommand;

Schedule::command(ScrapeListingsDailyCommand::class)->daily();
Schedule::command(CleanUpRemovedListingsCommand::class)->weeklyOn(2, 0);
