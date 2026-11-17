<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo;

use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Seo\Meta\Meta;
use Illuminate\Database\Eloquent\Model;

/**
 * The page being drawn, as the SEO module sees it: what record it shows,
 * its address in every language, the breadcrumbs and the meta. Filled in by
 * itself on the theme's pages; a page an application draws itself fills it
 * in its controller:
 *
 *     app(SeoPage::class)->show($product)->breadcrumbs([...]);
 *
 * Schema providers read it to decide what to say about the page.
 */
final class SeoPage
{
    public const HOME = 'home';

    public const INDEX = 'index';

    public const SECTION = 'section';

    public const RECORD = 'record';

    public const PAGE = 'page';

    public ?Model $record = null;

    public string $kind = self::PAGE;

    public ?string $url = null;

    public ?string $prefix = null;

    /** @var array<int,class-string> the models of the module a list page shows */
    public array $models = [];

    /** @var array<string,string> language code => address on this site */
    public array $alternates = [];

    /** @var array<int,array{label:string,url:string}> above the page, the home page not included */
    public array $breadcrumbs = [];

    public Meta $meta;

    public int $pageNumber = 1;

    /** @var array<int,array<string,mixed>> nodes added by the page itself */
    public array $schema = [];

    public bool $filled = false;

    /** @var array<string,mixed> what views on the page tell the providers, such as the questions a FAQ shows */
    public array $context = [];

    public function __construct()
    {
        $this->meta = new Meta;
    }

    public function show(Model $record): self
    {
        $this->record = $record;
        $this->kind = self::RECORD;
        $this->filled = true;

        return $this;
    }

    public function kind(string $kind): self
    {
        $this->kind = $kind;
        $this->filled = true;

        return $this;
    }

    /**
     * @param  array<int,mixed>  $breadcrumbs  as the theme gave them: [label, url] pairs are kept
     */
    public function breadcrumbs(array $breadcrumbs): self
    {
        $this->breadcrumbs = array_values(array_filter($breadcrumbs, static fn (mixed $crumb): bool => is_array($crumb)
            && is_string($crumb['label'] ?? null) && $crumb['label'] !== '' && is_string($crumb['url'] ?? null)));

        return $this;
    }

    /**
     * @param  array<string,mixed>  $node  a schema.org node, "@type" included
     */
    public function addSchema(array $node): self
    {
        $this->schema[] = $node;

        return $this;
    }

    public function site(): Site
    {
        return app(Sites::class)->current();
    }

    public function language(): string
    {
        return app()->getLocale();
    }

    /**
     * The address of the page, without the page number for search engines'
     * ids: page 2 of a list is still the list.
     */
    public function canonical(): string
    {
        return $this->meta->canonical ?? $this->url ?? url()->current();
    }

    public function homeUrl(): string
    {
        return $this->site()->url().'/';
    }

    /** The node of what the page is about: the product, the article. */
    public function mainEntityId(): string
    {
        return strtok($this->canonical(), '?').'#main';
    }

    public function organizationId(): string
    {
        return $this->homeUrl().'#organization';
    }

    public function websiteId(): string
    {
        return $this->homeUrl().'#website';
    }

    /**
     * Whether the page shows a record of this class (or a subclass).
     *
     * @param  class-string  $class
     */
    public function shows(string $class): bool
    {
        return $this->record instanceof $class;
    }
}
