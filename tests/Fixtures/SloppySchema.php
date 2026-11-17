<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Seo\Contracts\SchemaEnricher;
use Atrium\Seo\Contracts\SchemaProvider;
use Atrium\Seo\SeoPage;

/**
 * A careless module: its provider and its enricher hand back things that
 * are not nodes next to the ones that are, a node without an "@id" and
 * two that share one.
 */
final class SloppySchema implements SchemaEnricher, SchemaProvider
{
    public function nodes(SeoPage $page): array
    {
        return [
            ['name' => 'a node without a type'],
            'text',
            5,
            ['@type' => 'Thing', 'name' => 'Anonymous'],
            ['@type' => 'Thing', '@id' => 'https://example.com/#same', 'name' => 'First', 'url' => 'https://example.com/first'],
            ['@type' => 'WebPage', '@id' => 'https://example.com/#same', 'name' => 'Second', 'description' => 'Later'],
        ];
    }

    public function enrich(array $graph, SeoPage $page): array
    {
        return [...$graph, 'junk', ['no' => 'type'], ['@type' => 'Thing', 'name' => 'Enriched']];
    }
}
