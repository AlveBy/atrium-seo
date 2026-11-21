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
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Redirects\RedirectMap;
use Atrium\Seo\Tests\TestCase;
use Inertia\Testing\AssertableInertia;

final class RedirectTest extends TestCase
{
    private function redirect(string $source, ?string $target, int $status = 301, array $extra = []): Redirect
    {
        return Redirect::query()->create(['source' => $source, 'target' => $target, 'status' => $status, ...$extra]);
    }

    public function test_an_old_address_goes_to_the_new_one(): void
    {
        $this->redirect('/old-page', '/new-page');

        $this->get('/old-page')->assertStatus(301)->assertRedirect($this->root().'/new-page');
        $this->get('/other-page')->assertNotFound();
    }

    public function test_a_temporary_redirect_keeps_its_status(): void
    {
        $this->redirect('/sale', '/catalog', 302);

        $this->get('/sale')->assertStatus(302);
    }

    public function test_letters_case_trailing_slash_and_order_of_the_query_do_not_matter(): void
    {
        $redirect = $this->redirect('/Old-Page/', '/new');

        $this->assertSame('/Old-Page', $redirect->source);
        $this->get('/old-page')->assertRedirect($this->root().'/new');
        $this->get('/OLD-PAGE/')->assertRedirect($this->root().'/new');

        $this->redirect('/search?b=2&a=1', '/found');
        $this->get('/search?a=1&b=2')->assertRedirect($this->root().'/found');
    }

    public function test_the_visitors_query_goes_along_unless_the_redirect_was_for_it(): void
    {
        $this->redirect('/old', '/new');
        $this->redirect('/list?page=2', '/list/2');
        $this->redirect('/promo', '/catalog?from=promo');

        $this->get('/old?utm_source=mail')->assertRedirect($this->root().'/new?utm_source=mail');
        $this->get('/list?page=2')->assertRedirect($this->root().'/list/2');
        $this->get('/list?page=3')->assertNotFound();
        $this->get('/promo?utm_source=mail')->assertRedirect($this->root().'/catalog?from=promo');
    }

    public function test_a_wildcard_moves_a_whole_section(): void
    {
        $this->redirect('/blog/*', '/news/*');
        $this->redirect('/blog/archive/*', '/archive');

        $this->get('/blog/first-post')->assertRedirect($this->root().'/news/first-post');
        $this->get('/blog')->assertRedirect($this->root().'/news');
        $this->get('/blog/archive/2020')->assertRedirect($this->root().'/archive');
        $this->get('/blogger')->assertNotFound();
    }

    public function test_a_page_gone_for_good_answers_410(): void
    {
        $redirect = $this->redirect('/discontinued', '/ignored', 410);

        $this->assertNull($redirect->target);
        $this->get('/discontinued')->assertStatus(410);
    }

    public function test_a_chain_is_walked_in_one_hop(): void
    {
        $this->redirect('/a', '/b');
        $this->redirect('/b', '/c', 302);
        $this->redirect('/c', '/d');

        // The first redirect's status: the move from /a is permanent.
        $this->get('/a')->assertStatus(301)->assertRedirect($this->root().'/d');
        $this->get('/b')->assertStatus(302)->assertRedirect($this->root().'/d');
    }

    public function test_a_chain_is_shown_in_the_list_and_a_loop_is_found(): void
    {
        $a = $this->redirect('/a', '/b');
        $this->redirect('/b', '/c');
        $c = $this->redirect('/c', '/d');

        $this->assertSame('/b → /c → /d', $a->fresh()->chain);
        $this->assertSame('/d', $c->fresh()->chain);
        $this->assertSame([
            ['id' => $a->id, 'source' => '/a', 'hops' => ['/a', '/b', '/c', '/d'], 'loop' => false],
            ['id' => $a->id + 1, 'source' => '/b', 'hops' => ['/b', '/c', '/d'], 'loop' => false],
        ], app(RedirectMap::class)->problems());

        // A loop that slipped in past the panel (the database, an import
        // of the old site) is reported, not followed.
        Redirect::query()->whereKey($c->id)->update(['target' => '/a']);
        RedirectMap::flush();

        $this->assertSame(__('atrium-seo::seo.chain.loop'), $a->fresh()->chain);
        $this->assertTrue(collect(app(RedirectMap::class)->problems())->every(fn (array $problem): bool => $problem['loop']));
        $this->get('/a')->assertNotFound();
    }

    public function test_a_chain_longer_than_the_limit_counts_as_a_loop(): void
    {
        $hops = RedirectMap::MAX_HOPS + 1;

        for ($i = 0; $i < $hops; $i++) {
            $this->redirect("/r{$i}", '/r'.($i + 1));
        }

        $chain = app(RedirectMap::class)->chainFrom('/r0');
        $this->assertTrue($chain['loop']);
        $this->assertCount($hops + 1, $chain['hops']);
        $this->get('/r0')->assertNotFound();
        // As many redirects as the limit are followed to their end.
        $this->assertFalse(app(RedirectMap::class)->chainFrom('/r1')['loop']);
        $this->get('/r1')->assertRedirect($this->root()."/r{$hops}");

        // A new redirect in front of the limit would go over it.
        $this->assertNotNull(app(RedirectMap::class)->loopWith('/start', '/r1'));
        $this->assertNull(app(RedirectMap::class)->loopWith('/start', '/r2'));
    }

