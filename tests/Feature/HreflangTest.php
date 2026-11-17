<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sites;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Hreflang;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * hreflang links across every language and every site a page is on, with
 * x-default: the default site's default language.
 */
final class HreflangTest extends TestCase
{
    public function test_a_site_in_one_language_has_no_links(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $this->assertSame([], $this->hreflangs((string) $this->get('/catalog/mouse')->assertOk()->getContent()));
    }

    public function test_every_language_of_the_site_and_x_default(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);

        $expected = [
            'en' => $this->root(),
            'de' => $this->root().'/de',
            'x-default' => $this->root(),
        ];

        $this->assertSame($expected, $this->hreflangs((string) $this->get('/')->assertOk()->getContent()));
        $this->assertSame($expected, $this->hreflangs((string) $this->get('/de')->assertOk()->getContent()));
    }

    public function test_a_record_written_once_is_not_a_translation(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $this->assertSame([], $this->hreflangs((string) $this->get('/catalog/mouse')->assertOk()->getContent()));
    }

    public function test_a_translated_record_links_only_the_languages_it_is_written_in(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Language::query()->create(['code' => 'ru', 'name' => 'Русский']);
        News::query()->create(['name' => ['en' => 'We moved', 'de' => 'Umgezogen'], 'code' => ['en' => 'we-moved', 'de' => 'umgezogen']]);
        News::query()->create(['name' => ['en' => 'English only'], 'code' => ['en' => 'english-only']]);

        $this->assertSame([
            'en' => $this->root().'/news/we-moved',
            'de' => $this->root().'/de/news/umgezogen',
            'x-default' => $this->root().'/news/we-moved',
        ], $this->hreflangs((string) $this->get('/de/news/umgezogen')->assertOk()->getContent()));

        $this->assertSame([], $this->hreflangs((string) $this->get('/news/english-only')->assertOk()->getContent()));
    }

    public function test_a_shared_page_links_the_other_sites_in_their_languages(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);
        Site::query()->whereKey(1)->update(['languages' => ['en', 'de']]);
        Site::query()->create(['code' => 'fr', 'name' => 'Boutique', 'folder' => 'fr-shop', 'languages' => ['fr'], 'default_language' => 'fr']);

        $expected = [
            'en' => $this->root().'/catalog',
            'de' => $this->root().'/de/catalog',
            'fr' => $this->root().'/fr-shop/catalog',
            'x-default' => $this->root().'/catalog',
        ];

        $root = $this->hreflangs((string) $this->get('/catalog')->assertOk()->getContent());
        $this->assertEqualsCanonicalizing($expected, $root);
        $this->assertSame('x-default', array_key_last($root));

        // From the other site: the same set, x-default still the default site.
        $this->assertEqualsCanonicalizing($expected, $this->hreflangs((string) $this->get('/fr-shop/catalog')->assertOk()->getContent()));
    }

    public function test_a_site_whose_languages_are_all_switched_off_is_not_linked(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Site::query()->create(['code' => 'jp', 'name' => 'Japan', 'folder' => 'jp', 'languages' => ['ja'], 'default_language' => 'ja']);

        $this->assertSame([
            'en' => $this->root().'/catalog',
            'de' => $this->root().'/de/catalog',
            'x-default' => $this->root().'/catalog',
        ], $this->hreflangs((string) $this->get('/catalog')->assertOk()->getContent()));
    }

    public function test_an_address_outside_the_site_is_not_an_alternate(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $page = new SeoPage;
        $page->alternates = ['en' => $this->root().'/catalog', 'de' => 'https://elsewhere.example/de/catalog'];

        $this->assertSame([], app(Hreflang::class)->for($page));

        $page->alternates['de'] = $this->root().'/de/catalog';
        $this->assertSame(['en', 'de', 'x-default'], array_keys(app(Hreflang::class)->for($page)));
    }

    public function test_a_site_on_its_own_domain(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        Site::query()->create(['code' => 'de', 'name' => 'Laden', 'domains' => ['shop.example.de'], 'languages' => ['de'], 'default_language' => 'de']);

        $this->assertSame([
            'en' => $this->root(),
            'de' => 'http://shop.example.de',
            'x-default' => $this->root(),
        ], $this->hreflangs((string) $this->get('/')->assertOk()->getContent()));
    }

    public function test_a_language_shared_by_two_sites_stays_with_the_current_one(): void
    {
        Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);

        $this->assertSame([
            'en' => $this->root().'/site2/catalog',
            'de' => $this->root().'/site2/de/catalog',
            'x-default' => $this->root().'/catalog',
        ], $this->hreflangs((string) $this->get('/site2/catalog')->assertOk()->getContent()));
    }

    public function test_a_record_of_one_site_is_not_linked_to_the_others(): void
    {
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        Site::query()->create(['code' => 'fr', 'name' => 'Boutique', 'folder' => 'fr-shop', 'languages' => ['fr'], 'default_language' => 'fr']);
        News::query()->create(['name' => ['en' => 'We moved', 'fr' => 'Déménagé'], 'code' => ['en' => 'we-moved', 'fr' => 'demenage']]);

        $this->assertSame([], $this->hreflangs((string) $this->get('/news/we-moved')->assertOk()->getContent()));
    }

    public function test_a_site_switched_off_is_not_linked(): void
    {
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        Site::query()->create(['code' => 'fr', 'name' => 'Boutique', 'folder' => 'fr-shop', 'languages' => ['fr'], 'default_language' => 'fr', 'active' => false]);

        $this->assertSame([], $this->hreflangs((string) $this->get('/')->assertOk()->getContent()));
    }

    public function test_a_later_page_of_a_list_links_the_same_page_in_each_language(): void
    {
        config(['atrium-theme.per_page' => 1]);
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        Product::query()->create(['name' => 'Keyboard', 'code' => 'keyboard']);

        $this->assertSame([
            'en' => $this->root().'/catalog?page=2',
            'de' => $this->root().'/de/catalog?page=2',
            'x-default' => $this->root().'/catalog?page=2',
        ], $this->hreflangs((string) $this->get('/catalog?page=2')->assertOk()->getContent()));
    }

    /**
     * @return array<string,string> hreflang => address of a page that has only the given addresses
     */
    private function linksOf(array $alternates, ?Model $record = null): array
    {
        $page = new SeoPage;
        $page->record = $record;
        $page->alternates = $alternates;

        return app(Hreflang::class)->for($page);
    }

    public function test_a_language_the_record_is_not_written_in_does_not_stop_the_next_one(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);

        $this->assertSame([
            'en' => $this->root().'/catalog/mouse',
            'fr' => $this->root().'/fr/catalog/souris',
            'x-default' => $this->root().'/catalog/mouse',
        ], $this->linksOf(['en' => $this->root().'/catalog/mouse', 'fr' => $this->root().'/fr/catalog/souris'], new Product));
    }

    public function test_an_address_outside_the_site_does_not_stop_the_next_ones(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);

        $this->assertSame([
            'en' => $this->root().'/catalog',
            'fr' => $this->root().'/fr/catalog',
            'x-default' => $this->root().'/catalog',
        ], $this->linksOf([
            'de' => 'https://elsewhere.example/de/catalog',
            'en' => $this->root().'/catalog',
            'fr' => $this->root().'/fr/catalog',
        ]));
    }

    /**
     * @return iterable<string,array{string,string}> the German address of a page, and where the German site shows it
     */
    public static function germanAddresses(): iterable
    {
        yield 'the home page of the language' => ['/de', '/shop'];
        yield 'a page under the prefix' => ['/de/guide', '/shop/guide'];
        yield 'a page with a query under the prefix' => ['/de?page=2', '/shop/?page=2'];
        yield 'a word that only starts like the prefix' => ['/deutsch', '/shop/deutsch'];
        yield 'a page that only starts like the prefix with a query' => ['/deutsch?page=2', '/shop/deutsch?page=2'];
    }

    #[DataProvider('germanAddresses')]
    public function test_the_language_prefix_is_taken_off_the_address_for_another_site(string $german, string $shown): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        Site::query()->create(['code' => 'shop', 'name' => 'Shop', 'folder' => 'shop', 'languages' => ['de'], 'default_language' => 'de']);

        $this->assertSame([
            'en' => $this->root().'/guide',
            'de' => $this->root().$shown,
            'x-default' => $this->root().'/guide',
        ], $this->linksOf(['en' => $this->root().'/guide', 'de' => $this->root().$german]));
    }

    public function test_the_default_language_has_no_prefix_to_take_off(): void
    {
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        Site::query()->create(['code' => 'shop', 'name' => 'Shop', 'folder' => 'shop', 'languages' => ['fr'], 'default_language' => 'fr']);

        // A page that really lives under /en/ in the default language.
        $this->assertSame([
            'en' => $this->root().'/en/guide',
            'fr' => $this->root().'/shop/en/guide',
            'x-default' => $this->root().'/en/guide',
        ], $this->linksOf(['en' => $this->root().'/en/guide']));
    }

    public function test_another_site_shows_a_page_in_its_own_languages_only(): void
    {
        foreach (['de' => 'Deutsch', 'fr' => 'Français', 'ja' => '日本語'] as $code => $name) {
            Language::query()->create(['code' => $code, 'name' => $name]);
        }
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        Site::query()->create(['code' => 'shop', 'name' => 'Shop', 'folder' => 'shop', 'languages' => ['de', 'fr']]);

        $this->assertSame([
            'en' => $this->root().'/guide',
            'de' => $this->root().'/shop/guide',
            'fr' => $this->root().'/shop/fr/guide',
            'x-default' => $this->root().'/guide',
        ], $this->linksOf(['en' => $this->root().'/guide']));
    }

    public function test_a_site_whose_default_language_is_switched_off_starts_with_its_first_one(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        // "ja" is not among the panel's active languages.
        Site::query()->create(['code' => 'shop', 'name' => 'Shop', 'folder' => 'shop', 'languages' => ['ja', 'fr', 'de'], 'default_language' => 'ja']);

        $this->assertSame([
            'en' => $this->root().'/guide',
            'de' => $this->root().'/shop/guide',
            'fr' => $this->root().'/shop/fr/guide',
            'x-default' => $this->root().'/guide',
        ], $this->linksOf(['en' => $this->root().'/guide']));
    }

    public function test_a_record_of_a_per_site_module_is_not_linked_to_the_other_sites(): void
    {
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        Site::query()->create(['code' => 'shop', 'name' => 'Shop', 'folder' => 'shop', 'languages' => ['fr'], 'default_language' => 'fr']);
        $alternates = ['en' => $this->root().'/news/moved', 'fr' => $this->root().'/fr/news/demenage'];

        $this->assertSame([], $this->linksOf($alternates, new News));
        $this->assertSame([
            'en' => $this->root().'/news/moved',
            'fr' => $this->root().'/shop/news/demenage',
            'x-default' => $this->root().'/news/moved',
        ], $this->linksOf($alternates, new Product));
    }

    public function test_the_current_site_comes_first_then_the_default_one_then_the_rest(): void
    {
        foreach (['de' => 'Deutsch', 'fr' => 'Français'] as $code => $name) {
            Language::query()->create(['code' => $code, 'name' => $name]);
        }
        Site::query()->whereKey(1)->update(['languages' => ['en', 'de']]);
        $current = Site::query()->create(['code' => 'two', 'name' => 'Two', 'folder' => 'two', 'languages' => ['fr']]);
        Site::query()->create(['code' => 'three', 'name' => 'Three', 'folder' => 'three', 'languages' => ['de']]);
        app(Sites::class)->setCurrent($current);

        // German is the default site's, not the third one's.
        $this->assertSame([
            'fr' => $this->root().'/two/guide',
            'en' => $this->root().'/guide',
            'de' => $this->root().'/de/guide',
            'x-default' => $this->root().'/guide',
        ], $this->linksOf(['fr' => $this->root().'/two/guide']));
    }

    public function test_without_the_default_sites_default_language_x_default_is_the_current_sites_first_link(): void
    {
        foreach (['de' => 'Deutsch', 'fr' => 'Français'] as $code => $name) {
            Language::query()->create(['code' => $code, 'name' => $name]);
        }
        Site::query()->whereKey(1)->update(['languages' => ['en', 'de']]);
        $current = Site::query()->create(['code' => 'two', 'name' => 'Two', 'folder' => 'two', 'languages' => ['de', 'fr'], 'default_language' => 'fr']);
        app(Sites::class)->setCurrent($current);

        // A record with no English text: the default site has no link for its own default language.
        $links = $this->linksOf([
            'de' => $this->root().'/two/de/catalog/maus',
            'fr' => $this->root().'/two/catalog/souris',
        ], new Product);

        $this->assertSame(['fr', 'de', 'x-default'], array_keys($links));
        $this->assertSame($this->root().'/two/catalog/souris', $links['x-default']);
    }

    public function test_the_default_sites_default_language_wins_x_default_from_another_site(): void
    {
        Language::query()->create(['code' => 'fr', 'name' => 'Français']);
        Site::query()->whereKey(1)->update(['languages' => ['en']]);
        $current = Site::query()->create(['code' => 'two', 'name' => 'Two', 'folder' => 'two', 'languages' => ['fr']]);
        app(Sites::class)->setCurrent($current);

        $links = $this->linksOf(['fr' => $this->root().'/two/guide']);

        $this->assertSame($this->root().'/guide', $links['x-default']);
        $this->assertSame($this->root().'/two/guide', $links['fr']);
    }

    public function test_when_the_languages_cannot_be_read_only_the_current_site_is_linked(): void
    {
        foreach (['de' => 'Deutsch', 'fr' => 'Français'] as $code => $name) {
            Language::query()->create(['code' => $code, 'name' => $name]);
        }
        Site::query()->whereKey(1)->update(['languages' => ['en', 'de']]);
        Site::query()->create(['code' => 'shop', 'name' => 'Shop', 'folder' => 'shop', 'languages' => ['fr']]);
        // The current site's languages are read before the tables go missing.
        app(ContentLanguages::class)->all();
        app(Sites::class)->all();

        Language::addGlobalScope('missing', static function (): never {
            throw new QueryException('testing', 'select * from "languages"', [], new \Exception('no such table: languages'));
        });

        try {
            $links = $this->linksOf(['en' => $this->root().'/guide', 'de' => $this->root().'/de/guide']);
        } finally {
            Model::clearBootedModels();
        }

        $this->assertSame([
            'en' => $this->root().'/guide',
            'de' => $this->root().'/de/guide',
            'x-default' => $this->root().'/guide',
        ], $links);
    }

    public function test_language_codes_become_hreflang_values(): void
    {
        $this->assertSame('pt-BR', Hreflang::hreflang('pt_BR'));
        $this->assertSame('zh-CN', Hreflang::hreflang('zh-cn'));
        $this->assertSame('de', Hreflang::hreflang('de'));
        $this->assertSame('pt-BR', Hreflang::hreflang('PT_br'));
        $this->assertSame('en', Hreflang::hreflang('EN'));
    }
}
