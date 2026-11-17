<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Http\Middleware\EnterSiteFolder;
use Atrium\Admin\Models\Site;
use Atrium\Seo\Http\Middleware\HandleRedirects;
use Atrium\Seo\Models\NotFound;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Redirects\RedirectMap;
use Atrium\Seo\Tests\TestCase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

/**
 * The finer rules of the redirect manager: how addresses are cleaned, how
 * the map reads them, what the middleware leaves alone.
 */
final class RedirectRulesTest extends TestCase
{
    private function redirect(string $source, ?string $target, int $status = 301, array $extra = []): Redirect
    {
        return Redirect::query()->create(['source' => $source, 'target' => $target, 'status' => $status, ...$extra]);
    }

    private function map(): RedirectMap
    {
        return app(RedirectMap::class);
    }

    /**
     * Runs a test without one of the module's tables, as before the migration.
     */
    private function withoutTable(string $table, string $migration, Closure $test): void
    {
        $this->onlyOnSqlite();
        Schema::drop($table);

        try {
            $test();
        } finally {
            (require __DIR__.'/../../database/migrations/'.$migration)->up();
        }
    }

    // -- cleaning ---------------------------------------------------------

    public function test_a_source_is_cleaned(): void
    {
        $this->assertSame('/Old-Page?a=1&b=2', Redirect::cleanSource(' /Old-Page/?b=2&a=1#top '));
        $this->assertSame('/blog/*', Redirect::cleanSource('/blog/*'));
        $this->assertSame('/blog/*', Redirect::cleanSource('/blog//*'));
        // Stars and spaces after the address are not part of it.
        $this->assertSame('/blog/*', Redirect::cleanSource("/blog/** \n"));
        $this->assertSame('/*', Redirect::cleanSource('/*'));
        $this->assertSame('/*', Redirect::cleanSource('*'));
    }

    public function test_a_target_is_cleaned(): void
    {
        $this->assertSame('/x', Redirect::cleanTarget(' /x ', 301));
        $this->assertSame('/x', Redirect::cleanTarget('x', 302));
        $this->assertNull(Redirect::cleanTarget('   ', 301));
        $this->assertNull(Redirect::cleanTarget('', 301));
        $this->assertNull(Redirect::cleanTarget(null, 301));
        $this->assertNull(Redirect::cleanTarget('/x', 410));
        $this->assertSame('https://a.example/x', Redirect::cleanTarget('https://a.example/x', 301));
        $this->assertSame('HTTP://A.example/x', Redirect::cleanTarget('HTTP://A.example/x', 301));
        // An address inside the query is not the address.
        $this->assertSame('/go?u=http://a.example', Redirect::cleanTarget('go?u=http://a.example', 301));
    }

    public function test_the_numbers_of_a_redirect_are_numbers(): void
    {
        $redirect = (new Redirect)->forceFill(['status' => '302', 'hits' => '7', 'active' => '1']);

        $this->assertSame(302, $redirect->status);
        $this->assertSame(7, $redirect->hits);
        $this->assertTrue($redirect->active);
    }

    public function test_the_status_choices(): void
    {
        $this->assertSame([
            301 => '301 — moved for good',
            302 => '302 — moved for a while',
            307 => '307 — moved for a while, same method',
            308 => '308 — moved for good, same method',
            410 => '410 — gone',
        ], Redirect::statusOptions());
    }

    public function test_the_copy_action_carries_the_address_target_and_code(): void
    {
        $actions = Redirect::getAdministrationActions();

        $this->assertCount(1, $actions);
        $this->assertSame(['source' => 'source', 'target' => 'target', 'status' => 'status'], $actions[0]->toArray()['fill']);
    }

