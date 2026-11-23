<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);
use Atrium\Seo\Schema\BreadcrumbSchema;
use Atrium\Seo\Schema\FaqSchema;
use Atrium\Seo\Schema\LocalBusinessSchema;
use Atrium\Seo\Schema\NewsArticleSchema;
use Atrium\Seo\Schema\OrganizationSchema;
use Atrium\Seo\Schema\ProductSchema;
use Atrium\Seo\Schema\ReviewSchema;

return [

    /*
    |--------------------------------------------------------------------------
    | Default templates by kind of page
    |--------------------------------------------------------------------------
    |
    | A module without a template of its own in the panel (SEO → Templates)
    | takes the default of its kind, translated in lang/{language}/templates.php.
    | Models not listed here are "default". Modules add their own kind:
    |
    |     config()->set('atrium-seo.kinds.'.Tour::class, 'tour');
    |
    | and ship lang/{language}/templates.php... or name an existing kind.
    |
    */

    'kinds' => [
        'Atrium\\Catalog\\Models\\Product' => 'product',
        'Atrium\\Catalog\\Models\\CatalogSection' => 'section',
        'Atrium\\News\\Models\\News' => 'article',
        'Atrium\\Stores\\Models\\Store' => 'place',
        'Atrium\\Faq\\Models\\Category' => 'section',
    ],

    /*
    |--------------------------------------------------------------------------
    | schema.org providers
    |--------------------------------------------------------------------------
    |
    | Classes implementing Atrium\Seo\Contracts\SchemaProvider. Each module
    | adds its own from its service provider, once (a plain push would add it
    | again after config:cache):
    |
    |     \Atrium\Admin\Support\ConfigList::add('atrium-seo.schema', EventSchema::class);
    |
    | Adding a class name loads nothing: it is resolved only when this module
    | is installed and a page is drawn.
    |
    */

    'schema' => [
        OrganizationSchema::class,
        BreadcrumbSchema::class,
        ProductSchema::class,
        NewsArticleSchema::class,
        FaqSchema::class,
        LocalBusinessSchema::class,
        ReviewSchema::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | More addresses for sitemap.xml
    |--------------------------------------------------------------------------
    |
    | Classes implementing Atrium\Seo\Contracts\SitemapSource, for pages that
    | are not records: a calendar's days, a search by tag. Records come from
    | the modules' Sitemapable and from the theme.
    |
    */

    'sitemap_sources' => [],

    /*
    |--------------------------------------------------------------------------
    | IndexNow
    |--------------------------------------------------------------------------
    |
    | Bing and Yandex are told about a page as soon as it is published or
    | changed in the panel. Only in production; switched off per site in
    | the module's settings.
    |
    */

    'indexnow' => [
        'enabled' => env('ATRIUM_SEO_INDEXNOW', true),
        'endpoints' => [
            'https://www.bing.com/indexnow',
            'https://yandex.com/indexnow',
        ],
        // The same address is not sent again within this many minutes.
        'throttle_minutes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | The 404 log
    |--------------------------------------------------------------------------
    */

    'not_found' => [
        'enabled' => env('ATRIUM_SEO_404_LOG', true),
        // Paths not logged; * matches anything.
        'ignore' => ['*.map', '/.well-known/*', '/wp-*', '/xmlrpc.php'],
        // The oldest addresses go when there are more.
        'keep' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | The SEO check
    |--------------------------------------------------------------------------
    */

    'check' => [
        // Opening the list runs the check when the content changed or the
        // last run is older than this. Off for big sites: schedule
        // `php artisan atrium:seo:check` instead.
        'inline' => env('ATRIUM_SEO_CHECK_INLINE', true),
        'stale_minutes' => 60,
        'title_max' => 60,
        'image_max_kb' => 300,
    ],

];