    public function test_a_page_gone_for_good_or_switched_off_has_no_chain(): void
    {
        $this->redirect('/next', '/last');

        $this->assertSame('', Redirect::query()->create(['source' => '/gone', 'status' => 410])->chain);
        $this->assertSame('', $this->redirect('/off', '/next', 301, ['active' => false])->chain);
        $this->assertSame('/next → /last', $this->redirect('/on', '/next')->chain);
    }

    public function test_a_redirect_that_closes_a_loop_is_refused_in_the_panel(): void
    {
        $this->redirect('/a', '/b');
        $this->redirect('/b', '/c');

        $this->actingAs($this->admin(), 'admin')
            ->from('/admin/seo_redirects/create')
            ->post('/admin/seo_redirects', ['source' => '/c', 'target' => '/a', 'status' => 301, 'active' => true])
            ->assertSessionHasErrors(['target' => __('atrium-seo::seo.errors.loop', ['chain' => '/c → /a → /b → /c'])]);

        $this->assertSame(2, Redirect::query()->count());
    }

    public function test_a_redirect_to_itself_and_into_its_own_wildcard_are_loops(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->post('/admin/seo_redirects', ['source' => '/same/', 'target' => '/SAME', 'status' => 301, 'active' => true])
            ->assertSessionHasErrors('target');
        $this->post('/admin/seo_redirects', ['source' => '/shop/*', 'target' => '/shop/new', 'status' => 301, 'active' => true])
            ->assertSessionHasErrors('target');

        $this->assertSame(0, Redirect::query()->count());
    }

    public function test_a_redirect_that_ends_elsewhere_is_saved(): void
    {
        $this->redirect('/a', '/b');

        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/seo_redirects', ['source' => '/b', 'target' => '/c', 'status' => 301, 'active' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.seo_redirects.index'));

        // A switched-off redirect closes no loop: it does not work.
        $this->post('/admin/seo_redirects', ['source' => '/c', 'target' => '/a', 'status' => 301, 'active' => false])
            ->assertSessionHasNoErrors();

        // Editing a redirect does not count its own old target.
        $b = Redirect::query()->where('source', '/b')->sole();
        $this->put("/admin/seo_redirects/{$b->id}", ['source' => '/b', 'target' => '/d', 'status' => 301, 'active' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame('/d', $b->fresh()->target);
    }

    public function test_the_target_is_required_unless_the_page_is_gone(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/seo_redirects', ['source' => '/x', 'target' => '', 'status' => 301, 'active' => true])
            ->assertSessionHasErrors('target');

        $this->post('/admin/seo_redirects', ['source' => '/x', 'target' => '', 'status' => 410, 'active' => true])
            ->assertSessionHasNoErrors();
    }

    public function test_the_list_shows_chains(): void
    {
        $this->redirect('/a', '/b');
        $this->redirect('/b', '/c');

        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_redirects')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('items.total', 2));
    }

    public function test_hits_are_counted(): void
    {
        $redirect = $this->redirect('/old', '/new');

        $this->get('/old');
        $this->get('/old');

        $this->assertSame(2, $redirect->fresh()->hits);
        $this->assertNotNull($redirect->fresh()->last_hit_at);
    }

    public function test_the_panel_and_posted_forms_are_left_alone(): void
    {
        $this->redirect('/admin/*', '/elsewhere');
        $this->redirect('/form', '/thanks');

        $this->get('/admin/login')->assertOk();
        $this->post('/form')->assertNotFound();
        $this->get('/form')->assertRedirect($this->root().'/thanks');
    }

    public function test_a_switched_off_redirect_does_nothing(): void
    {
        $this->redirect('/old', '/new', 301, ['active' => false]);

        $this->get('/old')->assertNotFound();
    }

    public function test_a_redirect_to_another_site(): void
    {
        $this->redirect('/partner', 'https://partner.example.org/page');

        $this->get('/partner?ref=1')->assertRedirect('https://partner.example.org/page?ref=1');
    }

    public function test_an_absolute_address_on_this_site_is_followed_too(): void
    {
        $this->redirect('/a', $this->root().'/b');
        $this->redirect('/b', '/c');

        $this->get('/a')->assertRedirect($this->root().'/c');
    }

    public function test_each_site_has_redirects_of_its_own(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        $this->redirect('/old', '/new', 301, ['site_id' => $second->id]);

        $this->get('/old')->assertNotFound();
        $this->get('/site2/old')->assertRedirect($this->root().'/site2/new');
    }

    public function test_saving_a_redirect_clears_its_404_and_deleting_it_stops_it(): void
    {
        $this->get('/lost')->assertNotFound();
        $this->assertSame(1, NotFound::query()->count());

        $redirect = $this->redirect('/lost', '/found');
        $this->assertSame(0, NotFound::query()->count());
        $this->get('/lost')->assertRedirect($this->root().'/found');

        $redirect->delete();
        $this->get('/lost')->assertNotFound();
    }

    public function test_without_a_saved_site_the_page_answers_and_nothing_redirects(): void
    {
        $this->redirect('/old-page', '/new-page');
        Site::query()->delete();
        app(Sites::class)->flush();

        $this->get('/old-page')->assertNotFound();
        $this->assertSame([], app(RedirectMap::class)->problems());
    }
}
