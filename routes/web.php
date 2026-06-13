<?php

use App\Http\Controllers\SeoController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages.car-listings')->name('car-listings');
Route::livewire('/cars/{carListing}', 'pages.car-detail')->name('car-detail');

Route::get('/sitemap.xml', [SeoController::class, 'sitemapIndex'])->name('sitemap');
Route::get('/sitemap-{page}.xml', [SeoController::class, 'sitemapPage'])->whereNumber('page')->name('sitemap.page');
Route::get('/robots.txt', [SeoController::class, 'robots'])->name('robots');
