<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Fields\SelectField;
use Atrium\Admin\Models\SeoMeta;
use Atrium\Catalog\Models\Product;
use Atrium\Faq\Models\Category;
use Atrium\Faq\Models\Question;
use Atrium\Seo\IndexNow\IndexNow;
use Atrium\Seo\Meta\Templates;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Redirects\RedirectMap;
use Atrium\Seo\Robots;
use Atrium\Seo\SeoPage;
use Atrium\Seo\SeoServiceProvider;
use Atrium\Seo\Tests\Fixtures\Brochure;
use Atrium\Seo\Tests\TestCase;
use Atrium\Theme\Pages;
use Atrium\Theme\RecordView;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

/**
 * What the module wires into the app: bindings, publishing, hooks, the
 * menu and the markup it takes over from the FAQ and the reviews.
 */
final class SeoProviderTest extends TestCase
{
    private const VERSION = 'atrium.sitemap.version';

    public function test_the_pages_context_the_templates_the_redirects_and_indexnow_live_as_long_as_a_request(): void
    {
        foreach ([SeoPage::class, Templates::class, RedirectMap::class, IndexNow::class] as $class) {
            $this->assertSame(app($class), app($class), $class);
        }

        $before = array_map(static fn (string $class): object => app($class), [SeoPage::class, Templates::class, RedirectMap::class, IndexNow::class]);
        $this->app->forgetScopedInstances();

        foreach ([SeoPage::class, Templates::class, RedirectMap::class, IndexNow::class] as $index => $class) {
            $this->assertNotSame($before[$index], app($class), $class);
        }
    }

    public function test_a_finished_request_leaves_the_next_one_a_clean_context_but_keeps_indexnow(): void
    {
        $before = array_map(static fn (string $class): object => app($class), [SeoPage::class, Templates::class, RedirectMap::class, IndexNow::class]);

        Event::dispatch(new RequestHandled(request(), response('')));

        foreach ([SeoPage::class, Templates::class, RedirectMap::class] as $index => $class) {
            $this->assertNotSame($before[$index], app($class), $class);
        }
        $this->assertSame($before[3], app(IndexNow::class));
    }

    public function test_the_config_and_the_lang_files_can_be_published(): void
    {
        $config = ServiceProvider::pathsToPublish(SeoServiceProvider::class, 'atrium-seo-config');
        $lang = ServiceProvider::pathsToPublish(SeoServiceProvider::class, 'atrium-seo-lang');

        $this->assertCount(1, $config);
        $this->assertSame(realpath(__DIR__.'/../../config/atrium-seo.php'), realpath((string) array_key_first($config)));
        $this->assertSame(config_path('atrium-seo.php'), array_values($config)[0]);

        $this->assertCount(1, $lang);
        $this->assertSame(realpath(__DIR__.'/../../lang'), realpath((string) array_key_first($lang)));
        $this->assertSame($this->app->langPath('vendor/atrium-seo'), array_values($lang)[0]);
    }

    public function test_the_commands_the_translations_and_the_views_are_there(): void
    {
        $commands = array_keys(\Artisan::all());

        $this->assertContains('atrium:seo:check', $commands);
        $this->assertContains('atrium:seo:redirects:import', $commands);
        $this->assertNotSame('atrium-seo::seo.menu', __('atrium-seo::seo.menu'));
        $this->assertTrue(View::exists('atrium-seo::head'));
    }

    public function test_the_head_is_followed_by_the_modules_markup_and_the_title_has_no_suffix_of_its_own(): void
    {
        $this->assertSame('', config('atrium.seo.title_suffix'));
        app(SeoPage::class)->meta->robots = Robots::NOINDEX;

        $this->assertSame('<!-- x -->'.PHP_EOL.'<meta name="robots" content="noindex, follow">'.PHP_EOL, Hook::apply('atrium.head', '<!-- x -->'.PHP_EOL));
    }

    public function test_the_robots_field_is_added_to_the_seo_fields_after_the_others(): void
    {
        $expected = SelectField::make('seo_robots')
            ->label(__('atrium-seo::seo.fields.robots'))
            ->hint(__('atrium-seo::seo.hints.robots'))
            ->options(Robots::options())
            ->clearable()
            ->toArray();
        $first = SelectField::make('a');
        $second = SelectField::make('b');

        $fields = Hook::apply('atrium.seo.fields', [$first, $second]);

        $this->assertCount(3, $fields);
        $this->assertSame($first, $fields[0]);
        $this->assertSame($second, $fields[1]);
        $this->assertSame($expected, $fields[2]->toArray());
    }

