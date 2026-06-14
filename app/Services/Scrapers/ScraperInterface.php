<?php

namespace App\Services\Scrapers;

interface ScraperInterface
{
    public function scrape();

    public function isListingRemoved(string $url): bool;
}
