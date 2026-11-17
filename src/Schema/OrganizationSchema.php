<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use Atrium\Admin\Facades\Setting;
use Atrium\Seo\Contracts\SchemaProvider;
use Atrium\Seo\SeoPage;

/**
 * Who owns the site and the site itself, on the home page: Organization
 * (or the type chosen in the settings, such as LocalBusiness) and WebSite
 * with a SearchAction when the settings give the search address.
 */
final class OrganizationSchema implements SchemaProvider
{
    public function nodes(SeoPage $page): array
    {
        if ($page->kind !== SeoPage::HOME) {
            return [];
        }

        $site = $page->site();
        $type = Setting::get('seo.organization_type', null, $site);

        $organization = Nodes::filled([
            ...Nodes::publisher($page),
            '@type' => is_string($type) && $type !== '' ? $type : 'Organization',
            'telephone' => self::lines(Setting::get('seo.phones', null, $site))[0] ?? null,
            'email' => self::string(Setting::get('seo.email', null, $site)),
            'address' => self::string(Setting::get('seo.address', null, $site)),
            'sameAs' => self::lines(Setting::get('seo.same_as', null, $site)),
        ]);

        $search = self::string(Setting::get('seo.search_url', null, $site));

        $website = Nodes::filled([
            '@type' => 'WebSite',
            '@id' => $page->websiteId(),
            'url' => $page->homeUrl(),
            'name' => $site->name ?: config('app.name'),
            'inLanguage' => str_replace('_', '-', $page->language()),
            'publisher' => ['@id' => $page->organizationId()],
            'potentialAction' => $search !== null && str_contains($search, '{search_term_string}') ? [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $search],
                'query-input' => 'required name=search_term_string',
            ] : null,
        ]);

        return [$organization, $website];
    }

    private static function string(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int,string>
     */
    private static function lines(mixed $value): array
    {
        $lines = is_array($value) ? $value : preg_split('/\r\n|\r|\n|,/', (string) $value);

        return array_values(array_filter(array_map(static fn (mixed $line): string => trim((string) $line), (array) $lines)));
    }
}
