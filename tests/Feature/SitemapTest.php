<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Robots;
use Atrium\Seo\Tests\Fixtures\CalendarDays;
use Atrium\Seo\Tests\Fixtures\Leaflet;
use Atrium\Seo\Tests\TestCase;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * sitemap.xml is an index of one file per language and 50 000 addresses,
 * with the date of the latest change and the pictures of each page.
 */
final class SitemapTest extends TestCase
{
    private const SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const IMAGE = 'http://www.google.com/schemas/sitemap-image/1.1';

    private const XHTML = 'http://www.w3.org/1999/xhtml';

    public function test_the_index_lists_a_file_per_language(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Carbon::setTestNow('2026-05-01 10:00:00');
        News::query()->create(['name' => ['en' => 'We moved', 'de' => 'Umgezogen'], 'code' => ['en' => 'we-moved', 'de' => 'umgezogen']]);
        Carbon::setTestNow('2026-06-02 12:30:00');
        News::query()->create(['name' => ['en' => 'Sale'], 'code' => ['en' => 'sale']]);
        Carbon::setTestNow();

        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xpath = $this->xml((string) $response->getContent(), 'sitemapindex');

        $this->assertSame([$this->root().'/sitemap-en-1.xml', $this->root().'/sitemap-de-1.xml'], $this->texts($xpath, '//s:sitemap/s:loc'));
        $this->assertSame(['2026-06-02T12:30:00+00:00', '2026-05-01T10:00:00+00:00'], $this->texts($xpath, '//s:sitemap/s:lastmod'));
    }

    public function test_a_language_file_holds_the_pages_in_that_language_with_their_translations(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        News::query()->create(['name' => ['en' => 'We moved', 'de' => 'Umgezogen'], 'code' => ['en' => 'we-moved', 'de' => 'umgezogen']]);

        $en = $this->xml((string) $this->get('/sitemap-en-1.xml')->assertOk()->getContent(), 'urlset');
        $de = $this->xml((string) $this->get('/sitemap-de-1.xml')->assertOk()->getContent(), 'urlset');

        $this->assertContains($this->root().'/news/we-moved', $this->texts($en, '//s:url/s:loc'));
        $this->assertNotContains($this->root().'/de/news/umgezogen', $this->texts($en, '//s:url/s:loc'));
        $this->assertContains($this->root().'/de/news/umgezogen', $this->texts($de, '//s:url/s:loc'));

        $links = $de->query('//s:url[s:loc="'.$this->root().'/de/news/umgezogen"]/x:link');
        $alternates = [];

        foreach ($links as $link) {
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }

        $this->assertSame($this->root().'/news/we-moved', $alternates['en'] ?? null);
        $this->assertSame($this->root().'/de/news/umgezogen', $alternates['de'] ?? null);
    }

    public function test_a_long_list_is_split_into_files(): void
    {
        config(['atrium.sitemap.per_file' => 2]);

        foreach (['a', 'b', 'c', 'd'] as $code) {
            Product::query()->create(['name' => strtoupper($code), 'code' => $code]);
        }

        // The home page and four products: three files of two.
        $index = $this->xml((string) $this->get('/sitemap.xml')->getContent(), 'sitemapindex');
        $this->assertSame([
            $this->root().'/sitemap-en-1.xml',
            $this->root().'/sitemap-en-2.xml',
            $this->root().'/sitemap-en-3.xml',
        ], $this->texts($index, '//s:sitemap/s:loc'));

        $this->assertCount(2, $this->texts($this->xml((string) $this->get('/sitemap-en-2.xml')->assertOk()->getContent(), 'urlset'), '//s:url'));
        $this->assertCount(1, $this->texts($this->xml((string) $this->get('/sitemap-en-3.xml')->assertOk()->getContent(), 'urlset'), '//s:url'));
        $this->get('/sitemap-en-4.xml')->assertNotFound();
        $this->get('/sitemap-en-0.xml')->assertNotFound();
    }

    public function test_a_language_without_pages_has_no_file(): void
    {
        $this->get('/sitemap-de-1.xml')->assertNotFound();
        $this->get('/sitemap-../../etc-1.xml')->assertNotFound();
    }

