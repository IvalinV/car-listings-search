<?php

use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('app.env', 'production');
    Config::set('app.url', 'https://autosearch.bg');
});

it('redirects alternate hosts and insecure requests to the canonical domain', function (): void {
    $this->get('http://www.autosearch.bg/robots.txt?source=legacy')
        ->assertMovedPermanently()
        ->assertLocation('https://autosearch.bg/robots.txt?source=legacy');
});

it('serves the canonical domain without redirecting', function (): void {
    $this->get('https://autosearch.bg/robots.txt')
        ->assertSuccessful()
        ->assertSee('Sitemap: https://autosearch.bg/sitemap.xml', false);
});

it('recognizes the canonical scheme behind a TLS terminating proxy', function (): void {
    $this->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('http://autosearch.bg/robots.txt')
        ->assertSuccessful();
});
