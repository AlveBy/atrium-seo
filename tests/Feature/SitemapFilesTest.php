<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use ArrayObject;
use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Models\Language;
use Atrium\Admin\Support\Sitemap;
use Atrium\Admin\Support\Sites;
use Atrium\Seo\Http\Controllers\SitemapController;
use Atrium\Seo\IndexNow\IndexNow;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Robots;
use Atrium\Seo\Sitemap\LanguageSitemap;
use Atrium\Seo\Sitemap\RecordEntries;
use Atrium\Seo\Tests\Fixtures\Brochure;
use Atrium\Seo\Tests\Fixtures\ConfiguredSource;
use Atrium\Seo\Tests\Fixtures\Leaflet;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The sitemap's files and index to the letter: what is listed, in which
 * file, with which dates, alternates and pictures.
 */
final class SitemapFilesTest extends TestCase
{
    private const HEAD = '<?xml version="1.0" encoding="UTF-8"?>'."\n";

    protected function setUp(): void
    {
        parent::setUp();

        config(['atrium.sitemap.paths' => [], 'atrium-seo.sitemap_sources' => [ConfiguredSource::class]]);
    }

    /**
     * @param  array<string,array<int,array<string,mixed>>>  $pages  by language
     */
    private function pages(array $pages): void
    {
        config(['seo-test.pages' => $pages]);
    }

