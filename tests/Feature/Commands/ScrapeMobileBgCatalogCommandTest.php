<?php

use App\Jobs\SweepMobileBgSegmentJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function fakeMobileBrowseSitemap(): void
{
    $xml = '<?xml version="1.0"?><urlset>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/ac</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/ac/drugi</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw</loc></url>'
        .'<url><loc>https://www.mobile.bg/obiavi/avtomobili-dzhipove/bmw/116</loc></url>'
        .'</urlset>';
    Http::fake(['www.mobile.bg/sitemap/*' => Http::response(gzencode($xml))]);
}

it('dispatches one segment job per make with its model slugs', function (): void {
    Bus::fake();
    fakeMobileBrowseSitemap();

    $this->artisan('scrape:mobilebg-catalog')->assertSuccessful();

    Bus::assertDispatchedTimes(SweepMobileBgSegmentJob::class, 2);
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'ac' && $job->childSlugs === ['drugi']);
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'bmw' && $job->childSlugs === ['116']);
});

it('limits the sweep to a single make with --make', function (): void {
    Bus::fake();
    fakeMobileBrowseSitemap();

    $this->artisan('scrape:mobilebg-catalog', ['--make' => 'bmw'])->assertSuccessful();

    Bus::assertDispatchedTimes(SweepMobileBgSegmentJob::class, 1);
    Bus::assertDispatched(SweepMobileBgSegmentJob::class, fn ($job) => $job->slug === 'bmw');
});

it('fails and dispatches nothing when the browse-sitemap is unavailable', function (): void {
    Bus::fake();
    Http::fake(['www.mobile.bg/sitemap/*' => Http::response('', 500)]);

    $this->artisan('scrape:mobilebg-catalog')->assertFailed();

    Bus::assertNothingDispatched();
});
