<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Atrium\Seo\Http\Controllers\RedirectCsvController;
use Atrium\Seo\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

// Takes the place of the core sitemap.xml: an index of files per language.
Route::middleware('atrium.site')->group(function (): void {
    if (config('atrium.sitemap.enabled', true)) {
        Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('atrium.sitemap');
        Route::get('sitemap-{language}-{part}.xml', [SitemapController::class, 'part'])
            ->where(['language' => '[a-z]{2,3}(?:[-_][A-Za-z0-9]+)?', 'part' => '[0-9]+'])
            ->name('atrium.seo.sitemap.part');
    }

    Route::get('{key}.txt', [SitemapController::class, 'key'])->where('key', '[a-f0-9]{32}')->name('atrium.seo.indexnow.key');
});

// The CSV file of a redirect import, into the private disk.
Route::prefix(config('atrium.prefix', 'admin'))
    ->middleware([...config('atrium.middleware', ['web']), 'atrium.site', 'admin.auth'])
    ->post('seo/redirect-csv', RedirectCsvController::class)
    ->name('atrium.seo.redirect-csv');
