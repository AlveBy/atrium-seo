<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Seo\Models\NotFound;
use Atrium\Seo\Redirects\NotFoundLog;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * Addresses that end in a 404, grouped by address, with a button that
 * opens a redirect from them.
 */
final class NotFoundLogTest extends TestCase
{
    public function test_a_missing_address_is_logged_with_the_page_that_linked_to_it(): void
    {
        $this->withHeader('Referer', 'https://example.org/links')->get('/Lost-Page/?')->assertNotFound();

        $row = NotFound::query()->sole();
        $this->assertSame('/Lost-Page', $row->path);
        $this->assertSame(1, $row->hits);
        $this->assertSame('https://example.org/links', $row->referer);
        $this->assertNotNull($row->first_seen_at);
    }

    public function test_the_same_address_is_one_row(): void
    {
        $this->get('/lost')->assertNotFound();
        $this->withHeader('Referer', 'https://example.org/a')->get('/lost/')->assertNotFound();
        $this->get('/lost')->assertNotFound();

        $row = NotFound::query()->sole();
        $this->assertSame('/lost', $row->path);
        $this->assertSame(3, $row->hits);
        // A visit with no referer keeps the last one known.
        $this->assertSame('https://example.org/a', $row->referer);
    }

    public function test_found_pages_and_ignored_addresses_are_not_logged(): void
    {
        $this->get('/')->assertOk();
        $this->get('/wp-login.php')->assertNotFound();
        $this->get('/app.js.map')->assertNotFound();
        $this->get('/.well-known/security.txt')->assertNotFound();

        $this->assertSame(0, NotFound::query()->count());
    }

    #[DefineEnvironment('switchedOff')]
    public function test_the_log_can_be_switched_off(): void
    {
        $this->get('/lost')->assertNotFound();

        $this->assertSame(0, NotFound::query()->count());
    }

    protected function switchedOff($app): void
    {
        $app['config']->set('atrium-seo.not_found.enabled', false);
    }

    public function test_the_oldest_addresses_go_past_the_limit(): void
    {
        config(['atrium-seo.not_found.keep' => 2]);
        $log = app(NotFoundLog::class);

        Date::setTestNow('2026-11-01 10:00');
        $log->hit('/first', 1);
        Date::setTestNow('2026-11-02 10:00');
        $log->hit('/second', 1);
        Date::setTestNow('2026-11-03 10:00');
        $log->hit('/first', 1);
        Date::setTestNow('2026-11-04 10:00');
        $log->hit('/third', 1);

        $this->assertSame(['/first', '/third'], NotFound::query()->orderBy('path')->pluck('path')->all());
    }

    public function test_each_site_has_a_log_of_its_own(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);

        $this->get('/site2/lost')->assertNotFound();
        $this->get('/lost')->assertNotFound();

        $this->assertSame([1, $second->id], NotFound::query()->withoutGlobalScopes()->orderBy('site_id')->pluck('site_id')->all());
        $this->assertSame(['/lost', '/lost'], NotFound::query()->withoutGlobalScopes()->pluck('path')->all());
    }

    public function test_the_list_offers_a_redirect_from_each_address(): void
    {
        $this->get('/often')->assertNotFound();
        $this->get('/often')->assertNotFound();
        $this->get('/rare')->assertNotFound();

        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_not_found')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.total', 2)
                ->where('items.data.0.path', '/often')
                ->where('items.data.0.create_redirect', __('atrium-seo::seo.actions.create_redirect'))
                ->where('items.data.0.create_redirect_url', route('admin.seo_redirects.create', ['source' => '/often'])));
    }

    public function test_the_button_opens_the_form_with_the_address_filled_in(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_redirects/create?source=/lost&status=302&note=x')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fields.0.name', 'source')
                ->where('fields.0.default', '/lost')
                ->where('fields.2.default', 302)
                ->where('fields.4.default', null));
    }

    public function test_the_log_cannot_be_filled_by_hand(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_not_found/create')->assertForbidden();
    }

    public function test_without_a_saved_site_a_missing_address_is_not_logged(): void
    {
        Site::query()->delete();
        app(Sites::class)->flush();

        $this->get('/lost')->assertNotFound();
        app(NotFoundLog::class)->hit('/lost-too', null);

        $this->assertSame(0, NotFound::query()->withoutGlobalScopes()->count());
    }
}