    public function test_a_record_closed_to_search_engines_leaves_the_sitemap_through_the_filter(): void
    {
        $brochure = Brochure::query()->create(['name' => 'Brochure', 'code' => 'b']);
        $entries = [['loc' => 'https://example.com/b', 'lastmod' => null, 'alternates' => []]];

        $this->assertSame([$this->root().'/storage/brochures/b.jpg', $this->root().'/storage/brochures/b-back.jpg'], Hook::apply('atrium.sitemap.entries', $entries, $brochure)[0]['images']);

        $brochure->seoMeta()->create(['robots' => Robots::NOINDEX]);
        $this->assertSame([], Hook::apply('atrium.sitemap.entries', $entries, $brochure->fresh()));
        $this->assertSame(1, SeoMeta::query()->count());
    }

    public function test_the_screens_of_the_module_become_one_group_of_the_menu(): void
    {
        $menu = [
            ['label' => 'Dashboard', 'route' => 'admin.dashboard'],
            ['label' => 'Pages', 'route' => 'admin.seo_pages.index'],
            ['label' => 'Close', 'separator' => true],
            ['label' => 'Other', 'route' => 'admin.seo_other.index'],
            ['label' => 'Odd', 'route' => 5],
            ['label' => 'Redirects', 'route' => 'admin.seo_redirects.index'],
            ['label' => 'Prefixed', 'route' => 'xadmin.seo_issues.index'],
            ['label' => 'Suffixed', 'route' => 'admin.seo_issues.index.extra'],
            ['label' => 'Products', 'route' => 'admin.catalog_products.index'],
        ];

        $this->assertSame([
            $menu[0],
            ['label' => 'atrium-seo::seo.menu', 'icon' => 'search', 'children' => [$menu[5], $menu[1]]],
            $menu[2], $menu[3], $menu[4], $menu[6], $menu[7], $menu[8],
        ], array_values(SeoServiceProvider::menu($menu)));

        $this->assertSame(
            array_values(SeoServiceProvider::menu($menu)),
            Hook::apply('atrium.admin.menu', $menu),
        );
    }

    public function test_a_menu_that_has_only_the_modules_screens_becomes_the_group_alone(): void
    {
        $menu = SeoServiceProvider::menu([['label' => 'Templates', 'route' => 'admin.seo_templates.index']]);

        $this->assertSame([['label' => 'atrium-seo::seo.menu', 'icon' => 'search', 'children' => [['label' => 'Templates', 'route' => 'admin.seo_templates.index']]]], $menu);
        $this->assertSame([], SeoServiceProvider::menu([]));
    }

    public function test_the_sitemap_is_rebuilt_whenever_a_page_or_a_redirect_is_saved_or_deleted(): void
    {
        $changes = [
            'page saved' => static fn (): PageMeta => PageMeta::query()->create(['path' => '/a']),
            'redirect saved' => static fn (): Redirect => Redirect::query()->create(['source' => '/a', 'target' => '/b']),
        ];

        foreach ($changes as $name => $change) {
            Cache::forever(self::VERSION, 'old');
            $record = $change();
            $this->assertNotSame('old', Cache::get(self::VERSION), $name);

            Cache::forever(self::VERSION, 'old');
            $record->delete();
            $this->assertNotSame('old', Cache::get(self::VERSION), str_replace('saved', 'deleted', $name));
        }
    }

    public function test_a_category_page_hands_its_questions_to_the_modules_graph_and_prints_no_markup_of_its_own(): void
    {
        $category = Category::query()->create(['name' => ['en' => 'Delivery'], 'code' => ['en' => 'delivery']]);
        Question::query()->create(['category_id' => $category->id, 'question' => ['en' => 'How long?'], 'answer' => ['en' => 'Two days.']]);
        $page = new RecordView($category, app(Pages::class));

        $view = $this->composed('atrium-faq::category', ['page' => $page, 'jsonLd' => '<script>']);

        $this->assertSame('', $view->getData()['jsonLd']);
        $this->assertSame($view->getData()['questions'], app(SeoPage::class)->context['faq.questions']);
        $this->assertSame(['How long?'], app(SeoPage::class)->context['faq.questions']->map(fn (Question $question): string => $question->translated('question'))->all());
    }