    public function test_the_form_asks_for_what_a_redirect_needs(): void
    {
        $fields = collect(Redirect::getAdministrationFields())->keyBy(fn ($field): string => $field->getName());

        $this->assertSame(['required', 'string', 'max:2048'], $fields['source']->getValidationRules());
        $this->assertSame(['nullable', 'required_unless:status,410', 'string', 'max:2048'], $fields['target']->getValidationRules());

        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_redirects/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fields.0.name', 'source')
                ->where('fields.2.name', 'status')
                ->where('fields.2.default', 301)
                ->where('fields.3.name', 'active')
                ->where('fields.3.default', true));
    }

    public function test_the_list_columns_of_the_redirects(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_redirects')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns', fn ($columns): bool => collect($columns)->pluck('name')->all() === ['source', 'target', 'status', 'chain', 'hits', 'last_hit_at', 'active']));
    }

    public function test_an_empty_or_wordless_address_is_refused_in_the_panel(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/seo_redirects', ['source' => '', 'target' => '/x', 'status' => 301, 'active' => true])
            ->assertSessionHasErrors('source');

        $this->post('/admin/seo_redirects', ['source' => '/x', 'target' => str_repeat('a', 2049), 'status' => 301, 'active' => true])
            ->assertSessionHasErrors('target');
        $this->post('/admin/seo_redirects', ['source' => str_repeat('a', 2049), 'target' => '/x', 'status' => 301, 'active' => true])
            ->assertSessionHasErrors('source');
        $this->post('/admin/seo_redirects', ['source' => '/x', 'target' => null, 'status' => 410, 'active' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(['/x'], Redirect::query()->pluck('source')->all());
    }

    // -- the loop check ---------------------------------------------------

    public function test_a_loop_is_found_even_when_the_form_did_not_send_the_switch(): void
    {
        $this->expectException(ValidationException::class);

        (new Redirect)->transformAdminData(['source' => '/a', 'target' => '/a']);
    }

    public function test_the_loop_check_takes_what_the_form_does_not_send_from_the_redirect(): void
    {
        $gone = $this->redirect('/a', null, 410);

        // 410 has no target: nothing to loop.
        $this->assertSame(['target' => '/a'], $gone->transformAdminData(['target' => '/a']));

        // The form's own code beats the saved one; it comes as text.
        $moved = $this->redirect('/b', '/z');
        $this->assertSame(['status' => '410', 'target' => '/b'], $moved->transformAdminData(['status' => '410', 'target' => '/b']));

        // The saved address is the source when the form sends none.
        try {
            $moved->transformAdminData(['target' => '/b']);
            $this->fail('/b to /b is a loop');
        } catch (ValidationException $e) {
            $this->assertSame(['target' => [__('atrium-seo::seo.errors.loop', ['chain' => '/b → /b'])]], $e->errors());
        }
    }

    public function test_the_loop_check_takes_the_address_from_the_form_before_the_saved_one(): void
    {
        $moved = $this->redirect('/b', '/q');

        // /b becomes /q → /b: the old /b → /q is gone, there is no loop.
        $data = ['source' => '/q', 'target' => '/b'];
        $this->assertSame($data, $moved->transformAdminData($data));
    }

    public function test_the_target_of_the_form_may_come_as_a_number(): void
    {
        $data = ['source' => '/a', 'target' => 5];

        $this->assertSame($data, (new Redirect)->transformAdminData($data));
    }

    public function test_a_redirect_into_a_gone_page_is_not_a_loop(): void
    {
        $this->redirect('/gone', null, 410);

        $this->assertNull($this->map()->loopWith('/start', '/gone'));
    }

    public function test_which_addresses_a_source_covers(): void
    {
        $loop = fn (string $source, string $target): bool => $this->map()->loopWith($source, $target) !== null;

        // A section covers itself and what is inside, nothing like it.
        $this->assertTrue($loop('/shop/*', '/shop'));
        $this->assertTrue($loop('/shop/*', '/shop/new'));
        $this->assertTrue($loop('/shop/*', '/shop/a/b?x=1'));
        $this->assertFalse($loop('/shop/*', '/shopping'));
        $this->assertFalse($loop('/shop/*', '/other'));
        $this->assertFalse($loop('/shop/*', '/'));
        $this->assertTrue($loop('/Shop/*', '/shop/new'));
        $this->assertTrue($loop('/shop/*', '/SHOP/new'));
        $this->assertTrue($loop('/shop/*', '/SHOP'));
        $this->assertTrue($loop('/*', '/x'));
        $this->assertTrue($loop('/*', '/'));

        // One address, with its query or without, any case.
        $this->assertTrue($loop('/old', '/old'));
        $this->assertTrue($loop('/Old', '/OLD'));
        $this->assertTrue($loop('/old', '/old?x=1'));
        $this->assertTrue($loop('/Old', '/OLD?x=1'));
        $this->assertTrue($loop('/List?page=2', '/LIST?page=2'));
        $this->assertTrue($loop('/list?page=2', '/List?page=2'));
        $this->assertFalse($loop('/old', '/other?x=1'));
        $this->assertFalse($loop('/old', '/older'));
        $this->assertFalse($loop('/old?a=1', '/old'));
        $this->assertFalse($loop('/old?a=1', '/old?a=2'));
    }

    public function test_the_circle_is_told_hop_by_hop(): void
    {
        $this->redirect('/a', '/b');
        $this->redirect('/b', '/c');

        $this->assertSame(['/c', '/a', '/b', '/c'], $this->map()->loopWith('/c', '/a'));
        $this->assertNull($this->map()->loopWith('/c', '/z'));
    }

    // -- the map ----------------------------------------------------------

    public function test_a_chain_tells_where_it_started_and_what_the_first_redirect_said(): void
    {
        $a = $this->redirect('/a', '/b', 302);
        $this->redirect('/b', '/c', 301);
        $this->redirect('/c', '/d', 307);

        $this->assertSame(
            ['hops' => ['/a', '/b', '/c', '/d'], 'loop' => false, 'status' => 302, 'id' => $a->id],
            $this->map()->chainFrom('/a'),
        );
        $this->assertSame(
            ['hops' => ['/z'], 'loop' => false, 'status' => null, 'id' => null],
            $this->map()->chainFrom('/z'),
        );
    }

    public function test_an_address_in_the_wrong_case_with_a_query_is_found(): void
    {
        $this->redirect('/old', '/new');

        $this->assertSame('/new', $this->map()->match('/OLD?utm=1')['target']);
        $this->assertSame('/new', $this->map()->match('/Old')['target']);
        $this->assertNull($this->map()->match('/older?utm=1'));
    }

    public function test_an_address_with_a_query_is_found_in_any_case_and_before_the_one_without(): void
    {
        $this->redirect('/list', '/all');
        $this->redirect('/list?page=2', '/second');

        $this->assertSame('/second', $this->map()->match('/LIST?page=2')['target']);
        $this->assertSame('/second', $this->map()->match('/list?page=2')['target']);
        $this->assertSame('/all', $this->map()->match('/list?page=3')['target']);
    }

    public function test_a_star_right_after_a_name_is_the_end_of_the_target_too(): void
    {
        $this->redirect('/old/*', '/new*');

        $this->get('/old/a/b')->assertRedirect($this->root().'/new/a/b');
    }

    public function test_the_helpers_of_the_models_are_public(): void
    {
        // The models' magic lets a protected static method be called from
        // outside; only the reflection tells.
        $this->assertFalse(NotFound::isCreatableInAdmin());
        $this->assertFalse(NotFound::isEditableInAdmin());
        $this->assertSame('/a', Redirect::cleanSource('/a'));
        $this->assertSame('/a', Redirect::cleanTarget('/a', 301));
        $this->assertCount(5, Redirect::statusOptions());
        $this->assertCount(1, Redirect::getAdministrationActions());

        foreach ([
            [Redirect::class, 'cleanSource'], [Redirect::class, 'cleanTarget'], [Redirect::class, 'statusOptions'],
            [Redirect::class, 'getAdministrationActions'], [NotFound::class, 'isCreatableInAdmin'], [NotFound::class, 'isEditableInAdmin'],
        ] as [$class, $method]) {
            $this->assertTrue((new ReflectionMethod($class, $method))->isPublic(), "{$class}::{$method}");
        }
    }

    public function test_the_case_of_a_section_does_not_matter(): void
    {
        $this->redirect('/blog/*', '/news/*');
        $this->redirect('/Shop/*', '/store/*');

        $this->get('/BLOG/First')->assertRedirect($this->root().'/news/First');
        $this->get('/BLOG')->assertRedirect($this->root().'/news');
        $this->get('/shop/x')->assertRedirect($this->root().'/store/x');
        $this->get('/SHOP')->assertRedirect($this->root().'/store');
        $this->get('/shopping')->assertNotFound();
    }

    public function test_a_section_can_move_to_another_site_and_keeps_the_rest_of_the_path(): void
    {
        $this->redirect('/blog/*', 'https://new.example.org/*');
        $this->redirect('/docs/*', 'https://docs.example.org/guide/*');

        $this->get('/blog/a/b')->assertRedirect('https://new.example.org/a/b');
        $this->get('/docs/a')->assertRedirect('https://docs.example.org/guide/a');
    }

    public function test_the_whole_site_can_move(): void
    {
        $this->redirect('/*', 'https://new.example.org/*');

        $this->get('/anything/here')->assertRedirect('https://new.example.org/anything/here');
    }

    public function test_when_two_redirects_meet_at_one_address_the_older_wins(): void
    {
        $this->redirect('/Old', '/first');
        $this->redirect('/old', '/second');
        $this->redirect('/Blog/*', '/one');
        $this->redirect('/blog/*', '/two');

        $this->get('/old')->assertRedirect($this->root().'/first');
        $this->get('/blog/x')->assertRedirect($this->root().'/one');
    }

    public function test_the_target_that_is_a_full_address_of_another_host_ends_the_chain(): void
    {
        $this->redirect('/a', 'HTTPS://localhost/b');
        $this->redirect('/c', 'go?u=http://a.example');

        $this->assertSame(['/a', 'HTTPS://localhost/b'], $this->map()->chainFrom('/a')['hops']);
        $this->assertSame(['/c', '/go?u=http%3A%2F%2Fa.example'], $this->map()->chainFrom('/c')['hops']);
    }

    public function test_a_full_address_of_a_site_is_read_as_that_site(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        $this->redirect('/old', $this->root().'/site2/new', 301, ['site_id' => $second->id]);

        $this->assertSame(['/old', '/new'], $this->map()->chainFrom('/old', $second->id)['hops']);
    }

    public function test_the_map_is_read_once_and_kept_under_a_key_of_its_own(): void
    {
        $this->redirect('/old', '/new');

        $this->map()->match('/old');
        $this->assertTrue(Cache::has('atrium.seo.redirects.1.'.Cache::get('atrium.seo.redirects.version')));

        Cache::flush();
        DB::enableQueryLog();
        $this->assertSame('/new', $this->map()->match('/old')['target']);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'seo_redirects')));
    }

    public function test_a_redirect_saved_later_is_seen(): void
    {
        $this->assertNull($this->map()->match('/old'));

        $this->redirect('/old', '/new');

        $this->assertSame('/new', $this->map()->match('/old')['target']);
    }

    // -- the chain in the list --------------------------------------------

    public function test_a_switched_off_redirect_shows_no_chain_even_where_a_section_covers_it(): void
    {
        $this->redirect('/blog/*', '/news');
        $off = $this->redirect('/blog/x', '/y', 301, ['active' => false]);

        $this->assertSame('', $off->chain);
        $this->assertSame('', $off->getChainAttribute());
    }

    public function test_the_chain_is_read_by_name_as_well(): void
    {
        $a = $this->redirect('/a', '/b');
        $this->redirect('/b', '/c');

        $this->assertSame('/b → /c', $a->getChainAttribute());
    }

    // -- the middleware ---------------------------------------------------

    public function test_the_panel_is_left_alone_but_a_page_that_only_starts_like_it_is_not(): void
    {
        $this->redirect('/admin', '/x');
        $this->redirect('/administrator', '/new');

        $this->assertNotSame($this->root().'/x', $this->get('/admin')->headers->get('Location'));
        $this->get('/administrator')->assertStatus(301)->assertRedirect($this->root().'/new');
    }

    public function test_the_prefixes_of_the_panel_and_the_api_may_come_with_slashes(): void
    {
        $this->redirect('/api/v1/old', '/x');
        config(['atrium.api.prefix' => '/api/v1/']);
        // Straight through the middleware: the API itself looks for its tokens.
        $response = app(HandleRedirects::class)->handle(Request::create('/api/v1/old'), fn (): Response => new Response('api'));

        $this->assertSame('api', $response->getContent());
    }

    public function test_the_site_of_the_request_may_come_as_text(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        $this->redirect('/old', '/new', 301, ['site_id' => $second->id]);

        $request = Request::create('/old');
        $request->attributes->set(EnterSiteFolder::SITE, (string) $second->id);
        $response = app(HandleRedirects::class)->handle($request, fn (): Response => new Response('page'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringEndsWith('/new', (string) $response->headers->get('Location'));

        $request = Request::create('/missing');
        $request->attributes->set(EnterSiteFolder::SITE, (string) $second->id);
        app(HandleRedirects::class)->handle($request, fn (): Response => new Response('', 404));

        $this->assertSame($second->id, NotFound::query()->withoutGlobalScopes()->sole()->site_id);
    }

    public function test_the_redirect_of_a_site_that_is_not_a_number_is_not_a_site(): void
    {
        $this->redirect('/old', '/new');

        $request = Request::create('/old');
        $request->attributes->set(EnterSiteFolder::SITE, 'main');

        $this->assertSame(301, app(HandleRedirects::class)->handle($request, fn (): Response => new Response('page'))->getStatusCode());
    }

    // -- without the tables ----------------------------------------------

    public function test_before_the_tables_exist_the_site_still_answers(): void
    {
        $this->withoutTable('seo_redirects', '2026_11_17_200003_create_seo_redirects_table.php', function (): void {
            $this->assertNull($this->map()->match('/old'));
            $this->get('/old')->assertNotFound();
            $this->get('/')->assertOk();
        });
    }

    public function test_a_hit_that_cannot_be_counted_does_not_stop_the_redirect(): void
    {
        $this->redirect('/old', '/new');
        $this->get('/old')->assertRedirect($this->root().'/new');

        $this->withoutTable('seo_redirects', '2026_11_17_200003_create_seo_redirects_table.php', function (): void {
            $this->get('/old')->assertStatus(301)->assertRedirect($this->root().'/new');
        });
    }

    public function test_a_404_that_cannot_be_logged_is_still_a_404(): void
    {
        $this->withoutTable('seo_not_found', '2026_11_17_200004_create_seo_not_found_table.php', function (): void {
            $this->get('/lost')->assertNotFound();
        });
    }
}
