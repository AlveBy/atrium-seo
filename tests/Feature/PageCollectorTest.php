<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Audit\Audit;
use Atrium\Seo\Audit\PageCollector;
use Atrium\Seo\Models\Issue;
use Atrium\Seo\Tests\Fixtures\Ghost;
use Atrium\Seo\Tests\Fixtures\Pamphlet;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The pages the SEO check goes through: the modules' own list for the
 * sitemap first, then what the theme draws and the list did not name.
 */
final class PageCollectorTest extends TestCase
{
    /**
     * @return list<array{0:string,1:string,2:string}> record code, language, address
     */
    private function pamphlets(): array
    {
        $pages = [];

        foreach (app(PageCollector::class)->pages() as $page) {
            if ($page['record'] instanceof Pamphlet) {
                $pages[] = [$page['record']->code, $page['language'], $page['url']];
            }
        }

        return $pages;
    }

    private function twoPamphlets(): void
    {
        Pamphlet::query()->create(['name' => 'Alpha', 'code' => 'alpha', 'active' => true]);
        Pamphlet::query()->create(['name' => 'Beta', 'code' => 'beta', 'active' => true]);
    }

    #[DefineEnvironment('withPamphlets')]
    public function test_a_page_the_sitemap_lists_is_not_taken_again_from_the_theme_and_the_rest_are(): void
    {
        $this->twoPamphlets();

        $this->assertSame([
            ['alpha', 'en', $this->root().'/pamphlets/alpha'],
            ['beta', 'en', $this->root().'/pamphlets/beta'],
        ], $this->pamphlets());
    }

    #[DefineEnvironment('withGhost')]
    public function test_a_module_of_the_theme_that_cannot_be_read_is_skipped_and_the_others_still_are(): void
    {
        $this->twoPamphlets();
        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);

        $urls = array_map(static fn (array $page): string => $page['url'], iterator_to_array(app(PageCollector::class)->pages(), false));

        $this->assertContains($this->root().'/pamphlets/beta', $urls);
        $this->assertContains($this->root().'/catalog/lamp', $urls);
    }

    #[DefineEnvironment('withPamphlets')]
    public function test_records_are_read_in_batches_of_two_hundred(): void
    {
        $this->twoPamphlets();
        $limits = [];
        DB::listen(static function ($query) use (&$limits): void {
            if (preg_match('/limit (\d+) offset/', $query->sql, $m)) {
                $limits[] = (int) $m[1];
            }
        });

        iterator_to_array(app(PageCollector::class)->pages(), false);

        $this->assertGreaterThan(2, count($limits));
        $this->assertSame([200], array_values(array_unique($limits)));
    }

    #[DefineEnvironment('withPamphlets')]
    public function test_without_the_theme_only_the_pages_the_modules_list_are_there(): void
    {
        $this->twoPamphlets();
        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);
        $collector = app(PageCollector::class);

        $this->assertNotSame([], $collector->otherUrls());

        unset($this->app[PageCollector::THEME_PAGES]);

        $this->assertSame([['alpha', 'en', $this->root().'/pamphlets/alpha']], $this->pamphlets());
        $this->assertCount(1, iterator_to_array($collector->pages(), false));
        $this->assertSame([], $collector->otherUrls());

        app(Audit::class)->run();

        $this->assertSame(0, Issue::query()->where('model', '!=', Pamphlet::class)->count());
    }

    public function test_a_record_has_no_page_in_a_language_it_is_not_written_in(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        News::query()->create(['name' => ['en' => 'Hello'], 'code' => ['en' => 'hello'], 'preview_text' => ['en' => 'Text']]);
        News::query()->create(['name' => ['en' => 'Hi', 'de' => 'Hallo'], 'code' => ['en' => 'hi', 'de' => 'hallo'], 'preview_text' => ['en' => 'Text']]);

        $pages = [];

        foreach (app(PageCollector::class)->pages() as $page) {
            if ($page['record'] instanceof News) {
                $pages[] = [$page['record']->getTranslation('code', 'en'), $page['language']];
            }
        }

        $this->assertSame([['hello', 'en'], ['hi', 'en'], ['hi', 'de']], $pages);
    }

    public function test_the_pages_that_are_not_records_are_the_home_page_and_the_lists_of_every_language(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);

        $urls = app(PageCollector::class)->otherUrls();

        $this->assertContains($this->root(), $urls);
        $this->assertContains($this->root().'/catalog', $urls);
        $this->assertContains($this->root().'/de', $urls);
        $this->assertContains($this->root().'/de/catalog', $urls);
        $this->assertContains($this->root().'/de/news', $urls);
    }

    protected function withPamphlets(Application $app): void
    {
        $app['config']->set('atrium.resources', [Pamphlet::class]);
        $app['config']->set('atrium.public_urls.'.Pamphlet::class, ['prefix' => 'pamphlets']);
    }

    protected function withGhost(Application $app): void
    {
        // The module that cannot be read comes first in its prefix.
        $app['config']->set('atrium.public_urls.'.Ghost::class, ['prefix' => 'pamphlets']);
        $this->withPamphlets($app);
    }
}
