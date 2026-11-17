<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Seo\Meta\Meta;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Tests\TestCase;

/**
 * The page being drawn, as the schema providers read it.
 */
final class SeoPageTest extends TestCase
{
    public function test_a_breadcrumb_needs_a_label_and_an_address(): void
    {
        $page = (new SeoPage)->breadcrumbs([
            3 => ['label' => 'Catalog', 'url' => 'https://example.com/catalog'],
            'text',
            ['label' => 'No address'],
            ['url' => 'https://example.com/no-label'],
            ['label' => '', 'url' => 'https://example.com/empty-label'],
            ['label' => 5, 'url' => 'https://example.com/number-label'],
            ['label' => 'Number address', 'url' => 5],
            ['label' => 'Empty address', 'url' => ''],
            ['label' => 'Mice', 'url' => 'https://example.com/catalog/mice', 'extra' => true],
        ]);

        $this->assertSame([
            ['label' => 'Catalog', 'url' => 'https://example.com/catalog'],
            ['label' => 'Empty address', 'url' => ''],
            ['label' => 'Mice', 'url' => 'https://example.com/catalog/mice', 'extra' => true],
        ], $page->breadcrumbs);
    }

    public function test_the_canonical_is_the_metas_then_the_pages_then_the_current_address(): void
    {
        $page = new SeoPage;

        $this->assertSame(url()->current(), $page->canonical());

        $page->url = 'https://example.com/page';
        $this->assertSame('https://example.com/page', $page->canonical());

        $page->meta = new Meta(canonical: 'https://example.com/meta');
        $this->assertSame('https://example.com/meta', $page->canonical());

        $page->url = null;
        $this->assertSame('https://example.com/meta', $page->canonical());
    }

    public function test_the_ids_of_the_nodes_hang_off_the_home_page_and_the_canonical(): void
    {
        $page = new SeoPage;
        $page->url = 'https://example.com/catalog/mouse?page=2';

        $this->assertSame($this->root().'/', $page->homeUrl());
        $this->assertSame($this->root().'/#organization', $page->organizationId());
        $this->assertSame($this->root().'/#website', $page->websiteId());
        $this->assertSame('https://example.com/catalog/mouse#main', $page->mainEntityId());
    }

    public function test_the_home_page_of_a_site_in_a_folder_ends_with_one_slash(): void
    {
        $site = Site::query()->create(['code' => 'shop', 'name' => 'Shop', 'folder' => 'shop']);
        app(Sites::class)->setCurrent($site);

        $this->assertSame($this->root().'/shop/', (new SeoPage)->homeUrl());
    }
}
