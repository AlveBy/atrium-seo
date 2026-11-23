<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Models;

use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Fields\ImageField;
use Atrium\Admin\Fields\SelectField;
use Atrium\Admin\Fields\TextareaField;
use Atrium\Admin\Fields\TextField;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Atrium\Admin\Traits\Translatable;
use Atrium\Seo\Robots;
use Atrium\Seo\Support\Paths;
use Illuminate\Database\Eloquent\Model;

/**
 * The meta of one address: a page that is not a record (the home page, a
 * module's list, a page of the application), or a record whose meta should
 * read differently from its SEO tab. Wins over everything else.
 *
 * @property int $id
 * @property int|null $site_id
 * @property string $path
 * @property string $path_hash
 * @property string|null $og_image
 * @property string|null $robots
 * @property string|null $canonical_url
 */
class PageMeta extends Model implements Administrable
{
    use BelongsToSite, InteractsWithAdmin, Translatable;

    protected $table = 'seo_pages';

    /** @var array<int,string> */
    protected array $translatable = ['title', 'description', 'keywords'];

    protected $fillable = ['site_id', 'path', 'path_hash', 'title', 'description', 'keywords', 'og_image', 'robots', 'canonical_url'];

    protected static function booted(): void
    {
        static::saving(static function (self $page): void {
            $page->path = Paths::normalize($page->path);
            $page->path_hash = Paths::hash($page->path);
        });
    }

    public static function getAdminLabel(): string
    {
        return __('atrium-seo::seo.modules.pages');
    }

    public static function getAdminCreateLabel(): string
    {
        return __('atrium-seo::seo.create.page');
    }

    /** Service records, not content: no custom fields, no link target. */
    public static function isContentModule(): bool
    {
        return false;
    }

    public static function getAdminRoutePrefix(): string
    {
        return 'seo_pages';
    }

    public static function getDefaultSortColumn(): string
    {
        return 'path';
    }

    public static function getDefaultSortDirection(): string
    {
        return 'asc';
    }

    public static function getAdministrationFields(): array
    {
        return [
            TextField::make('path')->label(__('atrium-seo::seo.fields.path'))->hint(__('atrium-seo::seo.hints.path'))->required()
                ->rules(['string', 'max:2048']),
            TextField::make('title')->label(__('atrium-seo::seo.fields.title'))->hint(__('atrium-seo::seo.templates.variables')),
            TextareaField::make('description')->label(__('atrium-seo::seo.fields.description')),
            TextField::make('keywords')->label(__('atrium-seo::seo.fields.keywords')),
            ImageField::make('og_image')->label(__('atrium-seo::seo.fields.og_image')),
            SelectField::make('robots')->label(__('atrium-seo::seo.fields.robots'))->options(Robots::options())->clearable(),
            TextField::make('canonical_url')->label(__('atrium-seo::seo.fields.canonical'))->rules(['string', 'max:2048']),
        ];
    }

    public static function getAdministrationListColumns(): array
    {
        return [
            TextColumn::make('path')->label(__('atrium-seo::seo.fields.path'))->sortable()->searchable(),
            TextColumn::make('title')->label(__('atrium-seo::seo.fields.title'))->limit(80)->searchable(),
            TextColumn::make('robots')->label(__('atrium-seo::seo.fields.robots')),
        ];
    }
}
