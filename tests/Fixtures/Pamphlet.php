<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Admin\Contracts\Sitemapable;
use Atrium\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * A module whose sitemap lists only some of the pages the theme draws:
 * those with a code starting with "a".
 */
final class Pamphlet extends Product implements Sitemapable
{
    public static function sitemapQuery(): Builder
    {
        return self::query()->where('active', true)->where('code', 'like', 'a%');
    }

    public function sitemapUrl(string $language): ?string
    {
        return url('/pamphlets/'.$this->code);
    }
}
