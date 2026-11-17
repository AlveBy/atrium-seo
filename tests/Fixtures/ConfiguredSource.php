<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Seo\Contracts\SitemapSource;

/**
 * A module's sitemap source whose pages the test writes in config
 * ("seo-test.pages.{language}"), entries exactly as the module gives them.
 */
final class ConfiguredSource implements SitemapSource
{
    public function urls(string $language): iterable
    {
        yield from (array) config('seo-test.pages.'.$language, []);
    }
}