    public function test_the_faq_page_hands_the_questions_of_every_group_to_the_modules_graph(): void
    {
        config(['atrium.public_urls.'.Category::class => ['prefix' => 'faq']]);
        $delivery = Category::query()->create(['name' => ['en' => 'Delivery'], 'code' => ['en' => 'delivery']]);
        $returns = Category::query()->create(['name' => ['en' => 'Returns'], 'code' => ['en' => 'returns']]);
        foreach ([[$delivery, 'How long?'], [$delivery, 'How much?'], [$returns, 'Can I return it?']] as [$category, $text]) {
            Question::query()->create(['category_id' => $category->id, 'question' => ['en' => $text], 'answer' => ['en' => 'Yes.']]);
        }
        $pages = app(Pages::class);
        $records = new LengthAwarePaginator([new RecordView($delivery, $pages), new RecordView($returns, $pages)], 2, 10);

        $view = $this->composed('atrium-faq::index', ['records' => $records, 'jsonLd' => '<script>']);

        $this->assertSame('', $view->getData()['jsonLd']);
        $this->assertCount(2, $view->getData()['groups']);
        $this->assertSame(
            ['How long?', 'How much?', 'Can I return it?'],
            app(SeoPage::class)->context['faq.questions']->map(fn (Question $question): string => $question->translated('question'))->all(),
        );
    }

    public function test_a_faq_page_without_groups_hands_over_no_questions(): void
    {
        $this->composed('atrium-faq::index', []);

        $this->assertSame([], app(SeoPage::class)->context['faq.questions']->all());
    }

    public function test_the_reviews_of_the_page_itself_leave_their_markup_to_the_modules_graph(): void
    {
        $product = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'active' => true]);
        $other = Product::query()->create(['name' => 'Desk', 'code' => 'desk', 'active' => true]);

        // The layout has not filled the page yet: a theme page's record counts.
        $this->onRoute('shop.atrium.theme.page');
        $this->assertFalse($this->composed('atrium-reviews::block', ['record' => $product])->getData()['withJsonLd']);

        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => null])->getData());
        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', [])->getData());

        $this->onRoute('shop.atrium.theme.page.extra');
        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => $product])->getData());

        $this->onRoute('shop.other');
        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => $product])->getData());

        $this->onRoute(null);
        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => $product])->getData());

        // Once it is filled, the page's own record decides.
        $this->onRoute('shop.other');
        app(SeoPage::class)->show($product);
        $this->assertFalse($this->composed('atrium-reviews::block', ['record' => $product->fresh()])->getData()['withJsonLd']);
        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => $other])->getData());
        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => null])->getData());

        $this->onRoute('shop.atrium.theme.page');
        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => $other])->getData());
    }

    public function test_a_page_filled_without_a_record_leaves_no_review_markup_out(): void
    {
        $product = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'active' => true]);
        $this->onRoute('shop.atrium.theme.page');
        app(SeoPage::class)->kind(SeoPage::INDEX);

        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => $product])->getData());
        $this->assertArrayNotHasKey('withJsonLd', $this->composed('atrium-reviews::block', ['record' => null])->getData());
    }

    public function test_the_options_of_robots_are_translated(): void
    {
        $this->assertSame([
            Robots::INDEX => __('atrium-seo::seo.robots.index'),
            Robots::NOINDEX => __('atrium-seo::seo.robots.noindex'),
            Robots::NONE => __('atrium-seo::seo.robots.none'),
        ], Robots::options());
        $this->assertNotContains('atrium-seo::seo.robots.index', Robots::options());
        $this->assertSame(['index, follow', 'noindex, follow', 'noindex, nofollow'], array_keys(Robots::options()));
    }

    public function test_noindex_is_found_in_any_case_and_only_in_what_is_set(): void
    {
        $this->assertTrue(Robots::isNoindex('noindex, follow'));
        $this->assertTrue(Robots::isNoindex('NoIndex'));
        $this->assertTrue(Robots::isNoindex('follow, NOINDEX'));
        $this->assertFalse(Robots::isNoindex('index, follow'));
        $this->assertFalse(Robots::isNoindex(''));
        $this->assertFalse(Robots::isNoindex(null));
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function composed(string $name, array $data): ViewInstance
    {
        $view = view($name, $data);
        app('view')->callComposer($view);

        return $view;
    }

    private function onRoute(?string $name): void
    {
        request()->setRouteResolver(static fn (): ?Route => $name === null ? null : (new Route('GET', '/x', static fn () => ''))->name($name));
    }
}
