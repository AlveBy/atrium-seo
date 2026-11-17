<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Admin\Contracts\Sitemapable;
use Atrium\Catalog\Models\Product;
use Atrium\Seo\Contracts\SitemapImages;
use Illuminate\Database\Eloquent\Builder;

/**
 * A module that lists its own pages for the sitemap: active products as
 * leaflets, in English only, with the pictures it chooses.
 */
final class Leaflet extends Product implements Sitemapable, SitemapImages
{
    public static function sitemapQuery(): Builder
    {
        return self::query()->where('active', true);
    }

    public function sitemapUrl(string $language): ?string
    {
        return $language === 'en' ? url('/leaflets/'.$this->code) : null;
    }

    public function sitemapImages(): array
    {
        return ['leaflets/'.$this->code.'.jpg', ''];
    }
}