    private function sitemap(): LanguageSitemap
    {
        return app(LanguageSitemap::class);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listing(int $count, string $language = 'en'): array
    {
        return array_map(static fn (int $number): array => ['loc' => 'https://example.com/'.$language.'/page-'.$number, 'lastmod' => null], range(1, $count));
    }

    public function test_a_file_lists_each_page_with_its_date_alternates_and_pictures(): void
    {
        $this->pages(['en' => [
            [
                'loc' => 'https://example.com/a',
                'lastmod' => '2026-06-02T12:30:00+00:00',
                'alternates' => ['en' => 'https://example.com/a', 'de' => 'https://example.com/de/a'],
                'images' => ['https://example.com/a.jpg', 5, 'https://example.com/b.jpg'],
            ],
            ['loc' => 'https://example.com/b'],
        ]]);

        $this->assertSame(
            self::HEAD
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n"
            .' <url>'."\n"
            .'  <loc>https://example.com/a</loc>'."\n"
            .'  <lastmod>2026-06-02T12:30:00+00:00</lastmod>'."\n"
            .'  <xhtml:link rel="alternate" hreflang="en" href="https://example.com/a"/>'."\n"
            .'  <xhtml:link rel="alternate" hreflang="de" href="https://example.com/de/a"/>'."\n"
            .'  <image:image>'."\n"
            .'   <image:loc>https://example.com/a.jpg</image:loc>'."\n"
            .'  </image:image>'."\n"
            .'  <image:image>'."\n"
            .'   <image:loc>https://example.com/b.jpg</image:loc>'."\n"
            .'  </image:image>'."\n"
            .' </url>'."\n"
            .' <url>'."\n"
            .'  <loc>https://example.com/b</loc>'."\n"
            .' </url>'."\n"
            .'</urlset>'."\n",
            $this->sitemap()->part('en', 1),
        );
    }

    public function test_a_page_lists_at_most_a_thousand_pictures(): void
    {
        $images = array_map(static fn (int $number): string => 'https://example.com/'.$number.'.jpg', range(1, 1001));
        $this->pages(['en' => [['loc' => 'https://example.com/a', 'images' => $images]]]);

        $xml = (string) $this->sitemap()->part('en', 1);

        $this->assertSame(1000, substr_count($xml, '<image:loc>'));
        $this->assertStringContainsString('<image:loc>https://example.com/1000.jpg</image:loc>', $xml);
        $this->assertStringNotContainsString('https://example.com/1001.jpg', $xml);
    }

    public function test_what_a_source_gives_is_kept_as_lists_and_empty_languages_are_dropped(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $this->pages(['en' => [
            ['loc' => 'https://example.com/a', 'lastmod' => '2026-06-02', 'alternates' => new ArrayObject(['en' => 'https://example.com/a']), 'images' => [5, 'a.jpg', null, 'b.jpg']],
            ['loc' => 'https://example.com/b'],
        ]]);

        $this->assertSame(['en' => [
            ['loc' => 'https://example.com/a', 'lastmod' => '2026-06-02', 'alternates' => ['en' => 'https://example.com/a'], 'images' => ['a.jpg', 'b.jpg']],
            ['loc' => 'https://example.com/b', 'lastmod' => null, 'alternates' => [], 'images' => []],
        ]], $this->sitemap()->entries());
    }

    public function test_an_address_is_listed_once_under_the_first_language_that_has_it(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $this->pages([
            'en' => [['loc' => 'https://example.com/shared'], ['loc' => 'https://example.com/en-only']],
            'de' => [['loc' => 'https://example.com/shared'], ['loc' => 'https://example.com/de-only']],
        ]);

        $this->assertSame(
            ['en' => ['https://example.com/shared', 'https://example.com/en-only'], 'de' => ['https://example.com/de-only']],
            array_map(static fn (array $entries): array => array_column($entries, 'loc'), $this->sitemap()->entries()),
        );
    }

    public function test_a_page_of_a_language_the_site_does_not_have_goes_to_the_default_one(): void
    {
        app(Sitemap::class)->extend(
            static fn (): array => [new Brochure],
            static fn (Brochure $record, string $language): ?string => $language === 'en' ? 'https://example.com/en-page' : null,
        );
        Hook::filter('atrium.sitemap.entries', static fn (array $entries): array => array_map(static fn (array $entry): array => [...$entry, 'language' => 'xx'], $entries));

        $this->assertSame(['en' => ['https://example.com/en-page']], array_map(static fn (array $entries): array => array_column($entries, 'loc'), $this->sitemap()->entries()));
    }

    public function test_a_language_code_that_is_a_number_does_not_break_the_listing(): void
    {
        Language::query()->create(['code' => '12', 'name' => 'Twelve']);
        app(Sitemap::class)->extend(
            static fn (): array => [new Brochure],
            static fn (Brochure $record, string $language): ?string => $language === '12' ? 'https://example.com/twelve' : null,
        );
        Hook::filter('atrium.sitemap.entries', static fn (array $entries): array => array_map(static fn (array $entry): array => [...$entry, 'language' => 12], $entries));

        $entries = $this->sitemap()->entries();

        $this->assertSame([12], array_keys($entries));
        $this->assertSame(['https://example.com/twelve'], array_column($entries[12], 'loc'));
    }

    public function test_the_sources_may_be_named_by_one_class_and_a_bad_one_does_not_stop_the_rest(): void
    {
        $this->pages(['en' => [['loc' => 'https://example.com/a']]]);

        config(['atrium-seo.sitemap_sources' => ConfiguredSource::class]);
        $this->assertSame(['https://example.com/a'], array_column($this->sitemap()->entries()['en'], 'loc'));

        Cache::flush();
        config(['atrium-seo.sitemap_sources' => ['App\\Missing', \stdClass::class, 5, ConfiguredSource::class]]);
        $this->assertSame(['https://example.com/a'], array_column($this->sitemap()->entries()['en'], 'loc'));
    }

    public function test_the_entries_are_kept_under_a_key_of_the_site_the_host_and_the_version(): void
    {
        $this->pages(['en' => [['loc' => 'https://example.com/a']]]);
        $key = 'atrium.seo.sitemap.'.app(Sites::class)->current()->getKey().'.'.md5(url('/'));

        $this->sitemap()->entries();
        $this->assertTrue(Cache::has($key.'.0'));

        Cache::forever('atrium.sitemap.version', 'v7');
        $this->sitemap()->entries();
        $this->assertTrue(Cache::has($key.'.v7'));
    }

    public function test_the_index_lists_a_file_per_chunk_with_the_newest_date_of_its_pages(): void
    {
        config(['atrium.sitemap.per_file' => 2]);
        $this->pages(['en' => [
            ['loc' => 'https://example.com/1', 'lastmod' => '2026-03-01T00:30:00+02:00'],
            ['loc' => 'https://example.com/2', 'lastmod' => '2026-03-01'],
            ['loc' => 'https://example.com/3', 'lastmod' => null],
            ['loc' => 'https://example.com/4', 'lastmod' => '2026-01-15 08:00:00'],
            ['loc' => 'https://example.com/5'],
        ]]);

        $this->assertSame(
            self::HEAD
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .' <sitemap>'."\n"
            .'  <loc>'.$this->root().'/sitemap-en-1.xml</loc>'."\n"
            .'  <lastmod>2026-03-01T00:00:00+00:00</lastmod>'."\n"
            .' </sitemap>'."\n"
            .' <sitemap>'."\n"
            .'  <loc>'.$this->root().'/sitemap-en-2.xml</loc>'."\n"
            .'  <lastmod>2026-01-15T08:00:00+00:00</lastmod>'."\n"
            .' </sitemap>'."\n"
            .' <sitemap>'."\n"
            .'  <loc>'.$this->root().'/sitemap-en-3.xml</loc>'."\n"
            .' </sitemap>'."\n"
            .'</sitemapindex>'."\n",
            $this->sitemap()->index(),
        );
    }

    public function test_a_language_with_exactly_the_limit_of_addresses_has_one_file_and_one_more_gets_a_second(): void
    {
        config(['atrium.sitemap.per_file' => 3]);

        $this->pages(['en' => $this->listing(3)]);
        $this->assertSame(1, substr_count($this->sitemap()->index(), '<sitemap>'));
        $this->assertSame(3, substr_count((string) $this->sitemap()->part('en', 1), '<url>'));
        $this->assertNull($this->sitemap()->part('en', 2));

        Cache::flush();
        $this->pages(['en' => $this->listing(4)]);
        $this->assertSame(2, substr_count($this->sitemap()->index(), '<sitemap>'));
        $this->assertSame(3, substr_count((string) $this->sitemap()->part('en', 1), '<url>'));
        $this->assertSame(1, substr_count((string) $this->sitemap()->part('en', 2), '<url>'));
        $this->assertStringContainsString('page-4', (string) $this->sitemap()->part('en', 2));
        $this->assertNull($this->sitemap()->part('en', 3));
    }

    public function test_a_limit_below_one_means_one_address_a_file(): void
    {
        $this->pages(['en' => $this->listing(3)]);

        foreach ([0, -4, 1] as $limit) {
            Cache::flush();
            config(['atrium.sitemap.per_file' => $limit]);

            $this->assertSame(3, substr_count($this->sitemap()->index(), '<sitemap>'), (string) $limit);
            $this->assertSame(1, substr_count((string) $this->sitemap()->part('en', 3), '<url>'), (string) $limit);
            $this->assertNull($this->sitemap()->part('en', 4), (string) $limit);
        }
    }

    public function test_a_file_number_below_one_or_of_a_language_without_pages_is_no_file(): void
    {
        $this->pages(['en' => $this->listing(2)]);

        $this->assertNull($this->sitemap()->part('en', 0));
        $this->assertNull($this->sitemap()->part('en', -1));
        $this->assertNull($this->sitemap()->part('de', 1));
        $this->assertNotNull($this->sitemap()->part('en', 1));
    }

    public function test_pages_closed_to_search_engines_and_moved_ones_are_left_out_whatever_their_case(): void
    {
        $root = 'http://localhost';
        $this->pages(['en' => [
            ['loc' => $root.'/shop/hidden'],
            ['loc' => $root.'/SHOP/Other'],
            ['loc' => $root.'/old/page'],
            ['loc' => $root.'/OLD/Other'],
            ['loc' => $root.'/old/wild/card'],
            ['loc' => $root.'/open'],
            ['loc' => $root.'/indexed'],
            ['loc' => $root.'/inactive'],
        ]]);
        PageMeta::query()->create(['path' => '/Shop/Hidden', 'robots' => Robots::NOINDEX]);
        PageMeta::query()->create(['path' => '/shop/other', 'robots' => 'NOINDEX, follow']);
        PageMeta::query()->create(['path' => '/indexed', 'robots' => Robots::INDEX]);
        PageMeta::query()->create(['path' => '/open']);
        Redirect::query()->create(['source' => '/Old/Page', 'target' => '/new']);
        Redirect::query()->create(['source' => '/old/other', 'target' => '/new']);
        Redirect::query()->create(['source' => '/old/wild/*', 'target' => '/new/*']);
        Redirect::query()->create(['source' => '/inactive', 'target' => '/new', 'active' => false]);

        $this->assertSame(
            [$root.'/old/wild/card', $root.'/open', $root.'/indexed', $root.'/inactive'],
            array_column($this->sitemap()->entries()['en'], 'loc'),
        );
    }

    public function test_without_the_tables_of_pages_and_redirects_nothing_is_left_out_and_nothing_breaks(): void
    {
        PageMeta::query()->create(['path' => '/hidden', 'robots' => Robots::NOINDEX]);
        $this->pages(['en' => [['loc' => 'http://localhost/hidden']]]);
        $tables = [(new PageMeta)->getTable(), (new Redirect)->getTable()];
        $missing = true;
        DB::beforeExecuting(static function (string $query, array $bindings, $connection) use ($tables, &$missing): void {
            foreach ($missing ? $tables : [] as $table) {
                if (str_contains($query, '"'.$table.'"') || str_contains($query, '`'.$table.'`')) {
                    throw new QueryException($connection->getName(), $query, $bindings, new \PDOException('no such table: '.$table));
                }
            }
        });

        $this->assertSame(['http://localhost/hidden'], array_column($this->sitemap()->entries()['en'], 'loc'));
        // The tables are back for rolling the migrations back.
        $missing = false;
    }

    public function test_a_record_closed_to_search_engines_takes_no_pictures_and_no_place_in_the_sitemap(): void
    {
        $brochure = Brochure::query()->create(['name' => 'Brochure', 'code' => 'b']);
        $entries = [['loc' => 'https://example.com/b', 'lastmod' => null, 'alternates' => []]];

        $this->assertSame([
            ['loc' => 'https://example.com/b', 'lastmod' => null, 'alternates' => [], 'images' => [$this->root().'/storage/brochures/b.jpg', $this->root().'/storage/brochures/b-back.jpg']],
        ], (new RecordEntries)($entries, $brochure));

        $brochure->seoMeta()->create(['robots' => Robots::NOINDEX]);
        $this->assertSame([], (new RecordEntries)($entries, $brochure->fresh()));
    }

    public function test_a_record_that_keeps_no_seo_tab_still_gets_its_pictures(): void
    {
        $entries = [['loc' => 'https://example.com/b', 'lastmod' => null, 'alternates' => []]];

        $this->assertSame([['loc' => 'https://example.com/b', 'lastmod' => null, 'alternates' => [], 'images' => []]], (new RecordEntries)($entries, new Language));
    }

    public function test_a_record_with_a_noindex_in_any_case_is_kept_out_and_one_without_a_decision_is_not(): void
    {
        $leaflet = Leaflet::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'active' => true, 'preview_picture' => 'catalog/lamp.jpg']);
        $entries = [['loc' => 'https://example.com/b', 'lastmod' => null, 'alternates' => []]];

