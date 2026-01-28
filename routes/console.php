<?php

use App\Console\Commands\ScrapeListingsDailyCommand;

Schedule::command(ScrapeListingsDailyCommand::class)->daily();
