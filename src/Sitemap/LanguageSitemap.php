<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Sitemap;

use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sitemap;
use Atrium\Admin\Support\Sites;
use Atrium\Seo\Contracts\SitemapSource;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Robots;
use Atrium\Seo\Support\Paths;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use XMLWriter;

/**
 * sitemap.xml of the current site as an index of files per language
 * (sitemap-en-1.xml, sitemap-de-1.xml...), 50 000 addresses each, with the
 * date of the newest change of every file and the pictures of every page.
 *
 * The addresses are the core sitemap's (the modules' Sitemapable and the
 * theme), the module's SitemapSource classes added, without the pages kept
 * out of search engines (noindex in SEO → Pages or on the record's SEO
 * tab) and without addresses that redirect elsewhere.
 */
final class LanguageSitemap
{
    /** The core sitemap's version: whatever flushes it flushes this. */
    private const VERSION_KEY = 'atrium.sitemap.version';

    public function __construct(
        private readonly Sitemap $sitemap,
        private readonly Sites $sites,
        private readonly ContentLanguages $languages,
    ) {}

    /**
     * @return array<string,array<int,array{loc:string,lastmod:string|null,alternates:array<string,string>,images:array<int,string>}>> by language code
     */
    public function entries(): array
    {
        $key = 'atrium.seo.sitemap.'.$this->sites->current()->getKey().'.'.md5(url('/')).'.'.Cache::get(self::VERSION_KEY, '0');

        return Cache::remember($key, now()->addDay(), fn (): array => $this->build());
    }

    public function index(): string
    {
        $xml = self::writer();
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($this->entries() as $language => $entries) {
            foreach (array_chunk($entries, $this->perFile()) as $number => $chunk) {
                $xml->startElement('sitemap');
                $xml->writeElement('loc', url('sitemap-'.$language.'-'.($number + 1).'.xml'));
                $dates = array_filter(array_column($chunk, 'lastmod'));

                if ($dates !== []) {
                    $xml->writeElement('lastmod', max(array_map(static fn (string $date): string => gmdate(DateTimeInterface::ATOM, (int) strtotime($date)), $dates)));
                }

                $xml->endElement();
            }
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * One file of a language, null when there is no such file.
     */
    public function part(string $language, int $number): ?string
    {
        $entries = $this->entries()[$language] ?? [];
        $chunk = $number < 1 ? [] : array_slice($entries, ($number - 1) * $this->perFile(), $this->perFile());

        if ($chunk === []) {
            return null;
        }

        $xml = self::writer();
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        $xml->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');

        foreach ($chunk as $entry) {
            $xml->startElement('url');
            $xml->writeElement('loc', $entry['loc']);

            if ($entry['lastmod'] !== null) {
                $xml->writeElement('lastmod', $entry['lastmod']);
            }

            foreach ($entry['alternates'] as $hreflang => $href) {
                $xml->startElement('xhtml:link');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', (string) $hreflang);
                $xml->writeAttribute('href', $href);
                $xml->endElement();
            }

            foreach (array_slice($entry['images'], 0, 1000) as $image) {
                $xml->startElement('image:image');
                $xml->writeElement('image:loc', $image);
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();

        return $xml->outputMemory();
    }

    /**
     * @return array<string,array<int,array{loc:string,lastmod:string|null,alternates:array<string,string>,images:array<int,string>}>>
     */
    private function build(): array
    {
        $default = $this->languages->default();
        $root = $this->sites->current()->url();
        $excluded = $this->excluded();
        $byLanguage = array_fill_keys($this->languages->codes(), []);
        $seen = [];

        $add = static function (string $language, array $entry) use (&$byLanguage, &$seen, $excluded, $root): void {
            $path = Paths::relative($entry['loc'], $root);

            if (isset($seen[$entry['loc']]) || ($path !== null && isset($excluded[strtolower($path)]))) {
                return;
            }

            $seen[$entry['loc']] = $language;
            $byLanguage[$language][] = [
                'loc' => $entry['loc'],
                'lastmod' => $entry['lastmod'] ?? null,
                'alternates' => (array) ($entry['alternates'] ?? []),
                'images' => array_values(array_filter((array) ($entry['images'] ?? []), 'is_string')),
            ];
        };

        foreach ($this->sitemap->entries() as $entry) {
            $language = (string) ($entry['language'] ?? $default);
            $add(isset($byLanguage[$language]) ? $language : $default, $entry);
        }

        foreach ((array) config('atrium-seo.sitemap_sources', []) as $class) {
            $source = is_string($class) && class_exists($class) ? app($class) : null;

            if (! $source instanceof SitemapSource) {
                continue;
            }

            foreach (array_keys($byLanguage) as $language) {
                foreach ($source->urls((string) $language) as $entry) {
                    $add((string) $language, $entry);
                }
            }
        }

        return array_filter($byLanguage);
    }

    /**
     * Paths kept out of search engines in SEO → Pages and paths that redirect.
     *
     * @return array<string,int>
     */
    private function excluded(): array
    {
        $site = $this->sites->current()->id;
        $paths = [];

        try {
            foreach (PageMeta::query()->withoutGlobalScopes()->where('site_id', $site)->whereNotNull('robots')->get(['path', 'robots']) as $page) {
                if (Robots::isNoindex($page->robots)) {
                    $paths[] = strtolower($page->path);
                }
            }

            foreach (Redirect::query()->withoutGlobalScopes()->where('site_id', $site)->where('active', true)->pluck('source') as $source) {
                if (! str_ends_with((string) $source, '*')) {
                    $paths[] = strtolower((string) $source);
                }
            }
        } catch (QueryException) {
            // The module's tables are not there yet: what was read stays out.
        }

        return array_flip($paths);
    }

    private function perFile(): int
    {
        return max(1, (int) config('atrium.sitemap.per_file', Sitemap::PER_FILE));
    }

    private static function writer(): XMLWriter
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        return $xml;
    }
}
