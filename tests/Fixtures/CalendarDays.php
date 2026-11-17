<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Seo\Contracts\SitemapSource;

/**
 * Pages that are not records: a calendar's days.
 */
final class CalendarDays implements SitemapSource
{
    public function urls(string $language): iterable
    {
        yield ['loc' => 'https://example.com/'.$language.'/calendar/2026-01-01', 'lastmod' => '2026-01-01'];
    }
}
