<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sites;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Seo\Support\Paths;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * hreflang links of a page across every site and language it exists in,
 * with x-default.
 *
 * The theme knows the page's address in each language of the current site;
 * a record written in one language only has one. Another site shows the
 * same page when the page is shared: the home page and a module's list in
 * all of its languages, a record of a module that is not per site in the
 * languages it is translated into. Its address there is the same path under
 * that site's root and language prefix (example.de/news/x,
 * example.com/fr/news/x). A record of a per-site module lives on its own
 * site only.
 *
 * Each hreflang value appears once: when two sites share a language, the
 * current site keeps it, then the default site, then the others in their
 * order. x-default is the default site's default language, else the first
 * link, which is the current site's.
 */
final class Hreflang
{
    public function __construct(
        private readonly Sites $sites,
        private readonly ContentLanguages $languages,
    ) {}

    /**
     * @return array<string,string> hreflang => address, x-default last
     */
    public function for(SeoPage $page): array
    {
        $current = $this->sites->current();
        $paths = $this->paths($page->alternates, $current);
        $links = [];
        $defaults = [];
        // The home page and a module's list are on every site in every
        // language; a record only where the theme gives it an address.
        $fallback = $page->record === null;

        foreach ($this->sitesFor($page, $current) as $site) {
            [$codes, $default] = $site->is($current)
                ? [$this->languages->codes(), $this->languages->default()]
                : $this->siteLanguages($site);

            foreach ($codes as $code) {
                $path = $paths[$code] ?? ($site->is($current) || ! $fallback ? null : $paths[$this->languages->default()] ?? null);

                if ($path === null) {
                    continue;
                }

                $url = $site->is($current) ? $page->alternates[$code] : $this->url($site, $code, $default, $path);
                $links[self::hreflang($code)] ??= $url;

                if ($code === $default) {
                    $defaults[$site->id] = $url;
                }
            }
        }

        if (count($links) < 2) {
            return [];
        }

        $links['x-default'] = $defaults[$this->sites->default()->id] ?? reset($links);

        return $links;
    }

    public static function hreflang(string $code): string
    {
        return (new Language(['code' => strtolower(str_replace('_', '-', $code))]))->hreflang();
    }

    /**
     * The site-relative path of the page in each language of this site,
     * without the language prefix.
     *
     * @param  array<string,string>  $alternates
     * @return array<string,string>
     */
    private function paths(array $alternates, Site $current): array
    {
        $root = $current->url();
        $default = $this->languages->default();
        $paths = [];

        foreach ($alternates as $code => $url) {
            $path = Paths::relative((string) $url, $root);

            if ($path === null) {
                continue;
            }

            $prefix = '/'.$code;

            if ($code !== $default && ($path === $prefix || str_starts_with($path, $prefix.'/') || str_starts_with($path, $prefix.'?'))) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }

            $paths[$code] = $path;
        }

        return $paths;
    }

    /**
     * The current site first, then the others as the panel lists them: the
     * default one first.
     *
     * @return Collection<int,Site>
     */
    private function sitesFor(SeoPage $page, Site $current): Collection
    {
        $perSite = $page->record !== null && in_array(BelongsToSite::class, class_uses_recursive($page->record), true);

        if ($perSite) {
            return new Collection([$current]);
        }

        return $this->sites->all()
            ->filter(fn (Site $site): bool => $site->active || $site->is($current))
            ->sortByDesc(fn (Site $site): bool => $site->is($current))
            ->values();
    }

    /**
     * @return array{0:array<int,string>,1:string} the site's languages and its default one
     */
    private function siteLanguages(Site $site): array
    {
        $active = $this->activeLanguages();
        $codes = $site->languages ? array_values(array_intersect($active, (array) $site->languages)) : $active;

        if ($codes === []) {
            return [[], ''];
        }

        $default = in_array($site->default_language, $codes, true) ? $site->default_language : $codes[0];

        return [$codes, $default];
    }

    /**
     * @return array<int,string> the panel's active languages, the default one first
     */
    private function activeLanguages(): array
    {
        try {
            return Language::query()->where('active', true)->orderByDesc('is_default')->orderBy('sort')->orderBy('id')->pluck('code')->all();
        } catch (QueryException) {
            return [];
        }
    }

    private function url(Site $site, string $code, string $default, string $path): string
    {
        $prefix = $code === $default ? '' : $code;

        return $site->url($prefix.'/'.ltrim($path, '/'));
    }
}
