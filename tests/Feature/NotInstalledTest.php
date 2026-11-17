<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Support\Menu;
use Atrium\Catalog\Models\Product;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/**
 * Composer brings the package in; until `atrium:module:install` it does
 * nothing to the site.
 */
final class NotInstalledTest extends TestCase
{
    protected array $modules = ['alveby/atrium-catalog'];

    public function test_the_site_is_left_as_it_was(): void
    {
        Redirect::query()->create(['source' => '/old', 'target' => '/new']);
        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);

        $this->get('/old')->assertNotFound();
        $html = $this->get('/catalog/lamp')->assertOk()->getContent();

        $this->assertStringNotContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('hreflang', $html);
        $this->assertFalse(Route::has('atrium.seo.sitemap.part'));
        $this->assertFalse(Route::has('atrium.seo.redirect-csv'));
        $this->assertFalse(Route::has('admin.seo_redirects.index'));
        $this->assertNotSame('', config('atrium.seo.title_suffix'));
    }

    public function test_the_panel_has_no_seo_screens(): void
    {
        $menu = app(Menu::class)->items($this->admin());

        $this->assertSame([], array_filter($menu, static fn (array $item): bool => in_array($item['label'] ?? '', ['atrium-seo::seo.menu', __('atrium-seo::seo.menu')], true)));
    }
}
