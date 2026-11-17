<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Models;

use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Fields\SelectField;
use Atrium\Admin\Fields\TextareaField;
use Atrium\Admin\Fields\TextField;
use Atrium\Admin\Resources\ResourceRegistry;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Atrium\Admin\Traits\Translatable;
use Atrium\Seo\Meta\Templates;
use Illuminate\Database\Eloquent\Model;

/**
 * The meta of every record of a module, written once with variables:
 * "{name}[ for {price}] | {site}". One per module and site; "*" is every
 * module without one of its own.
 *
 * @property int $id
 * @property int|null $site_id
 * @property string $model
 */
class Template extends Model implements Administrable
{
    use BelongsToSite, InteractsWithAdmin, Translatable;

    protected $table = 'seo_templates';

    /** @var array<int,string> */
    protected array $translatable = ['title', 'description', 'keywords'];

    protected $fillable = ['site_id', 'model', 'title', 'description', 'keywords'];

    /** @var list<string> */
    protected $appends = ['module'];

    protected static function booted(): void
    {
        static::saved(static fn () => app(Templates::class)->flush());
        static::deleted(static fn () => app(Templates::class)->flush());
    }

    public static function getAdminLabel(): string
    {
        return __('atrium-seo::seo.modules.templates');
    }

    public static function getAdminCreateLabel(): string
    {
        return __('atrium-seo::seo.create.template');
    }

    public static function getAdminRoutePrefix(): string
    {
        return 'seo_templates';
    }

    /**
     * Every module of the panel, by class, and "*" for all of them.
     *
     * @return array<string,string>
     */
    protected static function moduleOptions(): array
    {
        $options = [Templates::ANY => __('atrium-seo::seo.templates.any')];

        foreach (app(ResourceRegistry::class)->all() as $model) {
            // The panel's own records (sites, languages, administrators)
            // and this module's have no public pages.
            if (! str_starts_with($model, 'Atrium\\Seo\\') && ! str_starts_with($model, 'Atrium\\Admin\\')) {
                $options[$model] = $model::getAdminLabel();
            }
        }

        return $options;
    }

    public function getModuleAttribute(): string
    {
        return self::moduleOptions()[$this->model] ?? (string) $this->model;
    }

    public static function getAdministrationFields(): array
    {
        $hint = __('atrium-seo::seo.templates.variables');

        return [
            SelectField::make('model')->label(__('atrium-seo::seo.fields.module'))->options(self::moduleOptions())->required()->searchable()
                ->rules(['string', 'max:255']),
            TextField::make('title')->label(__('atrium-seo::seo.fields.title'))->hint($hint),
            TextareaField::make('description')->label(__('atrium-seo::seo.fields.description'))->hint($hint),
            TextField::make('keywords')->label(__('atrium-seo::seo.fields.keywords')),
        ];
    }

    public static function getAdministrationListColumns(): array
    {
        return [
            TextColumn::make('module')->label(__('atrium-seo::seo.fields.module')),
            TextColumn::make('title')->label(__('atrium-seo::seo.fields.title'))->limit(80)->searchable(),
        ];
    }
}
