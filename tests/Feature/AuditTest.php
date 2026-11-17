<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Audit\Audit;
use Atrium\Seo\Audit\PageCollector;
use Atrium\Seo\Models\Issue;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Redirects\RedirectMap;
use Atrium\Seo\Tests\Fixtures\Leaflet;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * SEO → Check: each problem is a row with a link to the record to fix.
 * Every check is shown firing on a bad page and staying quiet on a good one.
 */
final class AuditTest extends TestCase
{
    /**
     * check => labels of the pages it was found on, after a run.
     *
     * @return array<string,list<string>>
     */
    private function check(): array
    {
        app(Audit::class)->run(Site::query()->findOrFail(1));

        $found = [];

        foreach (Issue::query()->orderBy('id')->get() as $issue) {
            $found[$issue->check][] = $issue->detail === null ? $issue->label : $issue->label.': '.$issue->detail;
        }

        return $found;
    }

    private function product(string $name, array $extra = []): Product
    {
        return Product::query()->create(['name' => $name, 'code' => strtolower(preg_replace('/\W+/', '-', $name)), 'preview_text' => 'About '.$name, ...$extra]);
    }

    public function test_a_good_site_has_no_problems(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/small.jpg', str_repeat('x', 10 * 1024));
        $this->product('Lamp', [
            'preview_picture' => 'catalog/small.jpg',
            'detail_text' => '<p><img src="/storage/catalog/small.jpg" alt="Lamp"> <a href="/catalog">All</a> <a href="/catalog/lamp">Self</a>'
                .' <a href="https://elsewhere.example/x">Out</a> <a href="mailto:shop@example.com">Mail</a> <a href="#top">Top</a> <a href="">Empty</a></p>',
        ]);
        News::query()->create(['name' => ['en' => 'We moved'], 'code' => ['en' => 'we-moved'], 'preview_text' => ['en' => 'To a bigger hall']]);

        $this->assertSame([], $this->check());
    }

    public function test_long_titles(): void
    {
        $this->product(str_repeat('Long ', 12));
        $this->product('Short');

        $found = $this->check();

        $this->assertSame(['title_long'], array_keys($found));
        $this->assertCount(1, $found['title_long']);
        $this->assertStringContainsString('> 60', $found['title_long'][0]);
    }

    public function test_the_limit_of_a_title_is_set_in_config(): void
    {
        config(['atrium-seo.check.title_max' => 10]);
        $this->product('Short');

        $this->assertSame(['title_long'], array_keys($this->check()));
    }

    public function test_titles_and_descriptions_used_twice(): void
    {
        News::query()->create(['name' => ['en' => 'Same'], 'code' => ['en' => 'same-1'], 'preview_text' => ['en' => 'One']]);
        News::query()->create(['name' => ['en' => 'Same'], 'code' => ['en' => 'same-2'], 'preview_text' => ['en' => 'Two']]);
        News::query()->create(['name' => ['en' => 'First'], 'code' => ['en' => 'first'], 'preview_text' => ['en' => 'Shared text']]);
        News::query()->create(['name' => ['en' => 'Second'], 'code' => ['en' => 'second'], 'preview_text' => ['en' => 'Shared text']]);

        $found = $this->check();
        $root = $this->root();

        $this->assertSame([
            'Same: '.$root.'/news/same-2',
            'Same: '.$root.'/news/same-1',
        ], $found['title_duplicate']);
        $this->assertSame([
            'First: '.$root.'/news/second',
            'Second: '.$root.'/news/first',
        ], $found['description_duplicate']);
        $this->assertSame(['title_duplicate', 'description_duplicate'], array_keys($found));
    }

    public function test_missing_description_and_missing_name(): void
    {
        // The default product description always says "Buy {name}".
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{name} | {site}'], 'description' => ['en' => '{excerpt}']]);
        $this->product('Quiet', ['preview_text' => null]);
        $nameless = Product::query()->create(['name' => '', 'code' => 'nameless', 'preview_text' => 'Text']);

        $found = $this->check();
        $url = $this->root().'/catalog/nameless';

        $this->assertEqualsCanonicalizing(['title_missing', 'description_missing', 'h1_missing'], array_keys($found));
        $this->assertSame([$url], $found['title_missing']);
        $this->assertSame(['Quiet'], $found['description_missing']);
        $this->assertSame([$url], $found['h1_missing']);

        $issue = Issue::query()->where('check', 'h1_missing')->sole();
        $this->assertSame(route('admin.catalog_products.edit', $nameless->id), $issue->edit_url);
    }

