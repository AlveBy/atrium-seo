<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Hook;
use Atrium\Catalog\Models\Product;
use Atrium\Faq\Models\Category;
use Atrium\Faq\Models\Question;
use Atrium\Seo\Schema\FaqSchema;
use Atrium\Seo\Schema\ReviewSchema;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Tests\TestCase;

/**
 * Only the catalog is installed: the FAQ, the reviews and the SEO module
 * itself are installed in the app's code but not switched on.
 */
final class ModulesOffTest extends TestCase
{
    /** @var list<string> */
    protected array $modules = ['alveby/atrium-catalog'];

    public function test_the_faq_says_nothing_to_search_engines_while_the_module_is_off(): void
    {
        $category = new Category(['name' => ['en' => 'Delivery'], 'code' => ['en' => 'delivery']]);
        $category->id = 1;
        $page = new SeoPage;
        $page->url = 'https://example.com/faq';
        $page->context['faq.questions'] = [new Question(['question' => ['en' => 'How long?'], 'answer' => ['en' => 'Two days.']])];

        $this->assertSame([], app(FaqSchema::class)->nodes($page));

        $page->show($category);
        unset($page->context['faq.questions']);
        $this->assertSame([], app(FaqSchema::class)->nodes($page));
    }

    public function test_the_rating_stays_out_of_the_graph_while_the_reviews_module_is_off(): void
    {
        $product = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'active' => true]);
        $page = new SeoPage;
        $page->url = 'https://example.com/shop/lamp';
        $page->show($product);
        $graph = [['@id' => $page->mainEntityId(), '@type' => 'Product', 'name' => 'Lamp']];

        $this->assertSame($graph, app(ReviewSchema::class)->enrich($graph, $page));
    }

    public function test_the_module_does_nothing_to_the_site_until_it_is_installed(): void
    {
        $this->assertNotSame('', config('atrium.seo.title_suffix'));
        $this->assertSame('<!-- x -->', Hook::apply('atrium.head', '<!-- x -->'));
        $this->assertSame([], Hook::apply('atrium.seo.fields', []));
        $this->assertSame([['route' => 'admin.seo_pages.index']], Hook::apply('atrium.admin.menu', [['route' => 'admin.seo_pages.index']]));
        $this->assertSame([['loc' => 'a']], Hook::apply('atrium.sitemap.entries', [['loc' => 'a']], new Product));
    }
}
