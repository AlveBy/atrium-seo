<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Contracts;

use Atrium\Seo\SeoPage;

/**
 * schema.org markup a module adds to the pages it knows. Every provider is
 * asked on every page and answers with nothing when the page is not its:
 *
 *     final class EventSchema implements SchemaProvider
 *     {
 *         public function nodes(SeoPage $page): array
 *         {
 *             if (! $page->record instanceof Event) {
 *                 return [];
 *             }
 *
 *             return [[
 *                 '@type' => 'Event',
 *                 '@id' => $page->mainEntityId(),
 *                 'name' => $page->record->translated('name'),
 *                 'startDate' => $page->record->starts_at->toAtomString(),
 *                 ...
 *             ]];
 *         }
 *     }
 *
 * and registers it from its service provider (nothing is loaded while the
 * SEO module is not installed):
 *
 *     \Atrium\Admin\Support\ConfigList::add('atrium-seo.schema', EventSchema::class);
 *
 * The nodes of all providers go into one <script type="application/ld+json">
 * with an "@graph"; "@context" is added there. Give the node about the page
 * itself "@id" => $page->mainEntityId(): reviews attach their rating to it.
 */
interface SchemaProvider
{
    /**
     * @return array<int,array<string,mixed>> schema.org nodes, each with "@type"
     */
    public function nodes(SeoPage $page): array;
}