    public function test_pictures_without_alt(): void
    {
        $this->product('Lamp', ['detail_text' => '<img src="/a.png"><img src="/b.png" alt=" "><img alt="C" src="/c.png"><img src=\'/d.png\' alt=\'D\'>']);

        $this->assertSame(['image_no_alt' => ['Lamp: /a.png', 'Lamp: /b.png']], $this->check());
    }

    public function test_heavy_pictures_of_the_text_and_of_the_record(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/big.jpg', str_repeat('x', 400 * 1024));
        Storage::disk('public')->put('catalog/small.jpg', str_repeat('x', 10 * 1024));
        $this->product('Lamp', [
            'preview_picture' => 'catalog/big.jpg',
            'detail_picture' => 'catalog/small.jpg',
            'detail_text' => '<img src="/storage/catalog/big.jpg" alt="Big"><img src="https://cdn.example.net/huge.jpg" alt="Elsewhere"><img src="/missing.jpg" alt="Gone">',
        ]);

        $this->assertSame(['image_heavy' => ['Lamp: /storage/catalog/big.jpg — 400 KB', 'Lamp: catalog/big.jpg — 400 KB']], $this->check());

        config(['atrium-seo.check.image_max_kb' => 500]);
        $this->assertSame([], $this->check());
    }

    public function test_heavy_pictures_and_links_to_files_of_the_public_folder(): void
    {
        $dir = public_path('seo-test-'.getmypid());
        @mkdir($dir);
        file_put_contents($dir.'/big.jpg', str_repeat('x', 400 * 1024));
        file_put_contents($dir.'/price.pdf', 'pdf');
        $this->beforeApplicationDestroyed(static function () use ($dir): void {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        });
        $folder = '/'.basename($dir);
        $this->product('Lamp', ['detail_text' => '<img src="'.$folder.'/big.jpg" alt="Big"> <a href="'.$folder.'/price.pdf">price</a> <a href="'.$folder.'/old.pdf">old</a>']);

        $this->assertSame([
            'image_heavy' => ['Lamp: '.$folder.'/big.jpg — 400 KB'],
            'broken_link' => ['Lamp: '.$folder.'/old.pdf'],
        ], $this->check());
    }

    public function test_broken_internal_links(): void
    {
        Redirect::query()->create(['source' => '/old-catalog', 'target' => '/catalog']);
        $this->product('Lamp');
        $this->product('Desk', ['detail_text' => '<a href="/catalog/lamp">ok</a> <a href="lamp">relative ok</a> <a href="/old-catalog">redirected</a>'
            .' <a href="/sitemap.xml">route</a> <a href="/nowhere">x</a> <a href="nope">y</a> <a href="'.$this->root().'/gone?x=1">z</a>'
            .' <a href="//elsewhere.example/a">other site</a> <a href="tel:+15550100">call</a>']);

        $this->assertSame(['broken_link' => ['Desk: /nowhere', 'Desk: nope', 'Desk: '.$this->root().'/gone?x=1']], $this->check());
    }

