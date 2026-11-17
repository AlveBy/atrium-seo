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
use RuntimeException;

/**
 * A page that behaves oddly, by its code: the home page itself ("home"), a
 * name ("long-name") and an address ("long-url") longer than any column,
 * and one that fails when asked for its address ("boom").
 */
final class Oddity extends Product implements Sitemapable
{
    public static function sitemapQuery(): Builder
    {
        return self::query()->where('active', true);
    }

    public function sitemapUrl(string $language): ?string
    {
        return match ($this->code) {
            'boom' => throw new RuntimeException('No address for this page'),
            'home' => url('/'),
            'long-url' => url('/oddities/'.str_repeat('é', 3000)),
            default => url('/oddities/'.$this->code),
        };
    }

    public function getNameAttribute(?string $value): ?string
    {
        return $this->code === 'long-name' ? str_repeat('я', 300) : $value;
    }
}
