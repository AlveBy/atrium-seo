<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Models;

use Atrium\Admin\Columns\DateColumn;
use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An address visitors asked for and did not find, counted once per path:
 * the most requested first, each with a button that opens a redirect from it.
 *
 * @property int $id
 * @property int|null $site_id
 * @property string $path
 * @property string $path_hash
 * @property int $hits
 * @property string|null $referer
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
class NotFound extends Model implements Administrable
{
    use BelongsToSite, InteractsWithAdmin;

    protected $table = 'seo_not_found';

    protected $fillable = ['site_id', 'path', 'path_hash', 'hits', 'referer', 'first_seen_at', 'last_seen_at'];

    /** @var list<string> */
    protected $appends = ['create_redirect', 'create_redirect_url'];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public static function getAdminLabel(): string
    {
        return __('atrium-seo::seo.modules.not_found');
    }

    public static function getAdminRoutePrefix(): string
    {
        return 'seo_not_found';
    }

    public static function getDefaultSortColumn(): string
    {
        return 'hits';
    }

    public static function isCreatableInAdmin(): bool
    {
        return false;
    }

    public static function isEditableInAdmin(): bool
    {
        return false;
    }

    public function getCreateRedirectAttribute(): string
    {
        return __('atrium-seo::seo.actions.create_redirect');
    }

    public function getCreateRedirectUrlAttribute(): string
    {
        return route('admin.seo_redirects.create', ['source' => $this->path]);
    }

    public static function getAdministrationFields(): array
    {
        return [];
    }

    public static function getAdministrationListColumns(): array
    {
        return [
            TextColumn::make('path')->label(__('atrium-seo::seo.fields.path'))->sortable()->searchable(),
            TextColumn::make('hits')->label(__('atrium-seo::seo.fields.hits'))->sortable(),
            TextColumn::make('referer')->label(__('atrium-seo::seo.fields.referer'))->urlFrom('referer')->limit(60),
            DateColumn::make('last_seen_at')->label(__('atrium-seo::seo.fields.last_seen_at'))->sortable(),
            TextColumn::make('create_redirect')->label('')->urlFrom('create_redirect_url'),
        ];
    }
}
