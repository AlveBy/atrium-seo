<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo;

use Atrium\Admin\Facades\Hook;
use Atrium\Admin\Fields\SelectField;
use Atrium\Admin\Modules\Modules;
use Atrium\Admin\Support\Sitemap;
use Atrium\Seo\Console\CheckCommand;
use Atrium\Seo\Console\ImportRedirectsCommand;
use Atrium\Seo\Http\Middleware\HandleRedirects;
use Atrium\Seo\IndexNow\IndexNow;
use Atrium\Seo\IndexNow\RecordSaved;
use Atrium\Seo\Meta\Templates;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Redirects\RedirectMap;
use Atrium\Seo\Sitemap\RecordEntries;
use Atrium\Seo\View\ThemeLayoutComposer;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

/**
 * The models, the menu and the migrations come from extra.atrium in
 * composer.json, once `atrium:module:install alveby/atrium-seo` has run;
 * until then the module does nothing to the site.
 */
final class SeoServiceProvider extends ServiceProvider
{
    public const PACKAGE = 'alveby/atrium-seo';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/atrium-seo.php', 'atrium-seo');

        $this->app->scoped(SeoPage::class);
        $this->app->scoped(Templates::class);
        $this->app->scoped(RedirectMap::class);
        $this->app->scoped(IndexNow::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'atrium-seo');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'atrium-seo');

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, ImportRedirectsCommand::class]);
            $this->publishes([__DIR__.'/../config/atrium-seo.php' => config_path('atrium-seo.php')], 'atrium-seo-config');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/atrium-seo')], 'atrium-seo-lang');
        }

        if (! $this->app->make(Modules::class)->isInstalled(self::PACKAGE)) {
            return;
        }

        // The templates carry the site's name themselves ("{name} | {site}").
        config(['atrium.seo.title_suffix' => '']);

        Hook::filter('atrium.head', static fn (string $html): string => $html.view('atrium-seo::head')->render());
        Hook::filter('atrium.seo.fields', static fn (array $fields): array => [...$fields, self::robotsField()]);
        Hook::filter('atrium.sitemap.entries', RecordEntries::class);
        Hook::filter('atrium.admin.menu', static fn (array $menu): array => self::menu($menu));
        Hook::on('atrium.record.saved', RecordSaved::class);

        View::composer('atrium-theme::layout', ThemeLayoutComposer::class);

        // A long-lived worker (Octane, tests) serves many requests with one
        // container: each page starts with a context of its own.
        Event::listen(RequestHandled::class, function (): void {
            foreach ([SeoPage::class, Templates::class, RedirectMap::class] as $class) {
                $this->app->forgetInstance($class);
            }
        });

        $flush = static fn () => app(Sitemap::class)->flush();
        PageMeta::saved($flush);
        PageMeta::deleted($flush);
        Redirect::saved($flush);
        Redirect::deleted($flush);

        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            $this->app->make(Kernel::class)->pushMiddleware(HandleRedirects::class);
            $this->replaceModuleMarkup();
        });
    }

    private static function robotsField(): SelectField
    {
        return SelectField::make('seo_robots')
            ->label(__('atrium-seo::seo.fields.robots'))
            ->hint(__('atrium-seo::seo.hints.robots'))
            ->options(Robots::options())
            ->clearable();
    }

    /**
     * The module's screens as one group of the menu, the check first.
     *
     * @param  array<int,array<string,mixed>>  $menu
     * @return array<int,array<string,mixed>>
     */
    public static function menu(array $menu): array
    {
        $order = array_flip(['seo_issues', 'seo_redirects', 'seo_not_found', 'seo_redirect_imports', 'seo_pages', 'seo_templates']);
        $children = [];
        $at = count($menu);

        foreach ($menu as $index => $item) {
            if (preg_match('/^admin\.(seo_[a-z_]+)\.index$/', (string) ($item['route'] ?? ''), $m) && isset($order[$m[1]])) {
                $children[$order[$m[1]]] = $item;
                $at = min($at, $index);
                unset($menu[$index]);
            }
        }

        if ($children === []) {
            return $menu;
        }

        ksort($children);
        $group = ['label' => 'atrium-seo::seo.menu', 'icon' => 'search', 'children' => array_values($children)];
        array_splice($menu, $at, 0, [$group]);

        return $menu;
    }

    /**
     * The FAQ and the reviews print schema.org markup of their own; with
     * this module it goes into the page's one graph instead.
     */
    private function replaceModuleMarkup(): void
    {
        View::composer(['atrium-faq::index', 'atrium-faq::category'], function (ViewInstance $view): void {
            $data = $view->getData();
            $questions = $data['questions'] ?? collect($data['groups'] ?? [])->flatMap(static fn (array $group): iterable => $group['questions']);

            $this->app->make(SeoPage::class)->context['faq.questions'] = $questions;
            $view->with('jsonLd', '');
        });

        View::composer('atrium-reviews::block', function (ViewInstance $view): void {
            $record = $view->getData()['record'] ?? null;
            $page = $this->app->make(SeoPage::class);
            // The block is drawn before the layout fills the page context.
            $same = $page->filled
                ? $page->record instanceof Model && $record instanceof Model && $record->is($page->record)
                : $record instanceof Model && str_ends_with((string) request()->route()?->getName(), 'atrium.theme.page');

            if ($same) {
                $view->with('withJsonLd', false);
            }
        });
    }
}
