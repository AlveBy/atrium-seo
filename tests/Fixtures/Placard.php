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
 * A record with pages in some languages only: none in the first one, one
 * address in the second and the third, a blank one in the fourth.
 */
final class Placard extends Product implements Sitemapable
{
    public static function sitemapQuery(): Builder
    {
        return self::query();
    }

    public function sitemapUrl(string $language): ?string
    {
        return match ($language) {
            'en' => null,
            'fr', 'de' => url('/placards/'.$this->code),
            default => '',
        };
    }
}