        $this->assertSame([$this->root().'/storage/leaflets/lamp.jpg'], (new RecordEntries)($entries, $leaflet)[0]['images']);

        $leaflet->seoMeta()->create(['robots' => null]);
        $this->assertCount(1, (new RecordEntries)($entries, $leaflet->fresh()));

        $leaflet->seoMeta()->update(['robots' => 'No-Index, nofollow NOINDEX']);
        $this->assertSame([], (new RecordEntries)($entries, $leaflet->fresh()));
    }

    public function test_the_controller_answers_with_xml_and_hides_what_is_not_there(): void
    {
        $this->pages(['en' => $this->listing(1)]);
        $controller = app(SitemapController::class);

        $index = $controller->index($this->sitemap());
        $this->assertSame(200, $index->getStatusCode());
        $this->assertSame('application/xml; charset=UTF-8', $index->headers->get('Content-Type'));
        $this->assertSame($this->sitemap()->index(), $index->getContent());

        $part = $controller->part($this->sitemap(), 'en', 1);
        $this->assertSame('application/xml; charset=UTF-8', $part->headers->get('Content-Type'));
        $this->assertSame($this->sitemap()->part('en', 1), $part->getContent());

        $this->expectException(NotFoundHttpException::class);
        $controller->part($this->sitemap(), 'en', 2);
    }

    public function test_the_controller_serves_the_key_to_whoever_knows_it_and_nobody_else(): void
    {
        $controller = app(SitemapController::class);
        $key = app(IndexNow::class)->key();

        $response = $controller->key(app(IndexNow::class), $key);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($key, $response->getContent());
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));

        $this->expectException(NotFoundHttpException::class);
        $controller->key(app(IndexNow::class), strrev($key));
    }
}
