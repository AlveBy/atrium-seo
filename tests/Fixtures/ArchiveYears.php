<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Seo\Contracts\SitemapSource;

/**
 * A page that is one address only with its query: the archive of a year.
 */
final class ArchiveYears implements SitemapSource
{
    public function urls(string $language): iterable
    {
        yield ['loc' => 'https://example.com/'.$language.'/archive?year=2026', 'lastmod' => '2026-01-01'];
    }
}
