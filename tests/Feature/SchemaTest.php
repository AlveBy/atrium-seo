<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Facades\Setting;
use Atrium\Catalog\Models\CatalogSection;
use Atrium\Catalog\Models\Product;
use Atrium\Faq\Models\Category;
use Atrium\Faq\Models\Question;
use Atrium\News\Models\News;
use Atrium\Reviews\Models\Review;
use Atrium\Reviews\ReviewStatus;
use Atrium\Seo\Schema\ProductSchema;
use Atrium\Seo\Schema\Validator;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Tests\Fixtures\BrokenSchema;
use Atrium\Seo\Tests\Fixtures\EventSchema;
use Atrium\Seo\Tests\TestCase;
use Atrium\Stores\Models\Store;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

/**
 * One JSON-LD graph per page, from the providers of every module, valid
 * against the schema.org types.
 */
final class SchemaTest extends TestCase
{
    public function test_the_home_page_describes_the_organization_and_the_site_with_its_search(): void
    {
        Setting::save('seo', [
            'organization_name' => 'Demo Shop Ltd',
            'organization_type' => 'Store',
            'logo' => 'brand/logo.png',
            'phones' => "+1 555 0100\n+1 555 0101",
            'email' => 'hello@example.com',
            'address' => '1 Main Street, Springfield',
            'same_as' => "https://social.example.com/demo\nhttps://video.example.com/demo",
            'search_url' => 'https://example.com/search?q={search_term_string}',
        ]);

        $graph = $this->valid('/');
        $organization = $this->nodes($graph, 'Store')[0];
        $website = $this->nodes($graph, 'WebSite')[0];

        $this->assertSame($this->root().'/#organization', $organization['@id']);
        $this->assertSame('Demo Shop Ltd', $organization['name']);
        $this->assertSame(['@type' => 'ImageObject', 'url' => $this->root().'/storage/brand/logo.png'], $organization['logo']);
        $this->assertSame('+1 555 0100', $organization['telephone']);
        $this->assertSame(['https://social.example.com/demo', 'https://video.example.com/demo'], $organization['sameAs']);
        $this->assertSame(['@id' => $organization['@id']], $website['publisher']);
        $this->assertSame('https://example.com/search?q={search_term_string}', $website['potentialAction']['target']['urlTemplate']);
        $this->assertSame('required name=search_term_string', $website['potentialAction']['query-input']);
    }

    public function test_a_search_address_without_the_placeholder_gives_no_search_box(): void
    {
        Setting::save('seo', ['search_url' => 'https://example.com/search']);

        $graph = $this->valid('/');

        $this->assertArrayNotHasKey('potentialAction', $this->nodes($graph, 'WebSite')[0]);
        $this->assertSame('Demo shop', $this->nodes($graph, 'Organization')[0]['name']);
    }

    public function test_other_pages_do_not_repeat_the_organization(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $graph = $this->valid('/catalog/mouse');

        $this->assertSame([], $this->nodes($graph, 'WebSite'));
        $this->assertSame([], $this->nodes($graph, 'Organization'));
    }

    public function test_breadcrumbs_lead_from_the_home_page_to_the_page(): void
    {
        $laptops = CatalogSection::query()->create(['name' => 'Laptops', 'code' => 'laptops']);
        Product::query()->create(['section_id' => $laptops->id, 'name' => 'Laptop Pro', 'code' => 'laptop-pro']);

        $crumbs = $this->nodes($this->valid('/catalog/laptops/laptop-pro'), 'BreadcrumbList')[0]['itemListElement'];

        $this->assertSame([1, 2, 3, 4], array_column($crumbs, 'position'));
        $this->assertSame(['Demo shop', 'Products', 'Laptops', 'Laptop Pro'], array_column($crumbs, 'name'));
        $this->assertSame($this->root().'/catalog/laptops/laptop-pro', $crumbs[3]['item']);
    }

