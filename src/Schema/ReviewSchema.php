<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use Atrium\Admin\Modules\Modules;
use Atrium\Seo\Contracts\SchemaEnricher;
use Atrium\Seo\SeoPage;

/**
 * The rating and the newest reviews of the record the page shows, added to
 * the node about it (the Product, the LocalBusiness); a node of the type
 * the reviews module gives the record when no other provider described it.
 * Takes the place of the reviews block's own script.
 */
final class ReviewSchema implements SchemaEnricher
{
    public const SERVICE = 'Atrium\\Reviews\\Reviews';

    public const PACKAGE = 'alveby/atrium-reviews';

    public function enrich(array $graph, SeoPage $page): array
    {
        if ($page->record === null || ! app(Modules::class)->isInstalled(self::PACKAGE)) {
            return $graph;
        }

        $reviews = app(self::SERVICE);

        if (! $reviews->accepts($page->record)) {
            return $graph;
        }

        $data = $reviews->jsonLd($page->record, Nodes::name($page->record, $page->language()), strtok($page->canonical(), '?'), $reviews->latest($page->record, 5));

        if ($data === null) {
            return $graph;
        }

        $extra = array_intersect_key($data, array_flip(['aggregateRating', 'review']));

        foreach ($graph as $index => $node) {
            if (($node['@id'] ?? null) === $page->mainEntityId()) {
                $graph[$index] = [...$node, ...$extra];

                return $graph;
            }
        }

        unset($data['@context']);
        $graph[] = ['@id' => $page->mainEntityId(), ...$data];

        return $graph;
    }
}