    #[DefineEnvironment('withLeaflets')]
    public function test_the_pages_a_module_lists_for_the_sitemap_are_checked(): void
    {
        Language::query()->create(['code' => 'de', 'name' => 'Deutsch']);
        $lamp = Leaflet::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'active' => true]);
        Leaflet::query()->create(['name' => 'Hidden', 'code' => 'hidden', 'active' => false]);

        $leaflets = [];

        foreach (app(PageCollector::class)->pages() as $page) {
            if ($page['record'] instanceof Leaflet) {
                $leaflets[] = [$page['record']->getKey(), $page['language'], $page['url']];
            }
        }

        // No German address, and the hidden one is not in the sitemap.
        $this->assertSame([[$lamp->id, 'en', $this->root().'/leaflets/lamp']], $leaflets);
    }

    public function test_pages_of_the_sitemap_closed_in_robots_txt(): void
    {
        Site::query()->whereKey(1)->update(['robots_txt' => "User-agent: Googlebot\nDisallow: /catalog/\n\nUser-agent: *\nDisallow: /news/\nDisallow:\nAllow: /\n"]);
        $this->product('Lamp');
        News::query()->create(['name' => ['en' => 'We moved'], 'code' => ['en' => 'we-moved'], 'preview_text' => ['en' => 'Text']]);

        $found = $this->check();

        $this->assertSame(['noindex_in_sitemap'], array_keys($found));
        $this->assertSame([$this->root().'/news/we-moved: robots.txt: Disallow: /news/'], $found['noindex_in_sitemap']);
        $this->assertSame($this->root().'/news/we-moved', Issue::query()->sole()->edit_url);
    }

    public function test_a_wildcard_rule_of_robots_txt(): void
    {
        Site::query()->whereKey(1)->update(['robots_txt' => "User-agent: *\nDisallow: /*lamp$\n"]);
        $this->product('Lamp');
        $this->product('Lampshade');

        $this->assertSame(['noindex_in_sitemap' => [$this->root().'/catalog/lamp: robots.txt: Disallow: /*lamp$']], $this->check());
    }

    public function test_redirect_chains_and_loops(): void
    {
        $a = Redirect::query()->create(['source' => '/a', 'target' => '/b']);
        Redirect::query()->create(['source' => '/b', 'target' => '/c']);

        $this->assertSame(['redirect_chain' => ['/a: /a → /b → /c']], $this->check());
        $this->assertSame(route('admin.seo_redirects.edit', $a->id), Issue::query()->sole()->edit_url);

        Redirect::query()->where('source', '/b')->update(['target' => '/a']);
        RedirectMap::flush();

        $this->assertSame(['redirect_loop' => ['/a: /a → /b → /a', '/b: /b → /a → /b']], $this->check());
    }

    public function test_the_list_runs_the_check_and_filters_by_kind(): void
    {
        $this->product(str_repeat('Long ', 12));
        $this->product('Lamp', ['detail_text' => '<img src="/a.png">']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin/seo_issues')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.total', 2)
                ->where('items.data.0.check_label', __('atrium-seo::seo.checks.image_no_alt').' (en)')
                ->where('items.data.0.check_url', route('admin.seo_issues.index', ['check' => 'image_no_alt']))
                ->where('items.data.0.edit_url', route('admin.catalog_products.edit', Product::query()->where('code', 'lamp')->value('id'))));

        $this->get('/admin/seo_issues?check=title_long')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('items.total', 1)->where('items.data.0.check', 'title_long'));

        $this->get('/admin/seo_issues/create')->assertForbidden();
    }

    public function test_the_check_runs_again_only_when_something_changed(): void
    {
        $audit = app(Audit::class);

        $this->assertTrue($audit->runIfStale());
        $this->assertFalse($audit->runIfStale());

        $this->product('Lamp', ['detail_text' => '<img src="/a.png">']);
        $this->assertTrue($audit->runIfStale());
        $this->assertSame(1, Issue::query()->count());

        $this->travel(61)->minutes();
        $this->assertTrue($audit->runIfStale());

        config(['atrium-seo.check.inline' => false]);
        $this->travel(61)->minutes();
        $this->assertFalse($audit->runIfStale());
    }

    public function test_each_site_is_checked_on_its_own(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        Redirect::query()->create(['source' => '/a', 'target' => '/b', 'site_id' => $second->id]);
        Redirect::query()->create(['source' => '/b', 'target' => '/c', 'site_id' => $second->id]);

        $this->assertSame([], $this->check());
        $this->assertSame(1, app(Audit::class)->run($second));
        $this->assertSame($second->id, Issue::query()->withoutGlobalScopes()->sole()->site_id);
    }

    public function test_the_command(): void
    {
        $this->product('Lamp', ['detail_text' => '<img src="/a.png">']);
        Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);

        $this->artisan('atrium:seo:check')->expectsOutput('Demo shop: 1')->expectsOutput('Second: 1')->assertSuccessful();
        $this->artisan('atrium:seo:check', ['--site' => 1])->expectsOutput('Demo shop: 1')->doesntExpectOutput('Second: 1')->assertSuccessful();
        $this->artisan('atrium:seo:check', ['--site' => 999])->expectsOutput('No such site.')->assertFailed();
    }

    protected function withLeaflets(Application $app): void
    {
        $app['config']->set('atrium.resources', [Leaflet::class]);
        // The theme draws them too: each page is checked once.
        $app['config']->set('atrium.public_urls.'.Leaflet::class, ['prefix' => 'leaflets']);
    }
}
