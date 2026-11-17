<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use Atrium\Seo\Contracts\SchemaProvider;
use Atrium\Seo\SeoPage;

/**
 * LocalBusiness on a store's page: the address, phones, e-mail and the
 * point on the map.
 */
final class LocalBusinessSchema implements SchemaProvider
{
    public const MODEL = 'Atrium\\Stores\\Models\\Store';

    public function nodes(SeoPage $page): array
    {
        if (! is_a($page->record, self::MODEL)) {
            return [];
        }

        $store = $page->record;
        $language = $page->language();
        $location = $store->getAttribute('location');
        $lat = $location['lat'] ?? $location['latitude'] ?? null;
        $lng = $location['lng'] ?? $location['lon'] ?? $location['longitude'] ?? null;
        $phones = array_values(array_filter((array) $store->getAttribute('phones'), 'is_string'));
        $address = Nodes::text($store, 'address', $language);

        return [Nodes::filled([
            '@type' => 'LocalBusiness',
            '@id' => $page->mainEntityId(),
            'name' => Nodes::name($store, $language),
            'description' => Nodes::description($store, $language),
            'image' => Nodes::images($store),
            'url' => strtok($page->canonical(), '?'),
            'address' => $address === '' ? null : ['@type' => 'PostalAddress', 'streetAddress' => $address],
            'telephone' => $phones[0] ?? null,
            'email' => $store->getAttribute('email') ?: null,
            'geo' => is_numeric($lat) && is_numeric($lng) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng] : null,
            'parentOrganization' => ['@id' => $page->organizationId()],
        ])];
    }
}
