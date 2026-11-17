<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Catalog\Models\CatalogSection;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Meta\Templates;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Robots;
use Atrium\Seo\Tests\TestCase;
use Atrium\Stores\Models\Store;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * The title, description and keywords of a page: SEO → Pages for the
 * address, else the record's SEO tab, else its module's template, else
 * the module's kind's default in the page's language.
 */
final class MetaTest extends TestCase
{
    public function test_a_product_takes_the_default_of_its_kind(): void
    {
        $laptops = CatalogSection::query()->create(['name' => 'Laptops', 'code' => 'laptops']);
        Product::query()->create(['section_id' => $laptops->id, 'name' => 'Laptop Pro', 'code' => 'laptop-pro', 'price' => 999, 'preview_text' => '<p>Light and <b>fast</b>.</p>']);

        $this->get('/catalog/laptops/laptop-pro')
            ->assertOk()
            ->assertSee('<title>Laptop Pro — €999.00 | Demo shop</title>', false)
            ->assertSee('<meta name="description" content="Buy Laptop Pro for €999.00. Light and fast.">', false)
            ->assertSee('<meta property="og:title" content="Laptop Pro — €999.00 | Demo shop">', false);
    }

    public function test_a_part_in_brackets_goes_when_its_variable_is_empty(): void
    {
        Product::query()->create(['name' => 'Gift card', 'code' => 'gift-card']);

        $this->get('/catalog/gift-card')
            ->assertOk()
            ->assertSee('<title>Gift card | Demo shop</title>', false)
            ->assertSee('<meta name="description" content="Buy Gift card.">', false)
            ->assertDontSee('Gift card for');
    }

    #[DefineEnvironment('withTitleSuffix')]
    public function test_the_core_title_suffix_is_not_added_twice(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $this->get('/catalog/mouse')->assertOk()->assertSee('<title>Mouse | Demo shop</title>', false);
    }

    public function test_a_template_of_the_module_beats_the_default_and_one_for_all_modules(): void
    {
        Template::query()->create(['model' => Templates::ANY, 'title' => ['en' => 'Any: {name}']]);
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse', 'price' => 10]);
        News::query()->create(['name' => ['en' => 'We moved'], 'code' => ['en' => 'we-moved'], 'active_from' => '2026-03-01 10:00:00']);

        $this->get('/catalog/mouse')->assertOk()->assertSee('<title>Any: Mouse</title>', false);
        $this->get('/news/we-moved')->assertOk()->assertSee('<title>Any: We moved</title>', false);

        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{name} at {price}'], 'description' => ['en' => 'Order {name} today']]);

        $this->get('/catalog/mouse')
            ->assertOk()
            ->assertSee('<title>Mouse at €10.00</title>', false)
            ->assertSee('<meta name="description" content="Order Mouse today">', false);
        $this->get('/news/we-moved')->assertOk()->assertSee('<title>Any: We moved</title>', false);
    }

    public function test_a_template_without_a_text_in_the_language_falls_back_to_the_default(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        Template::query()->create(['model' => News::class, 'title' => ['en' => 'News: {name}']]);
        News::query()->create(['name' => ['en' => 'We moved', 'de' => 'Wir sind umgezogen'], 'code' => ['en' => 'we-moved', 'de' => 'umgezogen'], 'preview_text' => ['de' => 'Neue Adresse'], 'active_from' => '2026-03-01 10:00:00']);

        $this->get('/news/we-moved')->assertOk()->assertSee('<title>News: We moved</title>', false);
        $this->get('/de/news/umgezogen')
            ->assertOk()
            ->assertSee('<title>Wir sind umgezogen | Demo shop</title>', false)
            ->assertSee('<meta name="description" content="1. März 2026. Neue Adresse">', false);
    }

    public function test_a_variable_outside_the_brackets_is_required(): void
    {
        // A date alone is not a description; a nameless record has no title.
        News::query()->create(['name' => ['en' => 'We moved'], 'code' => ['en' => 'we-moved'], 'active_from' => '2026-03-01 10:00:00']);
        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);

