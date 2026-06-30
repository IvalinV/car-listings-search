<?php

use App\Models\CarListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function listing(array $sourceUrls, ?string $checkedAt): CarListing
{
    return CarListing::factory()->create([
        'source_urls' => $sourceUrls,
        'checked_at' => $checkedAt,
    ]);
}

it('deletes a listing removed from all its sources', function (): void {
    $l = listing(['https://www.mobile.bg/obiava-123-x'], null);

    Http::fake(['*mobile.bg*' => Http::response('', 404)]);

    $this->artisan('listings:clean-up-removed')->assertSuccessful();

    expect(CarListing::find($l->id))->toBeNull();
});

it('prunes only the dead source from a partially-removed multi-source listing', function (): void {
    $l = listing([
        'https://www.mobile.bg/obiava-123-x',
        'https://www.auto.bg/obiava/456/x',
    ], null);

    Http::fake([
        '*mobile.bg*' => Http::response('<html>live</html>', 200),
        'www.auto.bg/*' => Http::response('', 301, ['Location' => 'https://www.auto.bg/obiavi/bmw']),
    ]);

    $this->artisan('listings:clean-up-removed')->assertSuccessful();

    $l->refresh();
    expect($l->source_urls)->toBe(['https://www.mobile.bg/obiava-123-x'])
        ->and($l->checked_at)->not->toBeNull();
});

it('bumps checked_at for an all-alive listing without deleting', function (): void {
    $l = listing(['https://www.mobile.bg/obiava-123-x'], null);

    Http::fake(['*mobile.bg*' => Http::response('<html>live</html>', 200)]);

    $this->artisan('listings:clean-up-removed')->assertSuccessful();

    expect(CarListing::find($l->id))->not->toBeNull()
        ->and($l->fresh()->checked_at)->not->toBeNull();
});

it('skips a listing when a source is transient (5xx) — no delete, checked_at untouched', function (): void {
    $l = listing(['https://www.mobile.bg/obiava-123-x'], null);

    Http::fake(['*mobile.bg*' => Http::response('', 503)]);

    $this->artisan('listings:clean-up-removed')->assertSuccessful();

    expect(CarListing::find($l->id))->not->toBeNull()
        ->and($l->fresh()->checked_at)->toBeNull();
});

it('respects --limit and probes the oldest checked_at first', function (): void {
    $old = listing(['https://www.mobile.bg/obiava-OLD-x'], null);                       // null = oldest
    $fresh = listing(['https://www.mobile.bg/obiava-FRESH-x'], now()->toDateTimeString());

    Http::fake(['*mobile.bg*' => Http::response('', 404)]);

    $this->artisan('listings:clean-up-removed', ['--limit' => 1])->assertSuccessful();

    // Only the oldest (null checked_at) was in the batch and got deleted.
    expect(CarListing::find($old->id))->toBeNull()
        ->and(CarListing::find($fresh->id))->not->toBeNull();
});
