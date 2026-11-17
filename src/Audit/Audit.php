<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Audit;

use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sites;
use Atrium\Seo\Meta\MetaResolver;
use Atrium\Seo\Models\Issue;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Redirects\RedirectMap;
use Atrium\Seo\Sitemap\LanguageSitemap;
use Atrium\Seo\Support\Paths;
use Atrium\Seo\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The SEO check of a site: goes through every public page in every
 * language and writes what is wrong into SEO → Check, one row per problem
 * with a link to the record to fix.
 *
 *  - title_missing, title_duplicate, title_long
 *  - description_missing, description_duplicate
 *  - h1_missing: the page has no name in that language
 *  - image_no_alt, image_heavy: pictures in the text and the record's own
 *  - noindex_in_sitemap: an address of sitemap.xml that is noindex or
 *    closed in robots.txt
 *  - broken_link: a link in the text to an address of the site that is
 *    neither a page, a redirect, a route nor a file
 *  - redirect_chain, redirect_loop
 */
final class Audit
{
    public const CHECKS = [
        'title_missing', 'title_duplicate', 'title_long', 'description_missing', 'description_duplicate',
        'h1_missing', 'image_no_alt', 'image_heavy', 'noindex_in_sitemap', 'broken_link', 'redirect_chain', 'redirect_loop',
    ];

    private const CONTENT = ['preview_text', 'detail_text', 'description', 'text', 'body', 'content', 'answer'];

    private const PICTURES = ['preview_picture', 'detail_picture', 'picture', 'image', 'photo', 'cover'];

    /** @var array<int,array<string,mixed>> */
    private array $issues = [];

    public function __construct(
        private readonly Sites $sites,
        private readonly ContentLanguages $languages,
        private readonly PageCollector $collector,
        private readonly MetaResolver $meta,
        private readonly RedirectMap $redirects,
        private readonly LanguageSitemap $sitemap,
    ) {}

    /**
     * Runs the check of the panel's current site when its results are older
     * than the content or than config('atrium-seo.check.stale_minutes').
     */
    public function runIfStale(): bool
    {
        if (! config('atrium-seo.check.inline', true)) {
            return false;
        }

        $site = $this->sites->current();
        $ran = Cache::get($this->ranKey($site));

        if (is_array($ran) && $ran['version'] === $this->version()
            && $ran['at'] > now()->subMinutes((int) config('atrium-seo.check.stale_minutes', 60))->getTimestamp()) {
            return false;
        }

        $this->run($site);

        return true;
    }

    /**
     * @return int how many problems were found
     */
    public function run(?Site $site = null): int
    {
        $site ??= $this->sites->current();
        $previous = $this->sites->isResolved() ? $this->sites->current() : null;
        $this->sites->setCurrent($site);
        $this->issues = [];

        try {
            $this->check($site);
            $this->save($site);
        } finally {
            $previous === null ? $this->sites->forgetCurrent() : $this->sites->setCurrent($previous);
        }

        Cache::forever($this->ranKey($site), ['version' => $this->version(), 'at' => now()->getTimestamp()]);

        return count($this->issues);
    }

    private function check(Site $site): void
    {
        $default = $this->languages->default();
        $titleMax = (int) config('atrium-seo.check.title_max', 60);
        $known = [];
        $links = [];
        $titles = [];
        $descriptions = [];

        foreach ($this->collector->otherUrls() as $url) {
            $known[$this->key($url, $site)] = true;
        }

        foreach ($this->collector->pages() as ['record' => $record, 'language' => $language, 'url' => $url]) {
            $known[$this->key($url, $site)] = true;
            $label = $this->label($record, $language, $url);
            $page = [$record, $language, $label, $url];
            $meta = $this->meta->forRecord($record, $language, $site, $language === $default);

            if ($meta->title === '') {
                $this->add('title_missing', $page);
            } elseif (mb_strlen($meta->title) > $titleMax) {
                $this->add('title_long', $page, mb_strlen($meta->title).' > '.$titleMax.': '.$meta->title);
            }

            if ($meta->title !== '') {
                $titles[$language][mb_strtolower($meta->title)][] = $page;
            }

            if ($meta->description === '') {
                $this->add('description_missing', $page);
            } else {
                $descriptions[$language][mb_strtolower($meta->description)][] = $page;
            }

            if ((Text::of($record, 'name', $language) ?: Text::of($record, 'title', $language)) === '') {
                $this->add('h1_missing', $page);
            }

            $html = implode(' ', array_map(static fn (string $attribute): string => Text::of($record, $attribute, $language), self::CONTENT));

            $this->images($page, $html, $record);

            foreach ($this->hrefs($html) as $href) {
                $links[] = [$page, $href];
            }
        }

        $this->duplicates('title_duplicate', $titles);
        $this->duplicates('description_duplicate', $descriptions);
        $this->sitemapProblems($site, $known);
        $this->brokenLinks($site, $links, $known);

        foreach ($this->redirects->problems((int) $site->id) as $problem) {
            $this->issues[] = [
                'check' => $problem['loop'] ? 'redirect_loop' : 'redirect_chain',
                'language' => null,
                'label' => $problem['source'],
                'url' => null,
                'detail' => implode(' → ', $problem['hops']),
                'model' => Redirect::class,
                'record_id' => $problem['id'],
            ];
        }
    }

