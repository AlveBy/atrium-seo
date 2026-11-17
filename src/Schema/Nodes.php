<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Support\MediaUrl;
use Atrium\Seo\Meta\MetaResolver;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Support\Text;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Small pieces the providers share: a record's text in the page's
 * language, its pictures as absolute addresses, the publisher.
 */
final class Nodes
{
    public static function text(Model $record, string $attribute, string $language, ?int $limit = null): string
    {
        $text = Text::of($record, $attribute, $language);

        if ($text === '' && Text::isTranslatable($record, $attribute)) {
            $text = Text::of($record, $attribute, (string) config('app.fallback_locale'));
        }

        return $limit === null ? trim(strip_tags($text)) : Text::excerpt($text, $limit);
    }

    public static function name(Model $record, string $language): string
    {
        return self::text($record, 'name', $language) ?: self::text($record, 'title', $language);
    }

    public static function description(Model $record, string $language): string
    {
        foreach (['preview_text', 'detail_text', 'description', 'text'] as $attribute) {
            if (($text = self::text($record, $attribute, $language, 300)) !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @return array<int,string>
     */
    public static function images(Model $record): array
    {
        $images = [];

        foreach (['detail_picture', 'preview_picture', 'picture', 'image', 'photo', 'cover', 'photos', 'gallery'] as $attribute) {
            foreach ((array) $record->getAttribute($attribute) as $value) {
                $path = is_array($value) ? ($value['path'] ?? $value['url'] ?? null) : $value;
                $url = MetaResolver::absolute(MediaUrl::to($path));

                if ($url !== null) {
                    $images[] = $url;
                }
            }
        }

        return array_values(array_unique($images));
    }

    public static function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    public static function organizationName(SeoPage $page): string
    {
        $name = Setting::get('seo.organization_name', null, $page->site());

        return is_string($name) && trim($name) !== '' ? trim($name) : (string) ($page->site()->name ?: config('app.name'));
    }

    private static function logo(SeoPage $page): ?string
    {
        $logo = Setting::get('seo.logo', null, $page->site());

        return MetaResolver::absolute(MediaUrl::to($logo));
    }

    /**
     * The site's owner as a publisher, inline: search engines want its name
     * and logo where it is referred to.
     *
     * @return array<string,mixed>
     */
    public static function publisher(SeoPage $page): array
    {
        return self::filled([
            '@type' => 'Organization',
            '@id' => $page->organizationId(),
            'name' => self::organizationName($page),
            'url' => $page->homeUrl(),
            'logo' => self::logo($page) === null ? null : ['@type' => 'ImageObject', 'url' => self::logo($page)],
        ]);
    }

    /**
     * Drops empty values, so no node says "price": "".
     *
     * @param  array<string,mixed>  $node
     * @return array<string,mixed>
     */
    public static function filled(array $node): array
    {
        return array_filter($node, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
