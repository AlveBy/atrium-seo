<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Support\Sites;
use Atrium\Catalog\Models\Product;
use Atrium\Faq\Faq;
use Atrium\Faq\Models\Category;
use Atrium\Faq\Models\Question;
use Atrium\News\Models\News;
use Atrium\Reviews\Models\Review;
use Atrium\Seo\Meta\Meta;
use Atrium\Seo\Schema\BreadcrumbSchema;
use Atrium\Seo\Schema\FaqSchema;
use Atrium\Seo\Schema\LocalBusinessSchema;
use Atrium\Seo\Schema\NewsArticleSchema;
use Atrium\Seo\Schema\OrganizationSchema;
use Atrium\Seo\Schema\ProductSchema;
use Atrium\Seo\Schema\ReviewSchema;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Tests\TestCase;
use Atrium\Stores\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Each provider's node, whole, for the page it is meant for and for the
 * pages it is not.
 */
final class SchemaProvidersTest extends TestCase
{
    private const URL = 'https://example.com/shop/lamp?utm=1';

    private function page(?Model $record = null, string $kind = SeoPage::PAGE): SeoPage
    {
        $page = new SeoPage;
        $page->url = self::URL;

        if ($record !== null) {
            $page->show($record);
        }

        $page->kind = $kind === SeoPage::PAGE && $record !== null ? SeoPage::RECORD : $kind;

        return $page;
    }

    private function home(): string
    {
        return $this->root().'/';
    }

    // Breadcrumbs

    public function test_the_home_page_has_no_breadcrumbs_whatever_else_it_knows(): void
    {
        $page = $this->page(null, SeoPage::HOME);
        $page->breadcrumbs = [['label' => 'Shop', 'url' => 'https://example.com/shop']];
        $page->meta = new Meta(title: 'Welcome');

        $this->assertSame([], app(BreadcrumbSchema::class)->nodes($page));
    }

    public function test_a_page_that_is_neither_a_list_nor_a_record_nor_below_anything_has_no_breadcrumbs(): void
    {
        $page = $this->page();
        $page->meta = new Meta(title: 'About us');

        $this->assertSame([], app(BreadcrumbSchema::class)->nodes($page));
    }