    public function test_a_product_has_its_offer(): void
    {
        Setting::save('seo', ['currency' => 'USD']);
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse', 'price' => 25.5, 'in_stock' => 'Y', 'article' => 'MS-1', 'preview_picture' => 'catalog/mouse.jpg']);
        Product::query()->create(['name' => 'Gone', 'code' => 'gone', 'price' => 10]);
        Product::query()->create(['name' => 'Free', 'code' => 'free']);

        $product = $this->nodes($this->valid('/catalog/mouse'), 'Product')[0];

        $this->assertSame($this->root().'/catalog/mouse#main', $product['@id']);
        $this->assertSame(['@type' => 'Offer', 'price' => '25.50', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock', 'url' => $this->root().'/catalog/mouse'], $product['offers']);
        $this->assertSame([$this->root().'/storage/catalog/mouse.jpg'], $product['image']);

        $this->assertSame('https://schema.org/OutOfStock', $this->nodes($this->valid('/catalog/gone'), 'Product')[0]['offers']['availability']);
        $this->assertArrayNotHasKey('offers', $this->nodes($this->valid('/catalog/free'), 'Product')[0]);
    }

    public function test_a_news_item_is_a_news_article(): void
    {
        News::query()->create(['name' => ['en' => 'We moved'], 'code' => ['en' => 'we-moved'], 'active_from' => '2026-03-01 10:00:00', 'preview_text' => ['en' => '<p>To a bigger place.</p>']]);

        $article = $this->nodes($this->valid('/news/we-moved'), 'NewsArticle')[0];

        $this->assertSame('We moved', $article['headline']);
        $this->assertSame('To a bigger place.', $article['description']);
        $this->assertStringStartsWith('2026-03-01T10:00:00', $article['datePublished']);
        $this->assertSame($this->root().'/news/we-moved', $article['mainEntityOfPage']);
        $this->assertSame('Organization', $article['publisher']['@type']);
    }

    public function test_the_faq_says_its_questions_once_in_the_graph(): void
    {
        $category = Category::query()->create(['name' => ['en' => 'Delivery'], 'code' => ['en' => 'delivery']]);
        Question::query()->create(['category_id' => $category->id, 'question' => ['en' => 'How long?'], 'answer' => ['en' => '<p>Two days.</p>']]);
        Category::query()->create(['name' => ['en' => 'Empty'], 'code' => ['en' => 'empty']]);

        foreach (['/faq', '/faq/delivery'] as $url) {
            $faq = $this->nodes($this->valid($url), 'FAQPage');
            $this->assertCount(1, $faq, $url);
            $this->assertSame('How long?', $faq[0]['mainEntity'][0]['name']);
            $this->assertSame('<p>Two days.</p>', $faq[0]['mainEntity'][0]['acceptedAnswer']['text']);
        }

        $this->assertSame([], $this->nodes($this->valid('/faq/empty'), 'FAQPage'));
    }

    public function test_reviews_go_into_the_product_node(): void
    {
        $product = Product::query()->create(['name' => 'Mouse', 'code' => 'mouse', 'price' => 10]);
        $this->review($product, 5, 'Ann', 'Great.');
        $this->review($product, 4, 'Bob', 'Good.');
        $this->review($product, 1, 'Eve', 'Spam', ReviewStatus::Rejected);
        Product::query()->create(['name' => 'Keyboard', 'code' => 'keyboard']);

        $graph = $this->valid('/catalog/mouse');
        $product = $this->nodes($graph, 'Product')[0];

        $this->assertCount(1, $this->nodes($graph, 'Product'));
        $this->assertSame(['@type' => 'AggregateRating', 'ratingValue' => 4.5, 'reviewCount' => 2, 'bestRating' => 5, 'worstRating' => 1], $product['aggregateRating']);
        $this->assertSame(['Ann', 'Bob'], array_column(array_column($product['review'], 'author'), 'name'));

        $this->assertArrayNotHasKey('aggregateRating', $this->nodes($this->valid('/catalog/keyboard'), 'Product')[0]);
    }

    public function test_a_page_the_application_draws_itself_says_which_record_it_shows(): void
    {
        $mouse = Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        $lamp = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);
        $this->review($mouse, 5, 'Ann', 'Great.');
        $this->review($lamp, 4, 'Bob', 'Good.');
        Route::middleware('web')->get('/compare', static function () use ($mouse, $lamp): string {
            app(SeoPage::class)->show($mouse);

            return view('atrium-reviews::block', ['record' => $mouse])->render()
                .view('atrium-reviews::block', ['record' => $lamp])->render();
        });

        // The rating of the record the page shows goes into the page's
        // graph; the block of another record keeps its own markup.
        $html = (string) $this->get('/compare')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'application/ld+json'));
        $this->assertStringContainsString('Bob', $html);