        $this->get('/news/we-moved')->assertOk()->assertDontSee('<meta name="description"', false);
        // "Buy {name}[ for {price}].[ {excerpt}]": the text is optional.
        $this->get('/catalog/lamp')->assertOk()->assertSee('<meta name="description" content="Buy Lamp.">', false);
    }

    public function test_the_defaults_are_written_in_the_page_language(): void
    {
        Language::query()->create(['code' => 'ru', 'name' => 'Русский']);
        Language::query()->create(['code' => 'ja', 'name' => '日本語']);
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse', 'price' => 10]);

        $this->get('/ru/catalog/mouse')->assertOk()->assertSee('content="Купить Mouse за 10,00', false);
        $this->get('/ja/catalog/mouse')->assertOk()->assertSee('content="Mouse を購入', false);
    }

    public function test_any_attribute_is_a_variable(): void
    {
        Store::query()->create(['name' => 'Central', 'code' => 'central', 'address' => '1 Main Street']);
        Store::query()->create(['name' => 'Kiosk', 'code' => 'kiosk']);

        $this->get('/stores/central')->assertOk()->assertSee('<title>Central, 1 Main Street | Demo shop</title>', false);
        $this->get('/stores/kiosk')->assertOk()->assertSee('<title>Kiosk | Demo shop</title>', false);
    }

    #[DefineEnvironment('withConfiguredTemplate')]
    public function test_a_template_in_the_app_config_beats_the_default_but_not_the_panel(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $this->get('/catalog/mouse')->assertOk()->assertSee('<title>Shop: Mouse</title>', false);

        Template::query()->create(['model' => Product::class, 'title' => ['en' => 'Panel: {name}']]);

        $this->get('/catalog/mouse')->assertOk()->assertSee('<title>Panel: Mouse</title>', false);
    }

    public function test_the_section_and_typed_attributes_fill_a_template(): void
    {
        $laptops = CatalogSection::query()->create(['name' => 'Laptops', 'code' => 'laptops']);
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{name} in {section}'], 'description' => ['en' => '{colors}, out on {released_on}']]);
        Product::query()->create(['section_id' => $laptops->id, 'name' => 'Laptop Pro', 'code' => 'laptop-pro', 'colors' => [['x'], 'Silver', 'Black'], 'released_on' => '2026-03-01']);
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $this->get('/catalog/laptops/laptop-pro')
            ->assertOk()
            ->assertSee('<title>Laptop Pro in Laptops</title>', false)
            ->assertSee('<meta name="description" content="Silver, out on March 1, 2026">', false);
        // Without a section or colours the template is empty: the name is left.
        $this->get('/catalog/mouse')
            ->assertOk()
            ->assertSee('<title>Mouse</title>', false)
            ->assertDontSee('<meta name="description"', false);
    }

    public function test_a_translated_attribute_and_the_date_fill_a_template(): void
    {
        Template::query()->create(['model' => News::class, 'title' => ['en' => '{name}[, {date}]'], 'description' => ['en' => '{detail_text}']]);
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{name}[, {date}]']]);
        News::query()->create(['name' => ['en' => 'We moved'], 'code' => ['en' => 'we-moved'], 'active_from' => '2026-03-01 10:00:00', 'detail_text' => ['en' => '<p>To a <b>bigger</b> office.</p>']]);
        Product::withoutTimestamps(fn () => Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']));

        $this->get('/news/we-moved')
            ->assertOk()
            ->assertSee('<title>We moved, March 1, 2026</title>', false)
            ->assertSee('<meta name="description" content="To a bigger office.">', false);
        // No publication, activity or creation date: the part goes.
        $this->get('/catalog/mouse')->assertOk()->assertSee('<title>Mouse</title>', false);
    }

    public function test_the_seo_tab_of_a_record_beats_the_template(): void
    {
        $product = Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        $product->seoMeta()->create(['title' => 'The best {name}', 'description' => 'Hand made', 'keywords' => 'mouse, wireless']);

        $this->get('/catalog/mouse')
            ->assertOk()
            ->assertSee('<title>The best Mouse</title>', false)
            ->assertSee('<meta name="description" content="Hand made">', false)
            ->assertSee('<meta name="keywords" content="mouse, wireless">', false);
    }

    public function test_the_address_meta_beats_everything(): void
    {
        $product = Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        $product->seoMeta()->create(['title' => 'From the record']);
        PageMeta::query()->create(['path' => 'http://localhost/catalog/mouse/', 'title' => ['en' => 'From the address | {site}'], 'robots' => Robots::NOINDEX]);

        $this->get('/catalog/mouse')
            ->assertOk()
            ->assertSee('<title>From the address | Demo shop</title>', false)
            ->assertSee('<meta name="robots" content="noindex, follow">', false);

        $this->assertSame('/catalog/mouse', PageMeta::query()->first()?->path);
    }

    public function test_the_address_meta_covers_pages_without_a_record(): void
    {
        PageMeta::query()->create(['path' => '/', 'title' => ['en' => 'Welcome'], 'description' => ['en' => 'All about us'], 'canonical_url' => 'https://example.com/']);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Welcome</title>', false)
            ->assertSee('<meta name="description" content="All about us">', false)
            ->assertSee('<link rel="canonical" href="https://example.com/">', false);
    }

    public function test_the_address_meta_of_another_site_does_not_apply(): void
    {
        $other = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        PageMeta::query()->create(['site_id' => $other->id, 'path' => '/', 'title' => ['en' => 'Second home']]);

        $this->get('/')->assertOk()->assertDontSee('Second home');
        $this->get('/site2')->assertOk()->assertSee('<title>Second home</title>', false);
    }

    public function test_the_home_page_and_a_list_take_the_site_name(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $this->get('/')->assertOk()->assertSee('<title>Demo shop</title>', false);
        $this->get('/catalog')->assertOk()->assertSee('<title>Products | Demo shop</title>', false);
    }

    public function test_a_later_page_of_a_list_says_its_number_and_is_canonical_to_itself(): void
    {
        config(['atrium-theme.per_page' => 1]);
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        Product::query()->create(['name' => 'Keyboard', 'code' => 'keyboard']);

        $this->get('/catalog?page=2')
            ->assertOk()
            ->assertSee('<title>Products | Demo shop — page 2</title>', false)
            ->assertSee('<link rel="canonical" href="'.$this->root().'/catalog?page=2">', false);

        $this->get('/catalog')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.$this->root().'/catalog">', false)
            ->assertDontSee('page 1');
    }

    public function test_the_open_graph_image_falls_back_to_the_site_setting(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'preview_picture' => 'catalog/lamp.jpg']);

        $this->get('/catalog/mouse')->assertOk()->assertDontSee('og:image', false);

        Setting::save('seo', ['og_image' => 'brand/share.png']);

        $this->get('/catalog/mouse')->assertOk()->assertSee('<meta property="og:image" content="'.$this->root().'/storage/brand/share.png">', false);
        $this->get('/catalog/lamp')->assertOk()->assertSee('<meta property="og:image" content="'.$this->root().'/storage/catalog/lamp.jpg">', false);
        $this->get('/')->assertOk()->assertSee('/storage/brand/share.png', false);
    }

    public function test_a_record_closed_on_its_seo_tab_says_so_and_has_no_hreflang(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $product = Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        $product->seoMeta()->create(['robots' => Robots::NONE]);

        $html = (string) $this->get('/catalog/mouse')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertSame([], $this->hreflangs($html));
    }

    public function test_an_open_page_has_no_robots_meta(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $this->get('/catalog/mouse')->assertOk()->assertDontSee('name="robots"', false);
    }

    protected function withTitleSuffix(Application $app): void
    {
        $app['config']->set('atrium.seo.title_suffix', ' | Demo shop');
    }

    protected function withConfiguredTemplate(Application $app): void
    {
        $app['config']->set('atrium.seo.templates.'.Product::class.'.title', 'Shop: {name}');
    }
}