    public function test_a_list_page_leads_from_the_home_page_to_its_title(): void
    {
        $page = $this->page(null, SeoPage::INDEX);
        $page->meta = new Meta(title: 'Products');

        $this->assertSame([[
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Demo shop', 'item' => $this->home()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Products', 'item' => 'https://example.com/shop/lamp'],
            ],
        ]], app(BreadcrumbSchema::class)->nodes($page));
    }

    public function test_a_list_page_without_a_title_has_nowhere_to_lead(): void
    {
        $this->assertSame([], app(BreadcrumbSchema::class)->nodes($this->page(null, SeoPage::INDEX)));
    }

    public function test_a_page_below_sections_lists_them_between_the_home_page_and_its_title(): void
    {
        $page = $this->page();
        $page->breadcrumbs = [['label' => 'Shop', 'url' => 'https://example.com/shop'], ['label' => 'Lamps', 'url' => 'https://example.com/shop/lamps']];
        $page->meta = new Meta(title: 'Desk lamp');

        $this->assertSame([[
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Demo shop', 'item' => $this->home()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop', 'item' => 'https://example.com/shop'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => 'Lamps', 'item' => 'https://example.com/shop/lamps'],
                ['@type' => 'ListItem', 'position' => 4, 'name' => 'Desk lamp', 'item' => 'https://example.com/shop/lamp'],
            ],
        ]], app(BreadcrumbSchema::class)->nodes($page));
    }

    public function test_sections_without_a_title_of_the_page_end_with_the_last_section(): void
    {
        $page = $this->page();
        $page->breadcrumbs = [['label' => 'Shop', 'url' => 'https://example.com/shop']];

        $this->assertSame([[
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Demo shop', 'item' => $this->home()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Shop', 'item' => 'https://example.com/shop'],
            ],
        ]], app(BreadcrumbSchema::class)->nodes($page));
    }

    public function test_a_record_ends_the_trail_with_its_own_name_not_the_meta_title(): void
    {
        $page = $this->page(Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']));
        $page->meta = new Meta(title: 'Buy a lamp | Demo shop');

        $this->assertSame([[
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Demo shop', 'item' => $this->home()],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Lamp', 'item' => 'https://example.com/shop/lamp'],
            ],
        ]], app(BreadcrumbSchema::class)->nodes($page));
    }

    public function test_the_home_page_of_the_trail_is_named_after_the_site_else_the_application(): void
    {
        $page = $this->page(null, SeoPage::INDEX);
        $page->meta = new Meta(title: 'Products');
        $name = static fn (array $nodes): mixed => $nodes[0]['itemListElement'][0]['name'];

        $site = app(Sites::class)->current();
        $site->name = 'Main site';
        $site->save();
        $this->assertSame('Main site', $name(app(BreadcrumbSchema::class)->nodes($page)));

        $site->name = '';
        $site->save();
        $this->assertSame('Demo shop', $name(app(BreadcrumbSchema::class)->nodes($page)));

        config(['app.name' => null]);
        $this->assertSame('', $name(app(BreadcrumbSchema::class)->nodes($page)));
    }

    // Organization and the site

    public function test_only_the_home_page_describes_the_organization(): void
    {
        $this->assertSame([], app(OrganizationSchema::class)->nodes($this->page()));
        $this->assertSame([], app(OrganizationSchema::class)->nodes($this->page(null, SeoPage::INDEX)));
    }

    public function test_the_home_page_of_a_shop_with_no_settings_has_a_bare_organization_and_site(): void
    {
        $this->assertSame([
            ['@type' => 'Organization', '@id' => $this->home().'#organization', 'name' => 'Demo shop', 'url' => $this->home()],
            ['@type' => 'WebSite', '@id' => $this->home().'#website', 'url' => $this->home(), 'name' => 'Demo shop', 'inLanguage' => 'en', 'publisher' => ['@id' => $this->home().'#organization']],
        ], app(OrganizationSchema::class)->nodes($this->page(null, SeoPage::HOME)));
    }

    public function test_the_settings_fill_the_organization_and_the_search_box(): void
    {
        Setting::save('seo', [
            'organization_name' => ' Demo Ltd ',
            'organization_type' => 'Store',
            'logo' => 'brand/logo.png',
            'phones' => " +1 555 0100 \n\n  \n+1 555 0101,+1 555 0102",
            'email' => ' hello@example.com ',
            'address' => ' 1 Main Street ',
            'same_as' => "https://social.example.com/demo\r\n \r\nhttps://video.example.com/demo",
            'search_url' => ' https://example.com/search?q={search_term_string} ',
        ]);
        app()->setLocale('pt_BR');

        $this->assertSame([
            [
                '@type' => 'Store',
                '@id' => $this->home().'#organization',
                'name' => 'Demo Ltd',
                'url' => $this->home(),
                'logo' => ['@type' => 'ImageObject', 'url' => $this->root().'/storage/brand/logo.png'],
                'telephone' => '+1 555 0100',
                'email' => 'hello@example.com',
                'address' => '1 Main Street',
                'sameAs' => ['https://social.example.com/demo', 'https://video.example.com/demo'],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $this->home().'#website',
                'url' => $this->home(),
                'name' => 'Demo shop',
                'inLanguage' => 'pt-BR',
                'publisher' => ['@id' => $this->home().'#organization'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => ['@type' => 'EntryPoint', 'urlTemplate' => 'https://example.com/search?q={search_term_string}'],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ], app(OrganizationSchema::class)->nodes($this->page(null, SeoPage::HOME)));
    }

    public function test_a_list_of_phones_and_addresses_from_the_settings_is_read_as_a_list(): void
    {
        Setting::save('seo', ['phones' => ['', ' +1 555 0100 ', '+1 555 0101'], 'same_as' => ['https://a.example.com', '  ', 'https://b.example.com']]);

        $organization = app(OrganizationSchema::class)->nodes($this->page(null, SeoPage::HOME))[0];

        $this->assertSame('+1 555 0100', $organization['telephone']);
        $this->assertSame(['https://a.example.com', 'https://b.example.com'], $organization['sameAs']);
    }

    public function test_blank_settings_say_nothing_and_a_search_address_needs_its_placeholder(): void
    {
        Setting::save('seo', ['organization_type' => '', 'email' => '   ', 'phones' => '  ', 'address' => '', 'search_url' => '   ']);
        $nodes = app(OrganizationSchema::class)->nodes($this->page(null, SeoPage::HOME));

        $this->assertSame(['@type', '@id', 'name', 'url'], array_keys($nodes[0]));
        $this->assertSame('Organization', $nodes[0]['@type']);
        $this->assertArrayNotHasKey('potentialAction', $nodes[1]);

        Setting::save('seo', ['organization_type' => 5, 'search_url' => 'https://example.com/search']);
        $nodes = app(OrganizationSchema::class)->nodes($this->page(null, SeoPage::HOME));

        $this->assertSame('Organization', $nodes[0]['@type']);
        $this->assertArrayNotHasKey('potentialAction', $nodes[1]);
    }

    public function test_the_site_is_named_after_the_site_else_the_application(): void
    {
        $name = fn (): mixed => app(OrganizationSchema::class)->nodes($this->page(null, SeoPage::HOME))[1]['name'] ?? null;

        $site = app(Sites::class)->current();
        $site->name = 'Main site';
        $site->save();
        $this->assertSame('Main site', $name());

        $site->name = '';
        $site->save();
        $this->assertSame('Demo shop', $name());

        config(['app.name' => null]);
        $this->assertNull($name());
    }

    // Product

    public function test_only_a_product_page_has_a_product(): void
    {
        $this->assertSame([], app(ProductSchema::class)->nodes($this->page()));
        $this->assertSame([], app(ProductSchema::class)->nodes($this->page(News::query()->create(['name' => ['en' => 'N'], 'code' => ['en' => 'n']]))));
    }

    public function test_a_product_with_a_price_has_an_offer(): void
    {
        Setting::save('seo', ['currency' => 'USD']);
        $product = new Product(['name' => 'Lamp', 'code' => 'lamp', 'price' => 25.5, 'in_stock' => 'Y', 'article' => 'L-1', 'preview_text' => 'A lamp.', 'preview_picture' => 'catalog/lamp.jpg']);

        $this->assertSame([[
            '@type' => 'Product',
            '@id' => 'https://example.com/shop/lamp#main',
            'name' => 'Lamp',
            'description' => 'A lamp.',
            'image' => [$this->root().'/storage/catalog/lamp.jpg'],
            'sku' => 'L-1',
            'url' => 'https://example.com/shop/lamp',
            'offers' => [
                '@type' => 'Offer',
                'price' => '25.50',
                'priceCurrency' => 'USD',
                'availability' => 'https://schema.org/InStock',
                'url' => 'https://example.com/shop/lamp',
            ],
        ]], app(ProductSchema::class)->nodes($this->page($product)));
    }

    public function test_a_product_without_a_price_or_an_article_says_neither(): void
    {
        $node = app(ProductSchema::class)->nodes($this->page(new Product(['name' => 'Lamp', 'code' => 'lamp', 'article' => ''])))[0];

        $this->assertSame(['@type', '@id', 'name', 'url'], array_keys($node));
    }

    public function test_a_price_of_zero_is_no_offer_and_the_smallest_one_is(): void
    {
        $offers = fn (mixed $price): mixed => app(ProductSchema::class)->nodes($this->page(new Product(['name' => 'Lamp', 'code' => 'lamp', 'price' => $price])))[0]['offers'] ?? null;

        $this->assertNull($offers(0));
        $this->assertNull($offers('0.00'));
        $this->assertNull($offers(null));
        $this->assertNull($offers(-5));
        $this->assertSame('0.01', $offers(0.01)['price']);
        $this->assertSame('1000.00', $offers(1000)['price']);
    }

    public function test_only_the_catalog_s_ticked_box_means_in_stock(): void
    {
        $availability = fn (mixed $stock): string => app(ProductSchema::class)->nodes($this->page(new Product(['name' => 'Lamp', 'code' => 'lamp', 'price' => 10, 'in_stock' => $stock])))[0]['offers']['availability'];

        foreach (['Y', true, 1, '1'] as $stock) {
            $this->assertSame('https://schema.org/InStock', $availability($stock), var_export($stock, true));
        }

        foreach ([false, 0, '0', 2, '', 'N', null] as $stock) {
            $this->assertSame('https://schema.org/OutOfStock', $availability($stock), var_export($stock, true));
        }
    }

    // News

    public function test_only_a_news_page_has_a_news_article(): void
    {
        $this->assertSame([], app(NewsArticleSchema::class)->nodes($this->page()));
        $this->assertSame([], app(NewsArticleSchema::class)->nodes($this->page(new Product(['name' => 'Lamp']))));
    }

    public function test_a_news_item_is_a_news_article_with_its_dates_and_publisher(): void
    {
        $news = new News(['name' => ['en' => 'We moved'], 'preview_text' => ['en' => 'To a bigger place.'], 'preview_picture' => 'news/moved.jpg']);
        $news->forceFill(['active_from' => Carbon::parse('2026-03-01 10:00:00', 'UTC'), 'created_at' => Carbon::parse('2026-02-01 08:00:00', 'UTC'), 'updated_at' => Carbon::parse('2026-03-05 12:30:00', 'UTC')]);
        $publisher = ['@type' => 'Organization', '@id' => $this->home().'#organization', 'name' => 'Demo shop', 'url' => $this->home()];

        $this->assertSame([[
            '@type' => 'NewsArticle',
            '@id' => 'https://example.com/shop/lamp#main',
            'headline' => 'We moved',
            'description' => 'To a bigger place.',
            'image' => [$this->root().'/storage/news/moved.jpg'],
            'datePublished' => '2026-03-01T10:00:00+00:00',
            'dateModified' => '2026-03-05T12:30:00+00:00',
            'inLanguage' => 'en',
            'mainEntityOfPage' => 'https://example.com/shop/lamp',
            'author' => ['@type' => 'Organization', 'name' => 'Demo shop', 'url' => $this->home()],
            'publisher' => $publisher,
        ]], app(NewsArticleSchema::class)->nodes($this->page($news)));
    }

    public function test_an_article_without_a_start_date_is_published_when_it_was_created_and_modified_then_too(): void
    {
        $news = new News(['name' => ['en' => 'We moved']]);
        $news->forceFill(['created_at' => Carbon::parse('2026-02-01 08:00:00', 'UTC')]);

        $node = app(NewsArticleSchema::class)->nodes($this->page($news))[0];

        $this->assertSame('2026-02-01T08:00:00+00:00', $node['datePublished']);
        $this->assertSame('2026-02-01T08:00:00+00:00', $node['dateModified']);

        $node = app(NewsArticleSchema::class)->nodes($this->page(new News(['name' => ['en' => 'We moved']])))[0];

        $this->assertArrayNotHasKey('datePublished', $node);
        $this->assertArrayNotHasKey('dateModified', $node);
    }

    public function test_a_headline_is_cut_at_110_characters_not_bytes(): void
    {
        $headline = fn (string $name): string => app(NewsArticleSchema::class)->nodes($this->page(new News(['name' => ['en' => $name]])))[0]['headline'];

        $this->assertSame(str_repeat('é', 109), $headline(str_repeat('é', 109)));
        $this->assertSame(str_repeat('é', 110), $headline(str_repeat('é', 110)));
        $this->assertSame(str_repeat('é', 110), $headline(str_repeat('é', 111)));
        $this->assertSame(str_repeat('é', 110), $headline(str_repeat('é', 300)));
    }

    public function test_an_article_names_its_language_the_web_way(): void
    {
        app()->setLocale('pt_BR');

        $this->assertSame('pt-BR', app(NewsArticleSchema::class)->nodes($this->page(new News(['name' => ['en' => 'We moved']])))[0]['inLanguage']);
    }

    // Stores

    public function test_only_a_store_page_has_a_local_business(): void
    {
        $this->assertSame([], app(LocalBusinessSchema::class)->nodes($this->page()));
        $this->assertSame([], app(LocalBusinessSchema::class)->nodes($this->page(new Product(['name' => 'Lamp']))));
    }

    public function test_a_store_is_a_local_business_with_its_address_phone_and_place(): void
    {
        $store = new Store(['name' => 'Central', 'code' => 'central', 'address' => ' 1 Main Street ', 'phones' => ['+1 555 0100', '+1 555 0101'], 'email' => 'central@example.com', 'location' => ['lat' => '52.52', 'lng' => '13.405'], 'detail_text' => 'Open daily.', 'preview_picture' => 'stores/central.jpg']);

        $this->assertSame([[
            '@type' => 'LocalBusiness',
            '@id' => 'https://example.com/shop/lamp#main',
            'name' => 'Central',
            'description' => 'Open daily.',
            'image' => [$this->root().'/storage/stores/central.jpg'],
            'url' => 'https://example.com/shop/lamp',
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => '1 Main Street'],
            'telephone' => '+1 555 0100',
            'email' => 'central@example.com',
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => 52.52, 'longitude' => 13.405],
            'parentOrganization' => ['@id' => $this->home().'#organization'],
        ]], app(LocalBusinessSchema::class)->nodes($this->page($store)));
    }

    public function test_a_bare_store_says_only_what_it_knows(): void
    {
        $node = app(LocalBusinessSchema::class)->nodes($this->page(new Store(['name' => 'Central', 'code' => 'central', 'address' => '', 'phones' => [], 'email' => ''])))[0];

        $this->assertSame(['@type', '@id', 'name', 'url', 'parentOrganization'], array_keys($node));
    }

    public function test_the_first_phone_that_is_text_is_the_telephone(): void
    {
        $telephone = fn (array $phones): mixed => app(LocalBusinessSchema::class)->nodes($this->page(new Store(['name' => 'Central', 'phones' => $phones])))[0]['telephone'] ?? null;

        $this->assertSame('+1 555 0101', $telephone([5, null, '+1 555 0101']));
        $this->assertNull($telephone([5, null]));
    }

    public function test_a_point_on_the_map_may_be_written_three_ways_and_needs_both_numbers(): void
    {
        $geo = fn (mixed $location): mixed => app(LocalBusinessSchema::class)->nodes($this->page(new Store(['name' => 'Central', 'location' => $location])))[0]['geo'] ?? null;
        $point = static fn (float $lat, float $lng): array => ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng];

        $this->assertSame($point(1.5, 2.5), $geo(['lat' => 1.5, 'lng' => 2.5]));
        $this->assertSame($point(1.5, 2.5), $geo(['latitude' => 1.5, 'longitude' => 2.5]));
        $this->assertSame($point(1.5, 2.5), $geo(['lat' => 1.5, 'lon' => 2.5]));
        $this->assertSame($point(1.0, 2.0), $geo(['lat' => 1, 'lng' => '2']));
        $this->assertSame($point(1.0, 0.0), $geo(['lat' => 1, 'lng' => 0]));
        $this->assertSame($point(1.0, 2.0), $geo(['lat' => 1, 'latitude' => 9, 'lng' => 2, 'lon' => 8, 'longitude' => 7]));
        $this->assertSame($point(1.0, 2.0), $geo(['lat' => 1, 'lon' => 2, 'longitude' => 7]));
        $this->assertSame($point(1.0, 7.0), $geo(['latitude' => 1, 'longitude' => 7]));
        $this->assertNull($geo(['lat' => 1.5]));
        $this->assertNull($geo(['lng' => 2.5]));
        $this->assertNull($geo(['lat' => 'north', 'lng' => 2.5]));
        $this->assertNull($geo(['lat' => 1.5, 'lng' => 'east']));
        $this->assertNull($geo(['lat' => null, 'lng' => null]));
        $this->assertNull($geo(null));
        $this->assertNull($geo([]));
    }

    // The FAQ

    private function faq(): array
    {
        $delivery = Category::query()->create(['name' => ['en' => 'Delivery'], 'code' => ['en' => 'delivery']]);
        $returns = Category::query()->create(['name' => ['en' => 'Returns'], 'code' => ['en' => 'returns']]);
        Category::query()->create(['name' => ['en' => 'Empty'], 'code' => ['en' => 'empty']]);
        Question::query()->create(['category_id' => $delivery->id, 'question' => ['en' => 'How long?'], 'answer' => ['en' => '<p>Two days.</p>']]);
        Question::query()->create(['category_id' => $returns->id, 'question' => ['en' => 'Can I return it?'], 'answer' => ['en' => 'Within 14 days.']]);

        return [$delivery, $returns, Category::query()->where('code->en', 'empty')->sole()];
    }

    private function question(string $name, string $text): array
    {
        return ['@type' => 'Question', 'name' => $name, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $text]];
    }

    public function test_a_category_page_says_the_questions_of_that_category(): void
    {
        [$delivery, $returns] = $this->faq();

        $this->assertSame([[
            '@id' => 'https://example.com/shop/lamp#faq',
            '@type' => 'FAQPage',
            'mainEntity' => [$this->question('How long?', '<p>Two days.</p>')],
        ]], app(FaqSchema::class)->nodes($this->page($delivery)));
        $this->assertSame('Can I return it?', app(FaqSchema::class)->nodes($this->page($returns))[0]['mainEntity'][0]['name']);
    }

    public function test_the_questions_the_faq_drew_win_over_the_category_s_own(): void
    {
        [$delivery, $returns] = $this->faq();
        $page = $this->page($delivery);
        $page->context['faq.questions'] = app(Faq::class)->questions([$returns]);

        $this->assertSame(
            [$this->question('Can I return it?', 'Within 14 days.')],
            app(FaqSchema::class)->nodes($page)[0]['mainEntity'],
        );
    }

    public function test_the_questions_the_faq_drew_on_a_page_that_is_not_a_category_are_said_too(): void
    {
        $this->faq();
        $page = $this->page();
        $page->context['faq.questions'] = app(Faq::class)->questions();

        $this->assertSame(
            [$this->question('How long?', '<p>Two days.</p>'), $this->question('Can I return it?', 'Within 14 days.')],
            app(FaqSchema::class)->nodes($page)[0]['mainEntity'],
        );
    }

    public function test_a_page_with_no_questions_has_no_faq(): void
    {
        [, , $empty] = $this->faq();
        $page = $this->page($empty);
        $this->assertSame([], app(FaqSchema::class)->nodes($page));

        $this->assertSame([], app(FaqSchema::class)->nodes($this->page()));
        $this->assertSame([], app(FaqSchema::class)->nodes($this->page(new Product(['name' => 'Lamp']))));

        $page = $this->page();
        $page->context['faq.questions'] = 'not questions';
        $this->assertSame([], app(FaqSchema::class)->nodes($page));

        $page->context['faq.questions'] = [];
        $this->assertSame([], app(FaqSchema::class)->nodes($page));
    }

    // Reviews

    private function reviews(Product $product, int $count): void
    {
        foreach (range(1, $count) as $number) {
            Review::query()->create([
                'reviewable_type' => $product->getMorphClass(),
                'reviewable_id' => $product->id,
                'author_name' => 'Author '.$number,
                'rating' => 5,
                'text' => 'Review '.$number,
                'status' => 'published',
                'posted_at' => now()->subMinutes(100 - $number),
            ]);
        }
    }

    public function test_the_newest_five_reviews_and_the_rating_of_all_go_into_the_product_node(): void
    {
        $product = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);
        $this->reviews($product, 7);
        $node = ['@type' => 'Product', '@id' => 'https://example.com/shop/lamp#main', 'name' => 'Our own name'];

        $graph = app(ReviewSchema::class)->enrich([['@type' => 'WebPage', '@id' => 'https://example.com/#page'], $node], $this->page($product));

        $this->assertCount(2, $graph);
        $this->assertSame(['@type' => 'WebPage', '@id' => 'https://example.com/#page'], $graph[0]);
        $this->assertSame(['@type', '@id', 'name', 'aggregateRating', 'review'], array_keys($graph[1]));
        $this->assertSame('Our own name', $graph[1]['name']);
        $this->assertSame(['@type' => 'AggregateRating', 'ratingValue' => 5.0, 'reviewCount' => 7, 'bestRating' => 5, 'worstRating' => 1], $graph[1]['aggregateRating']);
        $this->assertSame(['Author 7', 'Author 6', 'Author 5', 'Author 4', 'Author 3'], array_column(array_column($graph[1]['review'], 'author'), 'name'));
    }

    public function test_a_page_without_a_node_of_its_own_gets_one_from_the_reviews(): void
    {
        $product = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);
        $this->reviews($product, 1);

        $graph = app(ReviewSchema::class)->enrich([['@type' => 'WebPage', '@id' => 'https://example.com/#page']], $this->page($product));

        $this->assertCount(2, $graph);
        $this->assertSame(['@id', '@type', 'name', 'url', 'aggregateRating', 'review'], array_keys($graph[1]));
        $this->assertSame('https://example.com/shop/lamp#main', $graph[1]['@id']);
        $this->assertSame('Product', $graph[1]['@type']);
        $this->assertSame('Lamp', $graph[1]['name']);
        $this->assertSame('https://example.com/shop/lamp', $graph[1]['url']);
    }

    public function test_nothing_is_added_without_a_record_a_rating_or_a_model_that_takes_reviews(): void
    {
        $graph = [['@type' => 'WebPage', '@id' => 'https://example.com/#page']];
        $product = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);
        $news = News::query()->create(['name' => ['en' => 'We moved'], 'code' => ['en' => 'we-moved']]);
        Review::query()->create(['reviewable_type' => $news->getMorphClass(), 'reviewable_id' => $news->id, 'author_name' => 'Ann', 'rating' => 5, 'text' => 'Nice', 'status' => 'published', 'posted_at' => now()]);

        $this->assertSame($graph, app(ReviewSchema::class)->enrich($graph, $this->page()));
        $this->assertSame($graph, app(ReviewSchema::class)->enrich($graph, $this->page($product)));
        $this->assertSame($graph, app(ReviewSchema::class)->enrich($graph, $this->page($news)));
    }
}
