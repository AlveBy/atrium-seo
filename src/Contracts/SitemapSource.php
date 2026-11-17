<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Contracts;

/**
 * Addresses for sitemap.xml that are not records of a module: a calendar's
 * months, a tag's page. Records already come from the modules' Sitemapable
 * and from the theme. Register the class in config('atrium-seo.sitemap_sources').
 */
interface SitemapSource
{
    /**
     * The addresses of the current site in a language.
     *
     * @return iterable<array{loc:string,lastmod?:string|null,images?:array<int,string>}>
     */
    public function urls(string $language): iterable;
}
