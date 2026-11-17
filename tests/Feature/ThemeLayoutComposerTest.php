<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sites;
use Atrium\Catalog\Models\Product;
use Atrium\Seo\Meta\MetaResolver;
use Atrium\Seo\Meta\Templates;
use Atrium\Seo\Meta\Variables;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Tests\Fixtures\LocalizedProduct;
use Atrium\Seo\Tests\TestCase;
use Atrium\Seo\View\ThemeLayoutComposer;
use Atrium\Theme\Pages;
use Atrium\Theme\RecordView;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\View;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the layout of the theme gets from the SEO module, composed by hand:
 * the request, the route and the data the controller passed are all given
 * here, so every one of them can be told apart.
 */
final class ThemeLayoutComposerTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $data
     * @return array{0:array<string,mixed>,1:SeoPage} the data the layout ends up with, and the page
     */
    private function compose(array $data, string $uri = '/catalog', ?string $route = null, bool $named = true, ?SeoPage $page = null, string $language = 'en'): array
    {
        $request = Request::create($uri);

        if ($route !== null) {
            $bound = new Route('GET', '/{prefix}', static fn (): string => '');

            if ($named) {
                $bound->name($route);
            }

            $bound->bind($request);
            $request->setRouteResolver(static fn (): Route => $bound);
        }

        app()->setLocale($language);
        $page ??= new SeoPage;
        $composer = new ThemeLayoutComposer($page, app(MetaResolver::class), app(Templates::class), app(Variables::class), app(Sites::class), app(ContentLanguages::class), $request);
        $view = new View(app('view'), app('view.engine.resolver')->resolve('php'), 'layout', '/dev/null', $data);
        $composer->compose($view);

        return [$view->getData(), $page];
    }

    private function mouse(): Product
    {
        return Product::query()->create(['name' => 'Mouse', 'code' => 'mouse', 'price' => 10]);
    }

    public function test_the_layout_gets_the_meta_and_an_empty_list_of_alternates(): void
    {
        [$data, $page] = $this->compose([
            'metaTitle' => 'Products',
            'metaDescription' => 'Everything we sell',
            'metaKeywords' => 'shop, products',
            'ogImage' => '/img/share.png',
            'canonical' => 'https://example.com/catalog',
            'alternates' => ['en' => 'https://example.com/catalog'],
        ], route: 'shop.index');

        $this->assertSame('Products | Demo shop', $data['metaTitle']);
        $this->assertSame('Everything we sell', $data['metaDescription']);
        $this->assertSame('shop, products', $data['metaKeywords']);
        $this->assertSame($this->root().'/img/share.png', $data['ogImage']);
        $this->assertSame('https://example.com/catalog', $data['canonical']);
        $this->assertSame([], $data['alternates']);
        $this->assertSame($data['metaTitle'], $page->meta->title);
        $this->assertSame($data['canonical'], $page->meta->canonical);
    }

    public function test_a_theme_without_meta_leaves_the_layout_empty(): void
    {
        [$data, $page] = $this->compose([]);

        $this->assertSame('', $data['metaTitle']);
        $this->assertSame('', $data['metaDescription']);
        $this->assertSame('', $data['metaKeywords']);
        $this->assertNull($data['ogImage']);
        $this->assertNull($data['canonical']);
        $this->assertSame([], $page->alternates);
    }

    public function test_the_title_is_the_meta_title_then_the_title(): void
    {
        [$data] = $this->compose(['title' => 'Plain'], route: 'shop.index');
        $this->assertSame('Plain | Demo shop', $data['metaTitle']);

        [$data] = $this->compose(['title' => 'Plain', 'metaTitle' => 'Meta'], route: 'shop.index');
        $this->assertSame('Meta | Demo shop', $data['metaTitle']);
    }

    #[DataProvider('images')]
    public function test_the_open_graph_image_of_the_theme_is_made_absolute(mixed $passed, ?string $expected): void
    {
        [$data] = $this->compose(['ogImage' => $passed]);

        $this->assertSame($expected, $data['ogImage']);
    }

    /**
     * @return array<string,array{0:mixed,1:?string}>
     */
    public static function images(): array
    {
        return [
            'a path' => ['/img/a.png', 'http://localhost/img/a.png'],
            'an address' => ['https://cdn.example.com/a.png', 'https://cdn.example.com/a.png'],
            'nothing' => ['', null],
            'not a text' => [5, null],
        ];
    }

    public function test_the_site_image_is_only_for_pages_whose_theme_had_none(): void
    {
        Setting::save('seo', ['og_image' => 'brand/share.png']);

        [$data] = $this->compose([]);
        $this->assertSame($this->root().'/storage/brand/share.png', $data['ogImage']);

        [$data] = $this->compose(['ogImage' => 'https://cdn.example.com/a.png']);
        $this->assertSame('https://cdn.example.com/a.png', $data['ogImage']);
    }

    public function test_the_meta_of_a_record_beats_the_one_of_the_theme(): void
    {
        $mouse = $this->mouse();
        $mouse->seoMeta()->create(['title' => 'The best {name}']);

        [$data, $page] = $this->compose([
            'page' => new RecordView($mouse->fresh(), app(Pages::class)),
            'metaTitle' => 'From the theme',
            'metaKeywords' => 'theme, words',
        ], uri: '/catalog/mouse', route: 'shop.show');

        $this->assertSame('The best Mouse', $data['metaTitle']);
        $this->assertSame('Buy Mouse for €10.00.', $data['metaDescription']);
        $this->assertSame('theme, words', $data['metaKeywords']);
        $this->assertSame(SeoPage::RECORD, $page->kind);
        $this->assertTrue($mouse->is($page->record));
    }

    public function test_the_meta_of_an_address_beats_the_one_of_a_record_and_ignores_the_page_number(): void
    {
        $mouse = $this->mouse();
        $mouse->seoMeta()->create(['title' => 'From the record', 'description' => 'Record text']);
        PageMeta::query()->create(['path' => '/catalog/mouse', 'title' => ['en' => 'From the address']]);

        [$data] = $this->compose(['page' => $mouse->fresh()], uri: '/catalog/mouse?page=2', route: 'shop.show');

        $this->assertSame('From the address — page 2', $data['metaTitle']);
        $this->assertSame('Record text', $data['metaDescription']);
    }

    public function test_the_address_meta_of_a_list_comes_before_the_template(): void
    {
        PageMeta::query()->create(['path' => '/catalog', 'title' => ['en' => 'Our catalog'], 'canonical_url' => 'https://example.com/own']);

        [$data] = $this->compose(['metaTitle' => 'Products'], uri: '/catalog?utm=a&page=1', route: 'shop.index');
        $this->assertSame('Products | Demo shop', $data['metaTitle']);

        [$data] = $this->compose(['metaTitle' => 'Products'], uri: '/catalog?page=1', route: 'shop.index');
        $this->assertSame('Our catalog', $data['metaTitle']);
        $this->assertSame('https://example.com/own', $data['canonical']);
    }

    public function test_the_address_meta_of_a_list_with_a_query_matches_that_query_only(): void
    {
        PageMeta::query()->create(['path' => '/catalog?color=red&size=l', 'title' => ['en' => 'Large red']]);

        [$data] = $this->compose(['metaTitle' => 'Products'], uri: '/catalog?size=l&color=red&page=2', route: 'shop.index');
        $this->assertSame('Large red — page 2', $data['metaTitle']);

        [$data] = $this->compose(['metaTitle' => 'Products'], uri: '/catalog?size=l', route: 'shop.index');
        $this->assertSame('Products | Demo shop', $data['metaTitle']);
    }

    public function test_the_seo_tab_is_one_text_for_the_default_language_only_when_the_name_is_translated(): void
    {
        $localized = new LocalizedProduct;
        $localized->forceFill(['name' => 'Mouse', 'code' => 'mouse'])->save();
        $localized->seoMeta()->create(['title' => 'Own {name}']);
        $localized = LocalizedProduct::query()->findOrFail($localized->id);

        [$data] = $this->compose(['page' => $localized], uri: '/catalog/mouse', route: 'shop.show', language: 'en');
        $this->assertSame('Own Mouse', $data['metaTitle']);

        [$data] = $this->compose(['page' => $localized], uri: '/catalog/mouse', route: 'shop.show', language: 'de');
        $this->assertSame('Maus | Demo shop', $data['metaTitle']);
    }

    public function test_a_later_page_says_its_number_and_is_canonical_to_itself(): void
    {
        PageMeta::query()->create(['path' => '/catalog', 'canonical_url' => 'https://example.com/own']);
        $theme = ['metaTitle' => 'Products', 'canonical' => 'https://example.com/catalog?page=2'];

        [$data, $page] = $this->compose($theme, uri: '/catalog?page=2', route: 'shop.index');
        $this->assertSame('Products | Demo shop — page 2', $data['metaTitle']);
        $this->assertSame('https://example.com/catalog?page=2', $data['canonical']);
        $this->assertSame(2, $page->pageNumber);

        [$data] = $this->compose(['metaTitle' => 'Products'], uri: '/catalog?page=2', route: 'shop.index');
        $this->assertSame('Products | Demo shop — page 2', $data['metaTitle']);
        $this->assertNull($data['canonical']);

        [$data, $page] = $this->compose($theme, uri: '/catalog?page=1', route: 'shop.index');
        $this->assertSame('Products | Demo shop', $data['metaTitle']);
        $this->assertSame('https://example.com/own', $data['canonical']);
        $this->assertSame(1, $page->pageNumber);
    }

    #[DataProvider('pageNumbers')]
    public function test_the_page_number_is_a_whole_number_from_one(string $query, int $expected): void
    {
        [, $page] = $this->compose([], uri: '/catalog'.$query);

        $this->assertSame($expected, $page->pageNumber);
    }

    /**
     * @return array<string,array{0:string,1:int}>
     */
    public static function pageNumbers(): array
    {
        return [
            'none' => ['', 1],
            'the first' => ['?page=1', 1],
            'the third' => ['?page=3', 3],
            'zero' => ['?page=0', 1],
            'negative' => ['?page=-3', 1],
            'a word' => ['?page=abc', 1],
        ];
    }

    public function test_the_home_page_and_a_list_use_the_templates_of_their_own_kind(): void
    {
        $translator = app('translator');
        $translator->load('atrium-seo', 'templates', 'en');
        $translator->addLines(['templates.home.description' => 'Welcome to {site}'], 'en', 'atrium-seo');
        $translator->addLines(['templates.index.description' => 'About {name}'], 'en', 'atrium-seo');

        [$data, $page] = $this->compose(['metaTitle' => 'Home page'], uri: '/', route: 'shop.home');
        $this->assertSame(SeoPage::HOME, $page->kind);
        $this->assertSame('Demo shop', $data['metaTitle']);
        $this->assertSame('Welcome to Demo shop', $data['metaDescription']);

        [$data] = $this->compose(['metaTitle' => 'Products'], route: 'shop.index');
        $this->assertSame('Products | Demo shop', $data['metaTitle']);
        $this->assertSame('About Products', $data['metaDescription']);
    }

    public function test_a_template_left_empty_says_nothing(): void
    {
        $translator = app('translator');
        $translator->load('atrium-seo', 'templates', 'en');
        $translator->addLines(['templates.index.title' => '', 'templates.index.description' => ''], 'en', 'atrium-seo');

        [$data] = $this->compose(['metaTitle' => 'Products', 'metaDescription' => 'Ours'], route: 'shop.index');

        $this->assertSame('Products', $data['metaTitle']);
        $this->assertSame('Ours', $data['metaDescription']);
    }

    public function test_the_kind_of_the_page_comes_from_the_route_and_the_record(): void
    {
        $mouse = $this->mouse();
        $cases = [
            'home' => [SeoPage::HOME, ['shop.home', []]],
            'home with a record' => [SeoPage::HOME, ['shop.home', ['page' => $mouse]]],
            'section' => [SeoPage::SECTION, ['shop.show', ['page' => $mouse, 'sections' => []]]],
            'record' => [SeoPage::RECORD, ['shop.show', ['page' => $mouse]]],
            'a list' => [SeoPage::INDEX, ['shop.index', []]],
            'a list with a record' => [SeoPage::RECORD, ['shop.index', ['page' => $mouse]]],
            'a page' => [SeoPage::PAGE, ['shop.page', []]],
            'a sections list without a record' => [SeoPage::PAGE, ['shop.page', ['sections' => []]]],
            'no route' => [SeoPage::PAGE, [null, []]],
            'a route without a name' => [SeoPage::PAGE, ['x', []]],
        ];

        foreach ($cases as $label => [$kind, [$route, $data]]) {
            [, $page] = $this->compose($data, route: $route, named: $label !== 'a route without a name');

            $this->assertSame($kind, $page->kind, $label);
        }
    }

    public function test_the_record_is_what_the_record_view_or_the_page_holds(): void
    {
        $mouse = $this->mouse();

        [, $page] = $this->compose(['page' => new RecordView($mouse, app(Pages::class))]);
        $this->assertTrue($mouse->is($page->record));

        [, $page] = $this->compose(['page' => $mouse]);
        $this->assertTrue($mouse->is($page->record));

        [, $page] = $this->compose(['page' => 'a text']);
        $this->assertNull($page->record);

        [, $page] = $this->compose([]);
        $this->assertNull($page->record);
    }

    public function test_the_breadcrumbs_are_the_themes_when_it_gave_a_list(): void
    {
        $crumbs = [['label' => 'Catalog', 'url' => 'https://example.com/catalog']];

        [, $page] = $this->compose(['breadcrumbs' => $crumbs]);
        $this->assertSame($crumbs, $page->breadcrumbs);

        [, $page] = $this->compose(['breadcrumbs' => 'Catalog']);
        $this->assertSame([], $page->breadcrumbs);

        [, $page] = $this->compose([]);
        $this->assertSame([], $page->breadcrumbs);
    }

    public function test_the_models_of_a_module_come_with_its_prefix(): void
    {
        [, $page] = $this->compose([]);
        $this->assertNull($page->prefix);
        $this->assertSame([], $page->models);

        [, $page] = $this->compose([], uri: '/catalog', route: 'shop.index');
        $this->assertSame('catalog', $page->prefix);
        $this->assertContains(Product::class, $page->models);
        $this->assertSame(array_keys(app(Pages::class)->module('catalog')), $page->models);

        [, $page] = $this->compose([], uri: '/nothing', route: 'shop.index');
        $this->assertSame('nothing', $page->prefix);
        $this->assertSame([], $page->models);
    }

    public function test_the_address_of_the_page_is_the_canonical_without_a_query(): void
    {
        [, $page] = $this->compose(['canonical' => 'https://example.com/catalog?page=2&x=1']);
        $this->assertSame('https://example.com/catalog', $page->url);

        [, $page] = $this->compose(['canonical' => '']);
        $this->assertNull($page->url);

        [, $page] = $this->compose([]);
        $this->assertNull($page->url);

        [, $page] = $this->compose(['canonical' => new HtmlString('https://example.com/catalog?page=2')]);
        $this->assertSame('https://example.com/catalog', $page->url);
    }

    public function test_a_canonical_that_is_not_a_text_is_read_as_one(): void
    {
        [$data] = $this->compose(['canonical' => new HtmlString('https://example.com/catalog')]);
        $this->assertSame('https://example.com/catalog', $data['canonical']);

        [$data] = $this->compose(['canonical' => new HtmlString('https://example.com/catalog')], uri: '/catalog?page=2', route: 'shop.index', page: (new SeoPage)->kind(SeoPage::INDEX));
        $this->assertSame('https://example.com/catalog', $data['canonical']);
    }

    public function test_the_alternates_are_the_themes_addresses_and_a_later_page_has_its_own(): void
    {
        $alternates = ['en' => 'https://example.com/catalog?sort=a', 'fr' => 5, 'de' => 'https://example.com/de/catalog'];

        [, $page] = $this->compose(['alternates' => $alternates], route: 'shop.index');
        $this->assertSame(['en' => 'https://example.com/catalog?sort=a', 'de' => 'https://example.com/de/catalog'], $page->alternates);

        [, $page] = $this->compose(['alternates' => $alternates], uri: '/catalog?page=3', route: 'shop.index');
        $this->assertSame(['en' => 'https://example.com/catalog?page=3', 'de' => 'https://example.com/de/catalog?page=3'], $page->alternates);

        [, $page] = $this->compose([]);
        $this->assertSame([], $page->alternates);
    }

    public function test_a_list_without_alternates_takes_the_language_switch(): void
    {
        $switch = ['en' => 'https://example.com/catalog', 'fr' => 5, 'de' => 'https://example.com/de/catalog'];
        $own = ['en' => 'https://example.com/own'];

        [, $page] = $this->compose(['switch' => $switch], route: 'shop.index');
        $this->assertSame(['en' => 'https://example.com/catalog', 'de' => 'https://example.com/de/catalog'], $page->alternates);

        [, $page] = $this->compose(['switch' => $switch], uri: '/catalog?page=2', route: 'shop.index');
        $this->assertSame(['en' => 'https://example.com/catalog?page=2', 'de' => 'https://example.com/de/catalog?page=2'], $page->alternates);

        [, $page] = $this->compose(['alternates' => $own, 'switch' => $switch], route: 'shop.index');
        $this->assertSame($own, $page->alternates);

        [, $page] = $this->compose(['alternates' => ['en' => 5], 'switch' => $switch], route: 'shop.index');
        $this->assertSame(['en' => 'https://example.com/catalog', 'de' => 'https://example.com/de/catalog'], $page->alternates);

        // Only a list has a switch to take.
        [, $page] = $this->compose(['switch' => $switch], route: 'shop.page');
        $this->assertSame([], $page->alternates);
    }

    public function test_the_page_is_filled_once(): void
    {
        [, $page] = $this->compose(['canonical' => 'https://example.com/a'], route: 'shop.index');
        $this->assertTrue($page->filled);
        $this->assertSame(SeoPage::INDEX, $page->kind);

        $mouse = $this->mouse();
        $filled = (new SeoPage)->kind(SeoPage::PAGE);
        [, $page] = $this->compose(['page' => $mouse, 'canonical' => 'https://example.com/b', 'alternates' => ['en' => 'https://example.com/b']], uri: '/catalog?page=4', route: 'shop.home', page: $filled);

        $this->assertSame($filled, $page);
        $this->assertSame(SeoPage::PAGE, $page->kind);
        $this->assertNull($page->record);
        $this->assertNull($page->url);
        $this->assertSame(1, $page->pageNumber);
        $this->assertSame([], $page->alternates);
    }
}
