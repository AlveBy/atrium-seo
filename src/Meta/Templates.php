<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Meta;

use Atrium\Admin\Models\Site;
use Atrium\Admin\Scopes\SiteScope;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Support\Locale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * The template of a title, description or keywords for a module's pages in
 * a language, from the most particular to the most general:
 *
 * 1. the module's template on this site (SEO → Templates in the panel);
 * 2. the site's template for every module ("*");
 * 3. `atrium.seo.templates` in the application's config;
 * 4. the default of the module's kind, translated in lang/{language}/templates.php.
 */
final class Templates
{
    public const FIELDS = ['title', 'description', 'keywords'];

    public const ANY = '*';

    /** @var array<int,Collection<string,Template>> */
    private array $rows = [];

    public function for(string $model, string $field, string $language, Site $site): string
    {
        foreach ([$model, self::ANY] as $key) {
            $row = $this->rows($site)->get($key);

            if ($row !== null && ($text = trim((string) $row->getTranslation($field, $language, false))) !== '') {
                return $text;
            }
        }

        $configured = config('atrium.seo.templates.'.$model.'.'.$field);

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return $this->default($this->kind($model), $field, $language);
    }

    public function kind(string $model): string
    {
        $kind = config('atrium-seo.kinds.'.$model);

        return is_string($kind) && $kind !== '' ? $kind : 'default';
    }

    /**
     * The shipped default of a kind, "default" where the kind has none.
     */
    public function default(string $kind, string $field, string $language): string
    {
        foreach ([$kind, 'default'] as $candidate) {
            $key = "atrium-seo::templates.{$candidate}.{$field}";
            $text = Locale::trans($key, $language);

            if ($text !== $key) {
                return $text;
            }
        }

        return '';
    }

    public function flush(): void
    {
        $this->rows = [];
    }

    /**
     * @return Collection<string,Template>
     */
    private function rows(Site $site): Collection
    {
        if (isset($this->rows[$site->id])) {
            return $this->rows[$site->id];
        }

        try {
            $rows = Template::query()->withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->get()->keyBy('model');
        } catch (QueryException) {
            $rows = new Collection;
        }

        return $this->rows[$site->id] = $rows;
    }
}
