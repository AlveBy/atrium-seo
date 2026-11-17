<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Http\Controllers;

use Atrium\Seo\IndexNow\IndexNow;
use Atrium\Seo\Sitemap\LanguageSitemap;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * sitemap.xml, its files per language and the IndexNow key file.
 */
final class SitemapController extends Controller
{
    public function index(LanguageSitemap $sitemap): Response
    {
        return $this->xml($sitemap->index());
    }

    public function part(LanguageSitemap $sitemap, string $language, int $part): Response
    {
        $xml = $sitemap->part($language, $part);

        abort_if($xml === null, 404);

        return $this->xml($xml);
    }

    public function key(IndexNow $indexNow, string $key): Response
    {
        abort_unless(hash_equals($indexNow->key(), $key), 404);

        return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
