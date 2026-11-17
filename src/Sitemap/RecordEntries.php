<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Sitemap;

use Atrium\Admin\Models\SeoMeta;
use Atrium\Admin\Support\MediaUrl;
use Atrium\Seo\Contracts\SitemapImages;
use Atrium\Seo\Meta\MetaResolver;
use Atrium\Seo\Robots;
use Atrium\Seo\Schema\Nodes;
use Illuminate\Database\Eloquent\Model;

/**
 * The "atrium.sitemap.entries" filter: a record set to noindex on its SEO
 * tab leaves the sitemap, the others take their pictures along.
 */
final class RecordEntries
{
    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<int,array<string,mixed>>
     */
    public function __invoke(array $entries, Model $record): array
    {
        if (method_exists($record, 'seoMeta')) {
            $meta = $record->getRelationValue('seoMeta');

            if ($meta instanceof SeoMeta && Robots::isNoindex($meta->robots)) {
                return [];
            }
        }

        $images = $record instanceof SitemapImages
            ? array_values(array_filter(array_map(static fn (mixed $image): ?string => MetaResolver::absolute(MediaUrl::to($image)), $record->sitemapImages())))
            : Nodes::images($record);

        return array_map(static fn (array $entry): array => [...$entry, 'images' => $images], $entries);
    }
}
