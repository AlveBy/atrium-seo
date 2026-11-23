<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Models;

use Atrium\Admin\Actions\CreateFromRecord;
use Atrium\Admin\Columns\BooleanColumn;
use Atrium\Admin\Columns\DateColumn;
use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Fields\CheckboxField;
use Atrium\Admin\Fields\SelectField;
use Atrium\Admin\Fields\TextareaField;
use Atrium\Admin\Fields\TextField;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Atrium\Seo\Redirects\RedirectMap;
use Atrium\Seo\Support\Paths;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * An old address that sends visitors and search engines to a new one.
 * "/old/*" covers everything under /old; 410 says the page is gone for good.
 *
 * @property int $id
 * @property int|null $site_id
 * @property string $source
 * @property string $source_hash
 * @property string|null $target
 * @property int $status
 * @property bool $active
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property string|null $note
 */
class Redirect extends Model implements Administrable
{
    use BelongsToSite, InteractsWithAdmin;

    public const STATUSES = [301, 302, 307, 308, 410];

    protected $table = 'seo_redirects';

    protected $fillable = ['site_id', 'source', 'source_hash', 'target', 'status', 'active', 'note'];

    /** @var array<string,mixed> */
    protected $attributes = ['status' => 301, 'active' => true, 'hits' => 0];

    /** @var list<string> */
    protected $appends = ['chain'];

    protected function casts(): array
    {
        return ['status' => 'integer', 'active' => 'boolean', 'hits' => 'integer', 'last_hit_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(static function (self $redirect): void {
            $redirect->source = self::cleanSource($redirect->source);
            $redirect->source_hash = Paths::hash($redirect->source);
            $redirect->target = self::cleanTarget($redirect->target, (int) $redirect->status);
        });

        static::saved(static function (self $redirect): void {
            RedirectMap::flush();

            // The address works now: it is no longer a 404.
            NotFound::query()->withoutGlobalScopes()->where('site_id', $redirect->site_id)
                ->where('path_hash', Paths::hash($redirect->source))->delete();
        });

        static::deleted(static fn () => RedirectMap::flush());
    }

    /**
     * "/Old-Page/?b=2&a=1#top" → "/Old-Page?a=1&b=2"; a trailing "*" stays.
     */
    public static function cleanSource(string $source): string
    {
        $source = trim($source);
        $wildcard = str_ends_with($source, '*');
        $path = Paths::normalize(rtrim($source, '*'));

        return $wildcard ? rtrim($path, '/').'/*' : $path;
    }

    public static function cleanTarget(?string $target, int $status): ?string
    {
        $target = trim((string) $target);

        if ($status === 410 || $target === '') {
            return null;
        }

        return preg_match('#^https?://#i', $target) ? $target : '/'.ltrim($target, '/');
    }

    public static function getAdminLabel(): string
    {
        return __('atrium-seo::seo.modules.redirects');
    }

    public static function getAdminCreateLabel(): string
    {
        return __('atrium-seo::seo.create.redirect');
    }

    /** Service records, not content: no custom fields, no link target. */
    public static function isContentModule(): bool
    {
        return false;
    }

    public static function getAdminRoutePrefix(): string
    {
        return 'seo_redirects';
    }

    /**
     * The 404 log's "create a redirect" button opens the form with ?source=.
     */
    public static function getAdministrationActions(): array
    {
        return [
            CreateFromRecord::make('copy')->label(__('atrium-seo::seo.actions.copy'))
                ->fill(['source' => 'source', 'target' => 'target', 'status' => 'status']),
        ];
    }

    /**
     * @return array<int|string,string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (self::STATUSES as $status) {
            $options[$status] = $status.' — '.__('atrium-seo::seo.statuses.'.$status);
        }

        return $options;
    }

    public static function getAdministrationFields(): array
    {
        return [
            TextField::make('source')->label(__('atrium-seo::seo.fields.source'))->hint(__('atrium-seo::seo.hints.source'))->required()
                ->maxLength(2048),
            TextField::make('target')->label(__('atrium-seo::seo.fields.target'))->hint(__('atrium-seo::seo.hints.target'))
                ->maxLength(2048)->rules(['required_unless:status,410']),
            SelectField::make('status')->label(__('atrium-seo::seo.fields.status'))->options(self::statusOptions())->default(301)->required(),
            CheckboxField::make('active')->label(__('atrium::fields.standard.active'))->default(true),
            TextareaField::make('note')->label(__('atrium-seo::seo.fields.note')),
        ];
    }

    public static function getAdministrationListColumns(): array
    {
        return [
            TextColumn::make('source')->label(__('atrium-seo::seo.fields.source'))->sortable()->searchable(),
            TextColumn::make('target')->label(__('atrium-seo::seo.fields.target'))->searchable(),
            TextColumn::make('status')->label(__('atrium-seo::seo.fields.status'))->sortable(),
            TextColumn::make('chain')->label(__('atrium-seo::seo.fields.chain')),
            TextColumn::make('hits')->label(__('atrium-seo::seo.fields.hits'))->sortable(),
            DateColumn::make('last_hit_at')->label(__('atrium-seo::seo.fields.last_hit_at'))->sortable(),
            BooleanColumn::make('active')->label(__('atrium::fields.standard.active')),
        ];
    }

    /**
     * Refuses a redirect that would send visitors round in a circle.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function transformAdminData(array $data): array
    {
        $status = (int) ($data['status'] ?? $this->status);
        $source = self::cleanSource((string) ($data['source'] ?? $this->source ?? ''));
        $target = self::cleanTarget(isset($data['target']) ? (string) $data['target'] : null, $status);

        if ($target !== null && (bool) ($data['active'] ?? true)) {
            $loop = app(RedirectMap::class)->loopWith($source, $target, $this->exists ? $this->id : null, $this->site_id ?? null);

            if ($loop !== null) {
                throw ValidationException::withMessages([
                    'target' => __('atrium-seo::seo.errors.loop', ['chain' => implode(' → ', $loop)]),
                ]);
            }
        }

        return $data;
    }

    /**
     * "→ /c → /d" when the target redirects again, "loop" when it never ends.
     */
    public function getChainAttribute(): string
    {
        if (! $this->active || $this->target === null) {
            return '';
        }

        $chain = app(RedirectMap::class)->chainFrom($this->source, $this->site_id);

        if ($chain['loop']) {
            return __('atrium-seo::seo.chain.loop');
        }

        return implode(' → ', array_slice($chain['hops'], 1));
    }
}