        $page = new SeoPage;
        $this->assertFalse($page->shows(Product::class));
        $this->assertSame(SeoPage::RECORD, $page->show($mouse)->kind);
        $this->assertTrue($page->shows(Product::class));
        $this->assertFalse($page->shows(News::class));
        $this->assertSame(SeoPage::INDEX, $page->kind(SeoPage::INDEX)->kind);
        $this->assertTrue($page->filled);
    }

    public function test_reviews_of_a_page_without_its_own_node_get_one(): void
    {
        $this->app['config']->set('atrium-seo.schema', array_values(array_diff(config('atrium-seo.schema'), [ProductSchema::class])));
        $product = Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        $this->review($product, 3, 'Ann', 'Fine.');

        $node = $this->nodes($this->valid('/catalog/mouse'), 'Product')[0];

        $this->assertSame($this->root().'/catalog/mouse#main', $node['@id']);
        $this->assertSame(3, (int) $node['aggregateRating']['ratingValue']);
    }

    public function test_a_store_is_a_local_business(): void
    {
        Store::query()->create(['name' => 'Central', 'code' => 'central', 'address' => '1 Main Street', 'phones' => ['+1 555 0100'], 'email' => 'central@example.com', 'location' => ['lat' => 52.52, 'lng' => 13.405]]);

        $store = $this->nodes($this->valid('/stores/central'), 'LocalBusiness')[0];

        $this->assertSame(['@type' => 'PostalAddress', 'streetAddress' => '1 Main Street'], $store['address']);
        $this->assertSame(['@type' => 'GeoCoordinates', 'latitude' => 52.52, 'longitude' => 13.405], $store['geo']);
        $this->assertSame('+1 555 0100', $store['telephone']);
        $this->assertSame(['@id' => $this->root().'/#organization'], $store['parentOrganization']);
    }

    public function test_modules_add_providers_from_config_and_from_a_hook(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        config()->push('atrium-seo.schema', EventSchema::class);

        $graph = $this->valid('/catalog/mouse');
        // Same @id as the product: one node with both types' properties.
        $main = array_values(array_filter($graph, fn (array $node): bool => ($node['@id'] ?? null) === $this->root().'/catalog/mouse#main'));
        $this->assertCount(1, $main);
        $this->assertSame('Event', $main[0]['@type']);
        $this->assertSame('Launch party', $main[0]['name']);

        $this->app['config']->set('atrium-seo.schema', []);
        Hook::filter('atrium.seo.schema', static fn (array $classes): array => [...$classes, EventSchema::class, 'App\\Missing']);

        $graph = $this->valid('/catalog/mouse');
        $this->assertCount(1, $this->nodes($graph, 'Event'));
        $this->assertSame([], $this->nodes($graph, 'Product'));
    }

    public function test_a_view_adds_a_node_of_its_own(): void
    {
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
        View::composer('atrium-theme::page', static function (): void {
            app(SeoPage::class)->addSchema(['@type' => 'ImageObject', 'contentUrl' => 'https://example.com/mouse.jpg']);
        });

        $this->assertCount(1, $this->nodes($this->valid('/catalog/mouse'), 'ImageObject'));
    }

    public function test_a_broken_provider_takes_only_its_own_markup_down(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\RuntimeException $e) use (&$reported): bool {
            $reported[] = $e->getMessage();

            return false;
        });
        config()->push('atrium-seo.schema', BrokenSchema::class);
        Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);

        $this->assertCount(1, $this->nodes($this->valid('/catalog/mouse'), 'Product'));
        $this->assertSame(['Provider bug', 'Enricher bug'], $reported);
    }

    public function test_no_text_can_close_the_script_tag(): void
    {
        Product::query()->create(['name' => 'Mouse < Keyboard', 'code' => 'mouse', 'preview_text' => 'a </script><script>alert(1)</script> b']);

        $html = (string) $this->get('/catalog/mouse')->assertOk()->getContent();
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);

        $this->assertStringNotContainsString('<', $match[1]);
        $this->assertSame('Mouse < Keyboard', $this->nodes($this->graph($html), 'Product')[0]['name']);
    }

    public function test_a_page_without_nodes_has_no_script(): void
    {
        $this->app['config']->set('atrium-seo.schema', []);

        $this->get('/')->assertOk()->assertDontSee('application/ld+json', false);
    }

    /**
     * The page's graph after checking it against schema.org.
     *
     * @return list<array<string,mixed>>
     */
    private function valid(string $url): array
    {
        $html = (string) $this->get($url)->assertOk()->getContent();
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);
        $this->assertSame([], Validator::errors($match[1] ?? ''), $url);

        return $this->graph($html);
    }

    private function review(Product $product, int $rating, string $author, string $text, ReviewStatus $status = ReviewStatus::Published): Review
    {
        return Review::query()->create([
            'reviewable_type' => $product->getMorphClass(),
            'reviewable_id' => $product->id,
            'author_name' => $author,
            'rating' => $rating,
            'text' => $text,
            'status' => $status->value,
            'posted_at' => now()->subMinutes(10 - $rating),
        ]);
    }
}
