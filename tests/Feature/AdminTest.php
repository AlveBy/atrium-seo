<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\SeoMeta;
use Atrium\Admin\Support\Menu;
use Atrium\Catalog\Models\Product;
use Atrium\Seo\Models\Issue;
use Atrium\Seo\Models\NotFound;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Robots;
use Atrium\Seo\SeoServiceProvider;
use Atrium\Seo\Tests\TestCase;
use Inertia\Testing\AssertableInertia;

/**
 * The module's screens in the panel.
 */
final class AdminTest extends TestCase
{
    public function test_the_screens_are_one_group_of_the_menu_the_check_first(): void
    {
        $menu = app(Menu::class)->items($this->admin());
        $groups = array_values(array_filter($menu, static fn (array $item): bool => ($item['label'] ?? '') === __('atrium-seo::seo.menu')));

        $this->assertCount(1, $groups);
        $this->assertSame([
            'admin.seo_issues.index', 'admin.seo_redirects.index', 'admin.seo_not_found.index',
            'admin.seo_redirect_imports.index', 'admin.seo_pages.index', 'admin.seo_templates.index',
        ], array_column($groups[0]['children'], 'route'));
        $this->assertSame([], array_filter($menu, static fn (array $item): bool => str_starts_with((string) ($item['route'] ?? ''), 'admin.seo_')));
    }

    public function test_the_group_takes_the_place_of_its_first_screen(): void
    {
        $menu = [
            ['label' => 'Dashboard', 'route' => 'admin.dashboard'],
            ['label' => 'Templates', 'route' => 'admin.seo_templates.index'],
            ['label' => 'Products', 'route' => 'admin.catalog_products.index'],
            ['label' => 'Check', 'route' => 'admin.seo_issues.index'],
        ];

        $grouped = SeoServiceProvider::menu($menu);

        $this->assertSame(['Dashboard', 'atrium-seo::seo.menu', 'Products'], array_column($grouped, 'label'));
        $this->assertSame(['Check', 'Templates'], array_column($grouped[1]['children'], 'label'));

        // A menu without the module's screens stays as it is.
        $this->assertSame([$menu[0], $menu[2]], SeoServiceProvider::menu([$menu[0], $menu[2]]));
    }

    public function test_the_settings_are_saved_per_site(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/modules/seo/settings')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fields.0.name', 'organization_name'));

        $this->put('/admin/modules/seo/settings', ['organization_name' => 'Demo Ltd', 'currency' => 'EUR', 'indexnow' => false, 'email' => 'shop@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Demo Ltd', Setting::get('seo.organization_name'));
        $this->assertFalse((bool) Setting::get('seo.indexnow', true));

        $this->put('/admin/modules/seo/settings', ['email' => 'not an address'])->assertSessionHasErrors('email');
    }

    public function test_a_template_written_in_the_panel(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_templates/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fields.0.options', function ($options): bool {
                    $models = collect($options)->pluck('value');

                    return $models->contains('*') && $models->contains(Product::class)
                        && $models->every(static fn (string $model): bool => ! str_starts_with($model, 'Atrium\\Seo\\') && ! str_starts_with($model, 'Atrium\\Admin\\'));
                }));

        $this->post('/admin/seo_templates', ['model' => Product::class, 'title' => 'Order {name} | {site}', 'description' => 'Fast delivery of {name}'])
            ->assertSessionHasNoErrors();
        $this->post('/admin/seo_templates', ['title' => 'x'])->assertSessionHasErrors('model');

        $this->assertSame(Product::getAdminLabel(), Template::query()->sole()->module);

        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);
        $this->get('/catalog/lamp')
            ->assertSee('<title>Order Lamp | Demo shop</title>', false)
            ->assertSee('<meta name="description" content="Fast delivery of Lamp">', false);
    }

    public function test_meta_of_an_address_written_in_the_panel(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/seo_pages', ['path' => 'Catalog/', 'title' => 'Every lamp we sell', 'robots' => Robots::NOINDEX])
            ->assertSessionHasNoErrors();

        $this->assertSame('/Catalog', PageMeta::query()->sole()->path);
        $this->post('/admin/seo_pages', ['title' => 'x'])->assertSessionHasErrors('path');
    }

    public function test_the_lists_of_pages_templates_and_imports(): void
    {
        PageMeta::query()->create(['path' => '/news', 'title' => 'News']);
        PageMeta::query()->create(['path' => '/catalog', 'title' => 'Catalog']);
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{name}']]);
        $names = static fn (string ...$expected): \Closure => static fn ($columns): bool => collect($columns)->pluck('name')->all() === $expected;

        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_pages')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('createLabel', __('atrium-seo::seo.create.page'))
                ->where('filters.sort', 'path')
                ->where('filters.direction', 'asc')
                ->where('columns', $names('path', 'title', 'robots'))
                ->where('items.data.0.path', '/catalog'));
        $this->get('/admin/seo_templates')->assertInertia(fn (AssertableInertia $page) => $page->where('columns', $names('module', 'title')));
        $this->get('/admin/seo_redirect_imports')->assertInertia(fn (AssertableInertia $page) => $page->where('columns', $names('id', 'result', 'created_at')));

        // The check and the 404 log are written by the site, not in a form.
        $this->assertSame([], Issue::getAdministrationFields());
        $this->assertSame([], NotFound::getAdministrationFields());
    }

    public function test_the_seo_tab_of_a_record_keeps_it_out_of_search_engines(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/catalog_products/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fields', fn ($fields): bool => collect($fields)->contains('name', 'seo_robots')));

        $this->post('/admin/catalog_products', ['name' => 'Secret', 'code' => 'secret', 'active' => true, 'seo_robots' => Robots::NONE])
            ->assertSessionHasNoErrors();

        $product = Product::query()->sole();
        $this->assertSame(Robots::NONE, SeoMeta::query()->where('seoable_id', $product->id)->value('robots'));
        $this->get('/catalog/secret')->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        $this->put("/admin/catalog_products/{$product->id}", ['name' => 'Secret', 'code' => 'secret', 'active' => true, 'seo_robots' => ''])
            ->assertSessionHasNoErrors();
        $this->get('/catalog/secret')->assertDontSee('<meta name="robots"', false);

        $this->put("/admin/catalog_products/{$product->id}", ['name' => 'Secret', 'code' => 'secret', 'seo_robots' => 'nofollow'])
            ->assertSessionHasErrors('seo_robots');
    }
}
