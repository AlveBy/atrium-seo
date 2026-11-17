<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Audit\Audit;
use Atrium\Seo\Models\Issue;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Robots;
use Atrium\Seo\Tests\Fixtures\ArchiveYears;
use Atrium\Seo\Tests\Fixtures\CalendarDays;
use Atrium\Seo\Tests\Fixtures\Oddity;
use Atrium\Seo\Tests\Fixtures\SeoNews;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use RuntimeException;

/**
 * The rules of SEO → Check one by one: where each limit lies, what counts as
 * the same and what is left alone. AuditTest shows every check firing on a
 * bad page; here each condition is pushed to its edge and over it.
 */
final class AuditRulesTest extends TestCase
{
    /**
     * check => "label: detail" of what was found, after a run.
     *
     * @return array<string,list<string>>
     */
    private function check(?Site $site = null): array
    {
        $site ??= Site::query()->findOrFail(1);
        app(Audit::class)->run($site);

        $found = [];

        foreach (Issue::query()->withoutGlobalScopes()->where('site_id', $site->id)->orderBy('id')->get() as $issue) {
            $found[$issue->check][] = $issue->detail === null ? $issue->label : $issue->label.': '.$issue->detail;
        }

        return $found;
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function product(string $name, array $extra = []): Product
    {
        return Product::query()->create(['name' => $name, 'code' => mb_strtolower((string) preg_replace('/\W+/u', '-', $name)), 'preview_text' => 'About '.$name, ...$extra]);
    }

    private function news(string $name, string $text, int $site): News
    {
        $code = strtolower($name);

        return News::query()->create(['name' => ['en' => $name], 'code' => ['en' => $code], 'preview_text' => ['en' => 'About '.$name], 'detail_text' => ['en' => $text], 'site_id' => $site]);
    }

    /**
     * Titles are the names and descriptions the texts, so a test says what it means.
     */
    private function plainTemplates(): void
    {
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{name}'], 'description' => ['en' => '{excerpt}']]);
    }

    /**
     * @param  list<string>  $links
     * @return array<string,list<string>>
     */
    private function checkLinks(array $links): array
    {
        $this->product('Desk', ['detail_text' => implode(' ', array_map(static fn (string $href): string => '<a href="'.$href.'">link</a>', $links))]);

        return $this->check();
    }

    public function test_results_stay_fresh_for_an_hour_unless_config_says_otherwise_and_not_a_moment_longer(): void
    {
        config(['atrium-seo.check' => []]);
        $this->freezeTime();
        $audit = app(Audit::class);
        $audit->run();

        $this->travel(59)->minutes();
        $this->assertFalse($audit->runIfStale(), 'Fresh after 59 minutes');

        $this->travel(1)->minutes();
        $this->assertTrue($audit->runIfStale(), 'Stale after exactly 60 minutes');

        $this->travel(61)->seconds();
        $this->assertFalse($audit->runIfStale(), 'The new run is fresh');
    }

    public function test_the_time_results_stay_fresh_is_set_in_config(): void
    {
        config(['atrium-seo.check.stale_minutes' => 5]);
        $this->freezeTime();
        $audit = app(Audit::class);
        $audit->run();

        $this->travel(4)->minutes();
        $this->assertFalse($audit->runIfStale());

        $this->travel(1)->minutes();
        $this->assertTrue($audit->runIfStale());
    }

    public function test_a_run_is_remembered_with_the_version_of_the_content_under_the_key_of_its_site(): void
    {
        $this->freezeTime();
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        Cache::forever('atrium.sitemap.version', 'pages');
        Cache::forever('atrium.seo.redirects.version', 'redirects');

        app(Audit::class)->run($second);

        $this->assertSame(['version' => 'pages.redirects', 'at' => now()->getTimestamp()], Cache::get('atrium.seo.check.'.$second->id));
        $this->assertNull(Cache::get('atrium.seo.check.1'));
        $this->assertTrue(app(Audit::class)->runIfStale(), 'The first site has not been checked');
    }

    public function test_a_run_puts_the_site_it_found_back(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        $sites = app(Sites::class);
        $sites->setCurrent($second);

        app(Audit::class)->run(Site::query()->findOrFail(1));

        $this->assertTrue($sites->isResolved());
        $this->assertSame($second->id, $sites->current()->id);
    }

    public function test_a_run_leaves_no_current_site_when_there_was_none(): void
    {
        $sites = app(Sites::class);
        $this->assertFalse($sites->isResolved());

        app(Audit::class)->run(Site::query()->findOrFail(1));

        $this->assertFalse($sites->isResolved());
    }

    public function test_a_run_sees_the_records_of_its_own_site_only(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        $this->news('First', '<img src="/a.png">', 1);
        $this->news('Other', '<img src="/b.png"><img src="/c.png">', $second->id);

        $this->assertSame(['image_no_alt' => ['Other: /b.png', 'Other: /c.png']], $this->check($second));
        $this->assertSame(['image_no_alt' => ['First: /a.png']], $this->check());
    }

    #[DefineEnvironment('withOddities')]
    public function test_the_site_is_put_back_when_the_check_fails(): void
    {
        $sites = app(Sites::class);
        $this->product('Broken', ['code' => 'boom']);

        try {
            app(Audit::class)->run(Site::query()->findOrFail(1));
            $this->fail('The check should have failed');
        } catch (RuntimeException $e) {
            $this->assertSame('No address for this page', $e->getMessage());
        }

        $this->assertFalse($sites->isResolved());
    }

    public function test_a_title_of_exactly_the_limit_is_fine_and_one_more_letter_is_too_long(): void
    {
        config(['atrium-seo.check' => []]);
        $this->plainTemplates();
        $this->product(str_repeat('a', 60));
        $long = str_repeat('b', 61);
        $this->product($long);

        $this->assertSame(['title_long' => [$long.': 61 > 60: '.$long]], $this->check());
    }

    public function test_the_length_of_a_title_counts_letters_not_bytes(): void
    {
        $this->plainTemplates();
        $this->product(str_repeat('я', 60));
        $long = str_repeat('ю', 61);
        $this->product($long);

        $this->assertSame(['title_long' => [$long.': 61 > 60: '.$long]], $this->check());
    }

    public function test_the_limit_of_a_title_is_a_whole_number(): void
    {
        $this->plainTemplates();
        config(['atrium-seo.check.title_max' => 10.9]);
        $this->product('Eleven char');

        $this->assertSame(['title_long' => ['Eleven char: 11 > 10: Eleven char']], $this->check());
    }

    public function test_titles_differing_in_case_only_are_the_same_title(): void
    {
        $this->plainTemplates();
        $this->product('Лампа', ['code' => 'lampa-1']);
        $this->product('ЛАМПА', ['code' => 'lampa-2']);
        $this->product('Desk');
        $this->product('DESK', ['code' => 'desk-big']);
        $this->product('Chair');

        $this->assertCount(4, $this->check()['title_duplicate']);
    }

    public function test_descriptions_differing_in_case_only_are_the_same_description(): void
    {
        $this->plainTemplates();
        $this->product('One', ['preview_text' => 'Свет в доме']);
        $this->product('Two', ['preview_text' => 'СВЕТ В ДОМЕ']);
        $this->product('Three', ['preview_text' => 'Warm']);
        $this->product('Four', ['preview_text' => 'WARM']);
        $this->product('Five', ['preview_text' => 'Cold']);

        $this->assertCount(4, $this->check()['description_duplicate']);
    }

    #[DefineEnvironment('withSeoNews')]
    public function test_the_text_of_the_seo_tab_belongs_to_the_default_language_only(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $news = SeoNews::query()->create(['name' => ['en' => 'New', 'de' => 'Neu'], 'code' => ['en' => 'new', 'de' => 'neu'], 'preview_text' => ['en' => 'Hello', 'de' => 'Hallo']]);
        $news->seoMeta()->create(['title' => str_repeat('T', 80)]);

        $this->check();

        $this->assertSame(['en'], Issue::query()->where('check', 'title_long')->pluck('language')->all());
    }

    public function test_the_texts_of_a_record_are_read_as_one_text(): void
    {
        $this->product('Lamp', ['preview_text' => '<img src="/a.png"', 'detail_text' => 'alt="A">']);

        $this->assertArrayNotHasKey('image_no_alt', $this->check());
    }

    public function test_tags_and_attributes_are_found_in_any_case(): void
    {
        $this->product('Lamp', ['detail_text' => '<IMG SRC="/A.PNG"><img src="/b.png" ALT="B"><IMG SRC="/c.png" ALT="C">']);

        $this->assertSame(['image_no_alt' => ['Lamp: /A.PNG']], $this->check());
    }

    public function test_a_picture_of_exactly_the_limit_is_fine_and_one_byte_more_is_heavy(): void
    {
        config(['atrium-seo.check' => []]);
        Storage::fake('public');
        Storage::disk('public')->put('catalog/exact.jpg', str_repeat('x', 300 * 1024));
        Storage::disk('public')->put('catalog/over.jpg', str_repeat('x', 300 * 1024 + 1));
        $this->product('Lamp', ['detail_text' => '<img src="/storage/catalog/exact.jpg" alt="A"><img src="/storage/catalog/over.jpg" alt="B">']);

        $this->assertSame(['image_heavy' => ['Lamp: /storage/catalog/over.jpg — 300 KB']], $this->check());
    }

    public function test_the_size_of_a_heavy_picture_is_rounded_to_the_nearest_kilobyte(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/megabyte.jpg', str_repeat('x', 1000 * 1024));
        Storage::disk('public')->put('catalog/down.jpg', str_repeat('x', 400 * 1024 + 300));
        Storage::disk('public')->put('catalog/up.jpg', str_repeat('x', 400 * 1024 + 600));
        $this->product('Lamp', ['detail_text' => '<img src="catalog/megabyte.jpg" alt="A"><img src="catalog/down.jpg" alt="B"><img src="catalog/up.jpg" alt="C">']);

        $this->assertSame(['image_heavy' => ['Lamp: catalog/megabyte.jpg — 1000 KB', 'Lamp: catalog/down.jpg — 400 KB', 'Lamp: catalog/up.jpg — 401 KB']], $this->check());
    }

    public function test_the_limit_of_picture_size_is_whole_kilobytes(): void
    {
        config(['atrium-seo.check.image_max_kb' => 0.5]);
        Storage::fake('public');
        Storage::disk('public')->put('catalog/tiny.jpg', str_repeat('x', 100));
        $this->product('Lamp', ['detail_text' => '<img src="catalog/tiny.jpg" alt="A">']);

        $this->assertSame(['image_heavy' => ['Lamp: catalog/tiny.jpg — 0 KB']], $this->check());
    }

    public function test_an_empty_picture_is_never_heavy(): void
    {
        config(['atrium-seo.check.image_max_kb' => 0]);
        Storage::fake('public');
        Storage::disk('public')->put('catalog/empty.jpg', '');
        $this->product('Lamp', ['detail_text' => '<img src="catalog/empty.jpg" alt="A">']);

        $this->assertArrayNotHasKey('image_heavy', $this->check());
    }

    public function test_the_same_picture_is_reported_once(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/big.jpg', str_repeat('x', 400 * 1024));
        $this->product('Lamp', ['preview_picture' => 'catalog/big.jpg', 'detail_text' => '<img src="catalog/big.jpg" alt="A"><img src="catalog/big.jpg" alt="B">']);

        $this->assertSame(['image_heavy' => ['Lamp: catalog/big.jpg — 400 KB']], $this->check());
    }

    public function test_a_picture_with_the_address_of_the_site_in_full_is_measured(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/big.jpg', str_repeat('x', 400 * 1024));
        $src = $this->root().'/storage/catalog/big.jpg';
        $this->product('Lamp', ['detail_text' => '<img src="'.$src.'" alt="A">']);

        $this->assertSame(['image_heavy' => ['Lamp: '.$src.' — 400 KB']], $this->check());
    }

    public function test_a_picture_of_another_host_is_not_measured_even_when_its_path_is_a_file_of_the_site(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/big.jpg', str_repeat('x', 400 * 1024));
        $this->product('Lamp', ['detail_text' => '<img src="https://cdn.example.net/storage/catalog/big.jpg" alt="A">'
            .'<img src="HTTPS://CDN.EXAMPLE.NET/storage/catalog/big.jpg" alt="B">'
            .'<img src="//cdn.example.net/storage/catalog/big.jpg" alt="C">'
            .'<img src="/storage/catalog/big.jpg?from=http://cdn.example.net" alt="D">']);

        $this->assertSame(['image_heavy' => ['Lamp: /storage/catalog/big.jpg?from=http://cdn.example.net — 400 KB']], $this->check());
    }

    public function test_an_address_of_the_site_without_a_path_is_not_a_picture(): void
    {
        Storage::fake('public');
        $this->product('Lamp', ['detail_text' => '<img src="'.$this->root().'" alt="A"><img src="?v=1" alt="B"><img alt="C">']);

        $this->assertSame([], $this->check());
    }

    public function test_links_that_lead_nowhere_are_skipped_and_the_links_after_them_are_still_checked(): void
    {
        $found = $this->checkLinks([
            '#top', '?page=2', '', 'MAILTO:shop@example.com', 'Tel:+15550100', 'javascript:void(0)', 'data:text/plain,x', 'sms:+15550100',
            'https://elsewhere.example/x', 'HTTPS://ELSEWHERE.EXAMPLE/y', '//elsewhere.example/z',
            '/nowhere', '/nowhere?next=http://x.example', '/later?u=tel:5',
        ]);

        $this->assertSame(['broken_link' => ['Desk: /nowhere', 'Desk: /nowhere?next=http://x.example', 'Desk: /later?u=tel:5']], $found);
    }

    public function test_a_link_is_read_without_the_spaces_around_it_and_reported_once(): void
    {
        $this->product('Lamp');

        $found = $this->checkLinks([' /nowhere ', '/nowhere', '/catalog/lamp ']);

        $this->assertSame(['broken_link' => ['Desk: /nowhere']], $found);
    }

    public function test_a_link_with_a_query_goes_to_the_page_it_is_a_query_of(): void
    {
        $this->product('Lamp');

        $found = $this->checkLinks(['/catalog?page=2', '/catalog/lamp?b=2&a=1', '/nowhere?page=2']);

        $this->assertSame(['broken_link' => ['Desk: /nowhere?page=2']], $found);
    }

    public function test_links_are_compared_in_lower_case_and_only_get_routes_without_parameters_count(): void
    {
        Route::get('/Seo-Mixed', static fn (): string => 'ok');
        Route::post('/seo-form', static fn (): string => 'ok');
        Route::get('/seo-item/{id}', static fn (): string => 'ok');
        $this->product('Lamp');

        $found = $this->checkLinks(['/Catalog/Lamp', '/seo-mixed', '/SEO-MIXED', '/seo-form', '/seo-item/5']);

        $this->assertSame(['broken_link' => ['Desk: /seo-form', 'Desk: /seo-item/5']], $found);
    }

    public function test_a_page_kept_out_of_the_sitemap_is_still_a_page(): void
    {
        $this->product('Hidden')->seoMeta()->create(['robots' => Robots::NOINDEX]);

        $found = $this->checkLinks(['/catalog/hidden', '/catalog/hidden-too']);

        $this->assertSame(['broken_link' => ['Desk: /catalog/hidden-too']], $found);
    }

    public function test_a_page_a_sitemap_source_adds_with_a_query_is_known_by_that_query_only(): void
    {
        config(['atrium-seo.sitemap_sources' => [ArchiveYears::class]]);

        $found = $this->checkLinks(['/en/archive?year=2026', '/en/archive?year=2025']);

        $this->assertSame(['broken_link' => ['Desk: /en/archive?year=2025']], $found);
    }

    public function test_the_pages_a_sitemap_source_adds_are_known_pages(): void
    {
        config(['atrium-seo.sitemap_sources' => [CalendarDays::class]]);

        $found = $this->checkLinks(['/en/calendar/2026-01-01', '/en/calendar/2026-01-02']);

        $this->assertSame(['broken_link' => ['Desk: /en/calendar/2026-01-02']], $found);
    }

    public function test_a_link_with_the_address_of_a_secure_site_in_full_or_without_the_scheme(): void
    {
        $this->app->instance('request', Request::create('https://localhost/'));
        $this->product('Lamp');

        $found = $this->checkLinks(['//localhost/nowhere', '//localhost/catalog/lamp', 'https://localhost/catalog/lamp', '//elsewhere.example/x']);

        $this->assertSame(['broken_link' => ['Desk: //localhost/nowhere']], $found);
    }

    #[DefineEnvironment('withOddities')]
    public function test_a_link_without_a_slash_on_the_home_page_is_read_from_the_root(): void
    {
        $this->product('Lamp');
        Oddity::query()->create(['name' => 'Home', 'code' => 'home', 'active' => true, 'preview_text' => 'Welcome', 'detail_text' => '<a href="about">About</a> <a href="catalog/lamp">Lamp</a>']);

        $this->check();

        $this->assertSame(['about'], Issue::query()->where('model', Oddity::class)->where('check', 'broken_link')->pluck('detail')->all());
    }

    /**
     * @return array<string,list<string>> the pages robots.txt closes, with the rule that did
     */
    private function closedBy(string $robots): array
    {
        Site::query()->whereKey(1)->update(['robots_txt' => $robots]);

        return array_map(static fn (array $found): array => $found, array_intersect_key($this->check(), ['noindex_in_sitemap' => 1]));
    }

    private function sitemapPages(): void
    {
        $this->product('Lamp');
        News::query()->create(['name' => ['en' => 'We moved'], 'code' => ['en' => 'we-moved'], 'preview_text' => ['en' => 'Text']]);
    }

    public function test_robots_txt_may_be_indented_and_commented(): void
    {
        $this->sitemapPages();

        $closed = $this->closedBy("  User-agent: *  # everyone\n\t Disallow: /news/ # not the news\n");

        $this->assertSame(['noindex_in_sitemap' => [$this->root().'/news/we-moved: robots.txt: Disallow: /news/']], $closed);
    }

    public function test_a_line_of_robots_txt_that_is_not_a_directive_is_ignored(): void
    {
        $this->sitemapPages();

        $this->assertSame([], $this->closedBy("User-agent: *\nsee Disallow: /news/\n"));
    }

    public function test_directives_of_robots_txt_are_read_in_any_case(): void
    {
        $this->sitemapPages();

        $closed = $this->closedBy("USER-AGENT: *\nDISALLOW: /News/\n");

        $this->assertSame(['noindex_in_sitemap' => [$this->root().'/news/we-moved: robots.txt: Disallow: /News/']], $closed);
    }

    public function test_robots_named_one_after_another_share_the_rules_below_them(): void
    {
        $this->sitemapPages();
        $rule = [$this->root().'/news/we-moved: robots.txt: Disallow: /news/'];

        $this->assertSame(['noindex_in_sitemap' => $rule], $this->closedBy("User-agent: Googlebot\nUser-agent: *\nDisallow: /news/\n"));
        $this->assertSame(['noindex_in_sitemap' => $rule], $this->closedBy("User-agent: *\nUser-agent: Googlebot\nDisallow: /news/\n"));
    }

    public function test_the_rules_of_another_robot_that_follow_a_group_are_not_for_everyone(): void
    {
        $this->sitemapPages();

        $closed = $this->closedBy("User-agent: *\nDisallow: /catalog/\nUser-agent: Googlebot\nDisallow: /news/\n");

        $this->assertSame(['noindex_in_sitemap' => [$this->root().'/catalog/lamp: robots.txt: Disallow: /catalog/']], $closed);
        $this->assertSame([], $this->closedBy("User-agent: *\nAllow: /\nUser-agent: Googlebot\nDisallow: /news/\n"));
    }

    public function test_rules_that_come_before_any_robot_is_named_are_for_nobody(): void
    {
        $this->sitemapPages();

        $this->assertSame([], $this->closedBy("Disallow: /news/\nUser-agent: Googlebot\nDisallow: /catalog/\n"));
    }

    public function test_every_rule_of_robots_txt_counts(): void
    {
        $this->sitemapPages();

        $closed = $this->closedBy("User-agent: *\nDisallow: /news/\nDisallow: /catalog/\n");

        $this->assertEqualsCanonicalizing([
            $this->root().'/news/we-moved: robots.txt: Disallow: /news/',
            $this->root().'/catalog/lamp: robots.txt: Disallow: /catalog/',
        ], $closed['noindex_in_sitemap']);
    }

    public function test_a_page_closed_by_several_rules_is_reported_once_with_the_first(): void
    {
        $this->sitemapPages();

        $closed = $this->closedBy("User-agent: *\nDisallow: /news/we\nDisallow: /news/\n");

        $this->assertSame(['noindex_in_sitemap' => [$this->root().'/news/we-moved: robots.txt: Disallow: /news/we']], $closed);
    }

    public function test_the_problems_are_saved_in_batches_of_five_hundred(): void
    {
        $this->product('Lamp', ['detail_text' => str_repeat('<img src="/a.png">', 501)]);
        $rows = [];
        DB::listen(static function ($query) use (&$rows): void {
            if (str_starts_with($query->sql, 'insert into "seo_issues"') || str_starts_with($query->sql, 'insert into `seo_issues`')) {
                $rows[] = substr_count($query->sql, '(?');
            }
        });

        $count = app(Audit::class)->run(Site::query()->findOrFail(1));

        $this->assertSame(501, $count);
        $this->assertSame([500, 1], $rows);
        $this->assertSame(501, Issue::query()->count());
    }

    #[DefineEnvironment('withOddities')]
    public function test_a_name_a_page_and_a_detail_longer_than_their_columns_are_cut_by_letters(): void
    {
        Oddity::query()->create(['name' => 'Short', 'code' => 'long-name', 'active' => true, 'detail_text' => '<img src="/a.png">']);
        Oddity::query()->create(['name' => 'Far', 'code' => 'long-url', 'active' => true, 'detail_text' => '<img src="/b.png">']);
        $href = '/'.str_repeat('ж', 3000);
        Oddity::query()->create(['name' => 'Linker', 'code' => 'linker', 'active' => true, 'detail_text' => '<a href="'.$href.'">x</a>']);

        $this->check();
        $issues = Issue::query()->where('model', Oddity::class);

        $this->assertSame(str_repeat('я', 255), (clone $issues)->where('detail', '/a.png')->sole()->label);
        $this->assertSame(mb_substr(url('/oddities/'.str_repeat('é', 3000)), 0, 2048), (clone $issues)->where('detail', '/b.png')->sole()->url);
        $this->assertSame(mb_substr($href, 0, 2048), (clone $issues)->where('check', 'broken_link')->sole()->detail);
    }

    public function test_when_the_tables_of_the_module_are_not_there_the_check_reports_it_and_goes_on(): void
    {
        $this->onlyOnSqlite();
        Schema::rename('seo_issues', 'seo_issues_away');
        $this->product('Lamp', ['detail_text' => '<img src="/a.png">']);
        Exceptions::fake();

        $this->assertSame(1, app(Audit::class)->run(Site::query()->findOrFail(1)));

        Exceptions::assertReported(QueryException::class);
        $this->assertNotNull(Cache::get('atrium.seo.check.1'));
    }

    public function test_a_site_in_a_folder_is_checked_by_the_paths_inside_the_folder(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2', 'robots_txt' => "User-agent: *\nDisallow: /news/\n"]);
        URL::forceRootUrl('http://localhost/site2');
        $this->news('Fresh', '<a href="/news/fresh">self</a> <a href="/site2/news/fresh">folder</a> <a href="http://localhost/site2/news/fresh">full</a> <a href="/gone">gone</a>', $second->id);

        $found = $this->check($second);

        $this->assertSame(['noindex_in_sitemap' => ['http://localhost/site2/news/fresh: robots.txt: Disallow: /news/']], array_intersect_key($found, ['noindex_in_sitemap' => 1]));
        $this->assertSame(['broken_link' => ['Fresh: /site2/news/fresh', 'Fresh: /gone']], array_intersect_key($found, ['broken_link' => 1]));
    }

    protected function withSeoNews(Application $app): void
    {
        $app['config']->set('atrium.public_urls', [SeoNews::class => ['prefix' => 'news']]);
    }

    protected function withOddities(Application $app): void
    {
        $app['config']->set('atrium.resources', [Oddity::class]);
        $app['config']->set('atrium.public_urls.'.Oddity::class, ['prefix' => 'oddities']);
    }
}
