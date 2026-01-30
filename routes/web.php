<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages.car-listings')->name('car-listings');
Route::livewire('/cars/{carListing}', 'pages.car-detail')->name('car-detail');
