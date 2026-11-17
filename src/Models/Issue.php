<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Models;

use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Resources\ResourceRegistry;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Atrium\Seo\Audit\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One problem the SEO check found on a page: a missing title, an image
 * without alt, a broken link. The list is the check's report; each row
 * links to the record to fix. "?check=title_missing" shows one kind.
 *
 * @property int $id
 * @property int|null $site_id
 * @property string $check
 * @property string|null $language
 * @property string $label
 * @property string|null $url
 * @property string|null $detail
 * @property string|null $model
 * @property int|null $record_id
 */
class Issue extends Model implements Administrable
{
    use BelongsToSite, InteractsWithAdmin;

    protected $table = 'seo_issues';

    protected $fillable = ['site_id', 'check', 'language', 'label', 'url', 'detail', 'model', 'record_id'];

    /** @var list<string> */
    protected $appends = ['check_label', 'check_url', 'edit_url'];

    public static function getAdminLabel(): string
    {
        return __('atrium-seo::seo.modules.issues');
    }

    public static function getAdminRoutePrefix(): string
    {
        return 'seo_issues';
    }

    public static function getDefaultSortColumn(): string
    {
        return 'check';
    }

    public static function getDefaultSortDirection(): string
    {
        return 'asc';
    }

    public static function getAdminPerPage(): int
    {
        return 50;
    }

    public static function isCreatableInAdmin(): bool
    {
        return false;
    }

    public static function isEditableInAdmin(): bool
    {
        return false;
    }

    /**
     * Opening the list runs the check when its results are stale, and
     * "?check=" keeps one kind of problem.
     */
    public function scopeAdminList(Builder $query): Builder
    {
        app(Audit::class)->runIfStale();

        $check = request()->query('check');

        return is_string($check) && $check !== '' ? $query->where('check', $check) : $query;
    }

    public function getCheckLabelAttribute(): string
    {
        $label = __('atrium-seo::seo.checks.'.$this->check);

        return $this->language ? $label.' ('.$this->language.')' : $label;
    }

    public function getCheckUrlAttribute(): string
    {
        return route('admin.seo_issues.index', ['check' => $this->check]);
    }

    /**
     * The form of the record to fix, or the page itself when it is not one.
     */
    public function getEditUrlAttribute(): ?string
    {
        if ($this->model !== null && $this->record_id !== null) {
            $key = app(ResourceRegistry::class)->keyOf($this->model);

            if ($key !== null) {
                return route("admin.{$key}.edit", $this->record_id);
            }
        }

        return $this->url;
    }

    public static function getAdministrationFields(): array
    {
        return [];
    }

    public static function getAdministrationListColumns(): array
    {
        return [
            TextColumn::make('check_label')->label(__('atrium-seo::seo.fields.check'))->urlFrom('check_url'),
            TextColumn::make('label')->label(__('atrium-seo::seo.fields.page'))->urlFrom('edit_url')->searchable(),
            TextColumn::make('url')->label(__('atrium-seo::seo.fields.url'))->urlFrom('url')->limit(60),
            TextColumn::make('detail')->label(__('atrium-seo::seo.fields.detail'))->limit(120),
        ];
    }
}
