<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Meta;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\SeoMeta;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Scopes\SiteScope;
use Atrium\Admin\Support\MediaUrl;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Support\Paths;
use Atrium\Seo\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * The meta of a record's page or of any page by its address.
 *
 * A record: what the editor wrote on its SEO tab (HasAdminSeo), else its
 * module's template, else its name and text. The SEO tab has one text for
 * all languages, so it serves the site's default language and records that
 * are not translated; translated pages in other languages take the
 * template, written per language.
 *
 * A page by address (SEO → Pages) overrides both: it is the editor saying
 * "this address, exactly this".
 */
final class MetaResolver
{
    public function __construct(
        private readonly Templates $templates,
        private readonly Variables $variables,
    ) {}

    public function forRecord(Model $record, string $language, Site $site, bool $defaultLanguage = true): Meta
    {
        $own = $this->own($record, $language, $site, $defaultLanguage);

        $title = $own->title !== '' ? $own->title : $this->template($record, 'title', $language, $site);
        $description = $own->description !== '' ? $own->description : $this->template($record, 'description', $language, $site);
        $keywords = $own->keywords !== '' ? $own->keywords : $this->template($record, 'keywords', $language, $site);

        if ($title === '') {
            $title = $this->variables->value('name', $record, $language, $site);
        }

        if ($description === '') {
            $description = $this->variables->value('excerpt', $record, $language, $site);
        }

        return new Meta(
            $title,
            $description,
            $keywords,
            $own->image ?? $this->image($record) ?? self::defaultImage($site),
            $own->robots,
            $own->canonical,
        );
    }

    /**
     * Meta set for an address in the panel, or null when there is none.
     */
    public function forPath(string $path, string $language, Site $site): ?Meta
    {
        try {
            $row = PageMeta::query()->withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)
                ->where('path_hash', Paths::hash(Paths::normalize($path)))
                ->first();
        } catch (QueryException) {
            return null;
        }

        if (! $row instanceof PageMeta) {
            return null;
        }

        return new Meta(
            $this->variables->render(Text::of($row, 'title', $language), null, $language, $site),
            Text::of($row, 'description', $language),
            Text::of($row, 'keywords', $language),
            MediaUrl::to($row->og_image),
            $row->robots ?: null,
            $row->canonical_url ?: null,
        );
    }

    public static function defaultImage(Site $site): ?string
    {
        $image = Setting::get('seo.og_image', null, $site);

        return is_string($image) ? self::absolute(MediaUrl::to($image)) : null;
    }

    public static function absolute(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // url() leaves an address with a scheme (or "//") as it is.
        return url($url);
    }

    private function template(Model $record, string $field, string $language, Site $site): string
    {
        $template = $this->templates->for($record::class, $field, $language, $site);

        return $template === '' ? '' : $this->variables->render($template, $record, $language, $site);
    }

    private function own(Model $record, string $language, Site $site, bool $defaultLanguage): Meta
    {
        $meta = $record->getRelationValue('seoMeta');

        if (! $meta instanceof SeoMeta) {
            return new Meta;
        }

        // One text for every language: right for the default one and for
        // records that are written in one language only.
        $texts = $defaultLanguage || ! Text::isTranslatable($record, 'name');

        return new Meta(
            $texts ? $this->variables->render((string) $meta->title, $record, $language, $site) : '',
            $texts ? $this->variables->render((string) $meta->description, $record, $language, $site) : '',
            $texts ? trim((string) $meta->keywords) : '',
            self::absolute(MediaUrl::to($meta->og_image)),
            $meta->robots ?: null,
            $meta->canonical_url ?: null,
        );
    }

    private function image(Model $record): ?string
    {
        foreach (['detail_picture', 'preview_picture', 'picture', 'image', 'photo', 'cover'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (($url = self::absolute(MediaUrl::to($value))) !== null) {
                return $url;
            }
        }

        return null;
    }
}
