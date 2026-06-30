<?php

namespace App\Services\Scrapers;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

interface ScraperInterface
{
    public function scrape();

    public function isListingRemoved(string $url): bool;

    public function poolRemovalProbe(PendingRequest $request, string $url): PromiseInterface;

    public function isRemovedFromResponse(Response $response, string $url): bool;
}
