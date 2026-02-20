<?php

use App\Console\Commands\CleanUpRemovedListingsCommand;
use App\Console\Commands\ScrapeListingsDailyCommand;
use App\Console\Commands\ScrapeNewListingsCommand;

Schedule::command(ScrapeListingsDailyCommand::class)->daily();
Schedule::command(ScrapeNewListingsCommand::class)->everyThirtyMinutes();
Schedule::command(CleanUpRemovedListingsCommand::class)->weeklyOn(2, 0);
