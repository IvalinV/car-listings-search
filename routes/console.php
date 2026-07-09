<?php

use App\Console\Commands\CleanUpRemovedListingsCommand;
use App\Console\Commands\ScrapeListingsDailyCommand;
use App\Console\Commands\ScrapeNewListingsCommand;
use App\Console\Commands\SweepAutoBgListingsCommand;
use App\Console\Commands\UpdateCar24ListingsCommand;

Schedule::command(ScrapeListingsDailyCommand::class)->daily();
Schedule::command(ScrapeNewListingsCommand::class)->everyThirtyMinutes();
Schedule::command(CleanUpRemovedListingsCommand::class)->weeklyOn(2, 5);
Schedule::command(SweepAutoBgListingsCommand::class)->dailyAt('04:15')->withoutOverlapping();
Schedule::command(UpdateCar24ListingsCommand::class)->hourly();