    public function test_pages_carry_their_pictures(): void
    {
        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'preview_picture' => 'catalog/lamp.jpg', 'detail_picture' => 'catalog/lamp-big.jpg']);

        $xpath = $this->xml((string) $this->get('/sitemap-en-1.xml')->getContent(), 'urlset');

        $this->assertSame(
            [$this->root().'/storage/catalog/lamp-big.jpg', $this->root().'/storage/catalog/lamp.jpg'],
            $this->texts($xpath, '//s:url[s:loc="'.$this->root().'/catalog/lamp"]/i:image/i:loc'),
        );
    }

    #[DefineEnvironment('withLeaflets')]
    public function test_a_module_chooses_the_pictures_of_its_pages(): void
    {
        Leaflet::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'active' => true, 'preview_picture' => 'catalog/lamp.jpg']);

        $xpath = $this->xml((string) $this->get('/sitemap-en-1.xml')->getContent(), 'urlset');

        $this->assertSame([$this->root().'/storage/leaflets/lamp.jpg'], $this->texts($xpath, '//s:url[s:loc="'.$this->root().'/leaflets/lamp"]/i:image/i:loc'));
    }

    public function test_pages_closed_to_search_engines_and_old_addresses_are_left_out(): void
    {
        Product::query()->create(['name' => 'Open', 'code' => 'open']);
        Product::query()->create(['name' => 'Closed', 'code' => 'closed'])->seoMeta()->create(['robots' => Robots::NOINDEX]);
        Product::query()->create(['name' => 'Indexed', 'code' => 'indexed'])->seoMeta()->create(['robots' => Robots::INDEX]);
        Product::query()->create(['name' => 'By address', 'code' => 'by-address']);
        Product::query()->create(['name' => 'Moved', 'code' => 'moved']);
        Product::query()->create(['name' => 'Inactive', 'code' => 'inactive', 'active' => false]);
        PageMeta::query()->create(['path' => '/catalog/by-address', 'robots' => Robots::NONE]);
        Redirect::query()->create(['source' => '/catalog/moved', 'target' => '/catalog/open']);
        Redirect::query()->create(['source' => '/catalog/*', 'target' => '/shop/*', 'active' => false]);

        $locs = $this->texts($this->xml((string) $this->get('/sitemap-en-1.xml')->getContent(), 'urlset'), '//s:url/s:loc');

        $this->assertContains($this->root().'/catalog/open', $locs);
        $this->assertContains($this->root().'/catalog/indexed', $locs);
        $this->assertNotContains($this->root().'/catalog/closed', $locs);
        $this->assertNotContains($this->root().'/catalog/by-address', $locs);
        $this->assertNotContains($this->root().'/catalog/moved', $locs);
        $this->assertNotContains($this->root().'/catalog/inactive', $locs);
    }

    public function test_a_save_rebuilds_the_files(): void
    {
        Product::query()->create(['name' => 'First', 'code' => 'first']);
        $this->assertStringNotContainsString('/catalog/second', (string) $this->get('/sitemap-en-1.xml')->getContent());

        $product = Product::query()->create(['name' => 'Second', 'code' => 'second']);
        $this->assertStringContainsString('/catalog/second', (string) $this->get('/sitemap-en-1.xml')->getContent());

        $product->seoMeta()->create(['robots' => Robots::NOINDEX]);
        $product->touch();
        $this->assertStringNotContainsString('/catalog/second', (string) $this->get('/sitemap-en-1.xml')->getContent());

        PageMeta::query()->create(['path' => '/catalog/first', 'robots' => Robots::NOINDEX]);
        $this->assertStringNotContainsString('/catalog/first', (string) $this->get('/sitemap-en-1.xml')->getContent());
    }

    public function test_modules_add_addresses_that_are_not_records(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        config(['atrium-seo.sitemap_sources' => [CalendarDays::class, 'App\\Missing', \stdClass::class]]);

        $en = $this->texts($this->xml((string) $this->get('/sitemap-en-1.xml')->getContent(), 'urlset'), '//s:url/s:loc');
        $de = $this->texts($this->xml((string) $this->get('/sitemap-de-1.xml')->getContent(), 'urlset'), '//s:url/s:loc');

        $this->assertContains('https://example.com/en/calendar/2026-01-01', $en);
        $this->assertContains('https://example.com/de/calendar/2026-01-01', $de);
    }

    public function test_each_site_has_its_own_sitemap(): void
    {
        $site = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        News::query()->create(['name' => ['en' => 'Here'], 'code' => ['en' => 'here']]);
        News::query()->create(['site_id' => $site->id, 'name' => ['en' => 'There'], 'code' => ['en' => 'there']]);

        $index = $this->texts($this->xml((string) $this->get('/site2/sitemap.xml')->assertOk()->getContent(), 'sitemapindex'), '//s:sitemap/s:loc');
        $this->assertSame([$this->root().'/site2/sitemap-en-1.xml'], $index);

        $locs = $this->texts($this->xml((string) $this->get('/site2/sitemap-en-1.xml')->assertOk()->getContent(), 'urlset'), '//s:url/s:loc');
        $this->assertContains($this->root().'/site2/news/there', $locs);
        $this->assertNotContains($this->root().'/site2/news/here', $locs);
        $this->assertNotContains($this->root().'/news/here', $locs);
    }

    public function test_robots_txt_points_to_the_index(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.$this->root().'/sitemap.xml');
    }

    /**
     * Parses a sitemap the way search engines do: well-formed XML in the
     * sitemap namespace, absolute addresses, W3C dates.
     */
    private function xml(string $content, string $root): DOMXPath
    {
        $document = new DOMDocument;
        $this->assertTrue(@$document->loadXML($content), 'Not well-formed XML');
        $this->assertSame($root, $document->documentElement?->localName);
        $this->assertSame(self::SITEMAP, $document->documentElement->namespaceURI);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $content);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', self::SITEMAP);
        $xpath->registerNamespace('i', self::IMAGE);
        $xpath->registerNamespace('x', self::XHTML);

        foreach ($xpath->query('//s:loc | //i:loc') as $loc) {
            $this->assertMatchesRegularExpression('#^https?://[^\s<>]+$#', $loc->textContent);
            $this->assertLessThanOrEqual(2048, strlen($loc->textContent));
        }

        foreach ($xpath->query('//s:lastmod') as $date) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z))?$/', $date->textContent);
        }

        $children = $root === 'sitemapindex' ? 'sitemap' : 'url';
        $this->assertSame(0, $xpath->query('/s:'.$root.'/*[local-name() != "'.$children.'"]')->length, 'Only <'.$children.'> inside <'.$root.'>');
        $this->assertLessThanOrEqual(50000, $xpath->query('/s:'.$root.'/s:'.$children)->length);

        return $xpath;
    }

    /**
     * @return list<string>
     */
    private function texts(DOMXPath $xpath, string $query): array
    {
        $texts = [];

        foreach ($xpath->query($query) as $node) {
            $texts[] = $node->textContent;
        }

        return $texts;
    }

    protected function withLeaflets(Application $app): void
    {
        $app['config']->set('atrium.resources', [Leaflet::class]);
    }
}
