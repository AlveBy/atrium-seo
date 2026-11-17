<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\View;

use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\Sites;
use Atrium\Seo\Meta\Meta;
use Atrium\Seo\Meta\MetaResolver;
use Atrium\Seo\Meta\Templates;
use Atrium\Seo\Meta\Variables;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Support\Locale;
use Atrium\Seo\Support\Paths;
use Atrium\Theme\Pages;
use Atrium\Theme\RecordView;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Fills the page context from what the theme's controller passed to its
 * layout and gives the layout the meta back: the address's own meta from
 * SEO → Pages first, then the record's (its SEO tab, then the module's
 * template), then what the theme had. hreflang is printed by the module
 * itself, across all sites, so the theme's own list is emptied.
 */
final class ThemeLayoutComposer
{
    public function __construct(
        private readonly SeoPage $page,
        private readonly MetaResolver $resolver,
        private readonly Templates $templates,
        private readonly Variables $variables,
        private readonly Sites $sites,
        private readonly ContentLanguages $languages,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $data = $view->getData();

        if (! $this->page->filled) {
            $this->fill($data);
        }

        $site = $this->sites->current();
        $language = app()->getLocale();
        $record = $this->page->record;

        $passed = new Meta(
            (string) ($data['metaTitle'] ?? $data['title'] ?? ''),
            (string) ($data['metaDescription'] ?? ''),
            (string) ($data['metaKeywords'] ?? ''),
            is_string($data['ogImage'] ?? null) && $data['ogImage'] !== '' ? MetaResolver::absolute($data['ogImage']) : null,
        );

        $own = $this->resolver->forPath($this->path(), $language, $site) ?? new Meta;

        if ($record !== null) {
            $meta = $own->over($this->resolver->forRecord($record, $language, $site, $language === $this->languages->default()))->over($passed);
        } else {
            $meta = $own->over($this->listMeta($passed, $language))->over($passed);
            $meta->image ??= MetaResolver::defaultImage($site);
        }

        $canonical = (string) ($data['canonical'] ?? '');

        if ($this->page->pageNumber > 1) {
            $meta->title .= ' — '.Locale::trans('atrium-seo::seo.page_number', $language, ['number' => $this->page->pageNumber]);
            // Page 2 of a list is a page of its own, whatever the list's canonical says.
            $meta->canonical = $canonical !== '' ? $canonical : null;
        }

        $meta->canonical ??= $canonical !== '' ? $canonical : null;
        $this->page->meta = $meta;

        $view->with([
            'metaTitle' => $meta->title,
            'metaDescription' => $meta->description,
            'metaKeywords' => $meta->keywords,
            'ogImage' => $meta->image,
            'canonical' => $meta->canonical,
            'alternates' => [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function fill(array $data): void
    {
        $page = $this->page;
        $view = $data['page'] ?? null;
        $record = $view instanceof RecordView ? $view->record : ($view instanceof Model ? $view : null);
        $route = (string) $this->request->route()?->getName();

        $page->record = $record;
        $page->pageNumber = max(1, (int) $this->request->query('page', '1'));
        $page->breadcrumbs(is_array($data['breadcrumbs'] ?? null) ? $data['breadcrumbs'] : []);
        $prefix = $this->request->route('prefix');
        $page->prefix = is_string($prefix) ? $prefix : null;

        $page->kind = match (true) {
            str_ends_with($route, '.home') => SeoPage::HOME,
            $record !== null && array_key_exists('sections', $data) => SeoPage::SECTION,
            $record !== null => SeoPage::RECORD,
            str_ends_with($route, '.index') => SeoPage::INDEX,
            default => SeoPage::PAGE,
        };

        if ($page->prefix !== null) {
            $pages = app(Pages::class);
            $page->models = array_keys($pages->module($page->prefix));
        }

        $canonical = (string) ($data['canonical'] ?? '');
        $page->url = $canonical === '' ? null : strtok($canonical, '?');

        // A later page of a list: the same page number in every language.
        $alternates = array_filter((array) ($data['alternates'] ?? []), 'is_string');

        if ($alternates === [] && $page->kind === SeoPage::INDEX) {
            $alternates = array_filter((array) ($data['switch'] ?? []), 'is_string');
        }

        if ($page->pageNumber > 1) {
            $alternates = array_map(fn (string $url): string => strtok($url, '?').'?page='.$page->pageNumber, $alternates);
        }

        $page->alternates = $alternates;
        $page->filled = true;
    }

    /**
     * The home page and a module's list: "{name} | {site}" from the
     * templates of the "home" and "index" kinds.
     */
    private function listMeta(Meta $passed, string $language): Meta
    {
        $kind = $this->page->kind === SeoPage::HOME ? 'home' : 'index';
        $site = $this->sites->current();
        $extra = ['name' => $passed->title];

        return new Meta(
            $this->variables->render($this->templates->default($kind, 'title', $language), null, $language, $site, $extra),
            $this->variables->render($this->templates->default($kind, 'description', $language), null, $language, $site, $extra),
        );
    }

    private function path(): string
    {
        $query = $this->request->query();
        unset($query['page']);

        return Paths::normalize($this->request->getPathInfo().($query === [] ? '' : '?'.http_build_query($query)));
    }
}