    /**
     * @param  array{0:Model,1:string,2:string,3:string}  $page
     */
    private function images(array $page, string $html, Model $record): void
    {
        $max = (int) config('atrium-seo.check.image_max_kb', 300) * 1024;
        $sources = [];

        if (preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                $src = preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $m) ? $m[1] : '';

                if (! preg_match('/\salt\s*=\s*["\']\s*[^"\'\s][^"\']*["\']/i', $tag)) {
                    $this->add('image_no_alt', $page, $src);
                }

                $sources[] = $src;
            }
        }

        foreach (self::PICTURES as $attribute) {
            $value = $record->getAttribute($attribute);

            if (is_string($value)) {
                $sources[] = $value;
            }
        }

        foreach (array_unique($sources) as $src) {
            $size = $this->fileSize($src);

            if ($size !== null && $size > $max) {
                $this->add('image_heavy', $page, $src.' — '.round($size / 1024).' KB');
            }
        }
    }

    /**
     * The size of a picture stored by the site, null for one elsewhere.
     */
    private function fileSize(string $src): ?int
    {
        $path = (string) parse_url($src, PHP_URL_PATH);

        if ($path === '' || (preg_match('#^(https?:)?//#i', $src) && ! str_contains($src, (string) parse_url(url('/'), PHP_URL_HOST)))) {
            return null;
        }

        $path = rawurldecode($path);
        $public = public_path($path);

        if (is_file($public)) {
            return filesize($public) ?: null;
        }

        $disk = Storage::disk('public');
        $relative = preg_replace('#^/*(?:storage/)?#', '', $path) ?? '';

        return $disk->exists($relative) ? $disk->size($relative) : null;
    }

    /**
     * @return array<int|string,string>
     */
    private function hrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\shref\s*=\s*["\']([^"\']*)["\']/i', $html, $m);

        return array_unique(array_map('trim', $m[1]));
    }

    /**
     * @param  array<string,array<string,array<int,array{0:Model,1:string,2:string,3:string}>>>  $groups
     */
    private function duplicates(string $check, array $groups): void
    {
        foreach ($groups as $pages) {
            foreach ($pages as $same) {
                if (count($same) < 2) {
                    continue;
                }

                $others = array_map(static fn (array $page): string => $page[3], $same);

                foreach ($same as $page) {
                    $this->add($check, $page, implode(', ', array_diff($others, [$page[3]])));
                }
            }
        }
    }

    /**
     * @param  array<string,true>  $known
     */
    private function sitemapProblems(Site $site, array &$known): void
    {
        $disallow = $this->disallowed((string) $site->robots_txt);

        foreach ($this->sitemap->entries() as $language => $entries) {
            foreach ($entries as $entry) {
                $path = $this->key($entry['loc'], $site);
                $known[$path] = true;

                foreach ($disallow as $rule) {
                    if ($this->robotsMatch($rule, $path)) {
                        $this->issues[] = [
                            'check' => 'noindex_in_sitemap', 'language' => $language, 'label' => $entry['loc'],
                            'url' => $entry['loc'], 'detail' => 'robots.txt: Disallow: '.$rule, 'model' => null, 'record_id' => null,
                        ];

                        break;
                    }
                }
            }
        }
    }

    /**
     * @param  array<int,array{0:array{0:Model,1:string,2:string,3:string},1:string}>  $links
     * @param  array<string,true>  $known
     */
    private function brokenLinks(Site $site, array $links, array $known): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && ! str_contains($route->uri(), '{')) {
                $known[strtolower(Paths::normalize($route->uri()))] = true;
            }
        }

        foreach ($links as [$page, $href]) {
            // Nothing to follow: empty, the page itself, or not a page of the site.
            if (preg_match('#^(?:$|[\#?]|(?:mailto|tel|javascript|data|sms):)#i', $href)) {
                continue;
            }

            if (preg_match('#^(?:https?:)?//#i', $href)) {
                $absolute = str_starts_with($href, '//') ? 'https:'.$href : $href;

                if (Paths::relative($absolute, $site->url()) === null) {
                    continue;
                }

                $path = $this->key($absolute, $site);
            } else {
                $path = $this->resolve($href, $page[3], $site);
            }

            $bare = Str::before($path, '?');

            if (($known[$path] ?? false) || ($known[$bare] ?? false)
                || $this->redirects->match($path, (int) $site->id) !== null
                || is_file(public_path(rawurldecode($bare)))) {
                continue;
            }

            $this->add('broken_link', $page, $href);
        }
    }

    /**
     * A link of the text as a site-relative path, lower case.
     */
    private function resolve(string $href, string $pageUrl, Site $site): string
    {
        if (! str_starts_with($href, '/')) {
            $href = Str::beforeLast((string) parse_url($pageUrl, PHP_URL_PATH), '/').'/'.$href;
        }

        return $this->key(url($href), $site);
    }

    /**
     * The robots.txt "Disallow" rules for every robot.
     *
     * @return array<int,string>
     */
    private function disallowed(string $robots): array
    {
        $directives = [];

        foreach (preg_split('/\r\n|\r|\n/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*/', '', $line));

            if (preg_match('/^([a-z-]+)\s*:\s*(.*)/i', $line, $m)) {
                $directives[] = [strtolower($m[1]), $m[2]];
            }
        }

        $rules = [];
        $applies = false;

        foreach ($directives as $i => [$field, $value]) {
            if ($field === 'user-agent') {
                // Robots listed one after another share the rules below them.
                $joins = ($directives[$i - 1][0] ?? null) === 'user-agent';
                $applies = ($joins && $applies) || $value === '*';
            } elseif ($applies && $field === 'disallow' && $value !== '') {
                $rules[] = $value;
            }
        }

        return $rules;
    }

    private function robotsMatch(string $rule, string $path): bool
    {
        $pattern = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote(strtolower($rule), '#')).'#';

        return (bool) preg_match($pattern, $path);
    }

    /**
     * @param  array{0:Model,1:string,2:string,3:string}  $page
     */
    private function add(string $check, array $page, ?string $detail = null): void
    {
        [$record, $language, $label, $url] = $page;

        $this->issues[] = [
            'check' => $check,
            'language' => $language,
            'label' => mb_substr($label, 0, 255),
            'url' => mb_substr($url, 0, 2048),
            'detail' => $detail === null ? null : mb_substr($detail, 0, 2048),
            'model' => $record::class,
            'record_id' => $record->getKey(),
        ];
    }

    private function label(Model $record, string $language, string $url): string
    {
        return Text::of($record, 'name', $language) ?: Text::of($record, 'title', $language) ?: Text::of($record, 'question', $language) ?: $url;
    }

    /**
     * An address as the site-relative path the lookups use.
     */
    private function key(string $url, Site $site): string
    {
        return strtolower(Paths::relative($url, $site->url()) ?? Paths::normalize($url));
    }

    private function save(Site $site): void
    {
        try {
            Issue::query()->withoutGlobalScopes()->where('site_id', $site->id)->delete();
            $now = now();

            foreach (array_chunk($this->issues, 500) as $chunk) {
                Issue::query()->withoutGlobalScopes()->insert(array_map(
                    static fn (array $issue): array => [...$issue, 'site_id' => $site->id, 'created_at' => $now, 'updated_at' => $now],
                    $chunk,
                ));
            }
        } catch (QueryException $e) {
            report($e);
        }
    }

    private function ranKey(Site $site): string
    {
        return 'atrium.seo.check.'.$site->id;
    }

    /**
     * Changes whenever a record, a site, a language or a redirect does.
     */
    private function version(): string
    {
        return Cache::get('atrium.sitemap.version', '0').'.'.Cache::get('atrium.seo.redirects.version', '0');
    }
}
