<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\Site;
use Atrium\Catalog\Models\Product;
use Atrium\Seo\IndexNow\IndexNow;
use Atrium\Seo\IndexNow\RecordSaved;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Robots;
use Atrium\Seo\Tests\Fixtures\Leaflet;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bing and Yandex hear about a page the moment it is saved in the panel.
 */
final class IndexNowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        // Production, where the panel's forms check the CSRF token.
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_a_saved_product_is_sent_to_both_engines(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/catalog_products', ['name' => 'Desk lamp', 'code' => 'desk-lamp', 'active' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $key = app(IndexNow::class)->key();
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.bing.com/indexnow'
            && $request['host'] === parse_url($this->root(), PHP_URL_HOST)
            && $request['key'] === $key
            && $request['keyLocation'] === $this->root().'/'.$key.'.txt'
            && $request['urlList'] === [$this->root().'/catalog/desk-lamp']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://yandex.com/indexnow');
    }

    public function test_the_key_file_proves_the_site_is_ours(): void
    {
        $key = app(IndexNow::class)->key();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
        $this->get('/'.$key.'.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSeeText($key);
        $this->get('/'.str_repeat('0', 32).'.txt')->assertNotFound();
    }

    public function test_each_site_has_a_key_of_its_own(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);

        $this->assertNotSame(app(IndexNow::class)->key(), app(IndexNow::class)->key($second));
    }

    public function test_nothing_is_sent_outside_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->actingAs($this->admin(), 'admin')->post('/admin/catalog_products', ['name' => 'Desk lamp', 'code' => 'desk-lamp', 'active' => true]);

        Http::assertNothingSent();
    }

    public function test_nothing_is_sent_when_switched_off_for_the_site_or_in_config(): void
    {
        Setting::save('seo', ['indexnow' => false]);
        $this->assertFalse(app(IndexNow::class)->enabled());

        Setting::save('seo', ['indexnow' => true]);
        $this->assertTrue(app(IndexNow::class)->enabled());

        config(['atrium-seo.indexnow.enabled' => false]);
        $this->assertFalse(app(IndexNow::class)->enabled());

        app(IndexNow::class)->queue(['https://example.com/a']);
        $this->assertSame(0, app(IndexNow::class)->flush());
        Http::assertNothingSent();
    }

    public function test_a_hidden_record_and_one_kept_out_of_search_engines_send_nothing(): void
    {
        $hidden = Product::query()->create(['name' => 'Draft', 'code' => 'draft', 'active' => false]);
        $this->assertSame([], app(RecordSaved::class)->urls($hidden));

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/catalog_products', ['name' => 'Secret', 'code' => 'secret', 'active' => true, 'seo_robots' => Robots::NOINDEX])
            ->assertSessionHasNoErrors();

        Http::assertNothingSent();
    }

    public function test_a_record_without_a_public_page_sends_nothing(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/admin/seo_redirects', ['source' => '/a', 'target' => '/b', 'status' => 301, 'active' => true]);
        app(RecordSaved::class)('not a record');

        $this->assertSame([], app(RecordSaved::class)->urls(Redirect::query()->sole()));
        Http::assertNothingSent();
    }

    public function test_the_same_address_is_not_sent_twice_in_a_row(): void
    {
        $indexNow = app(IndexNow::class);
        $site = Site::query()->findOrFail(1);

        $this->assertSame(2, $indexNow->send($site, ['https://example.com/a', 'https://example.com/b', 'https://example.com/a']));
        $this->assertSame(1, $indexNow->send($site, ['https://example.com/a', 'https://example.com/c']));
        $this->assertSame(0, $indexNow->send($site, ['https://example.com/c']));

        config(['atrium-seo.indexnow.throttle_minutes' => 0]);
        $this->assertSame(1, $indexNow->send($site, ['https://example.com/c']));
    }

    public function test_an_engine_that_fails_does_not_break_the_save(): void
    {
        Http::fake(fn () => throw new RuntimeException('down'));

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/catalog_products', ['name' => 'Desk lamp', 'code' => 'desk-lamp', 'active' => true])
            ->assertRedirect();

        $this->assertSame(1, Product::query()->count());
    }

    public function test_addresses_of_a_site_that_is_gone_are_dropped(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        app(IndexNow::class)->queue(['https://example.com/site2/a'], $second);
        $second->delete();

        $this->assertSame(0, app(IndexNow::class)->flush());
        Http::assertNothingSent();
    }

    public function test_a_record_of_a_module_with_its_own_sitemap_sends_its_own_addresses(): void
    {
        $lamp = Leaflet::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'active' => true]);
        $hidden = Leaflet::query()->create(['name' => 'Hidden', 'code' => 'hidden', 'active' => false]);

        $this->assertSame([$this->root().'/leaflets/lamp'], app(RecordSaved::class)->urls($lamp));
        $this->assertSame([], app(RecordSaved::class)->urls($hidden));
    }
}
