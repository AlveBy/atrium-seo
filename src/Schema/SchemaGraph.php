<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use Atrium\Admin\Facades\Hook;
use Atrium\Seo\Contracts\SchemaEnricher;
use Atrium\Seo\Contracts\SchemaProvider;
use Atrium\Seo\SeoPage;
use Throwable;

/**
 * All schema.org nodes of a page in one graph: the providers from
 * config('atrium-seo.schema') and the "atrium.seo.schema" filter, the
 * nodes the page added itself, then the enrichers.
 */
final class SchemaGraph
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function nodes(SeoPage $page): array
    {
        $classes = array_unique(array_filter(
            (array) Hook::apply('atrium.seo.schema', (array) config('atrium-seo.schema', []), $page),
            'is_string',
        ));

        $providers = [];

        foreach ($classes as $class) {
            if (class_exists($class)) {
                $providers[] = app($class);
            }
        }

        $graph = [];

        foreach ($providers as $provider) {
            if ($provider instanceof SchemaProvider) {
                array_push($graph, ...$this->safely(fn (): array => $provider->nodes($page)));
            }
        }

        array_push($graph, ...$page->schema);

        foreach ($providers as $provider) {
            if ($provider instanceof SchemaEnricher) {
                $graph = $this->safely(fn (): array => $provider->enrich($graph, $page), $graph);
            }
        }

        return $this->unique($graph);
    }

    /**
     * The script's JSON, or null when there is nothing to say. "<" is
     * escaped, so no text can close the tag.
     */
    public function json(SeoPage $page): ?string
    {
        $nodes = $this->nodes($page);

        if ($nodes === []) {
            return null;
        }

        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => $nodes],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * A provider that fails takes only its own markup down, not the page.
     *
     * @param  callable(): array<int,mixed>  $nodes  what a provider gave, maybe not nodes
     * @param  array<int,array<string,mixed>>  $otherwise
     * @return array<int,array<string,mixed>>
     */
    private function safely(callable $nodes, array $otherwise = []): array
    {
        try {
            return array_filter($nodes(), static fn (mixed $node): bool => is_array($node) && isset($node['@type']));
        } catch (Throwable $e) {
            report($e);

            return $otherwise;
        }
    }

    /**
     * One node per "@id": a later one adds to the first.
     *
     * @param  array<int,array<string,mixed>>  $graph
     * @return array<int,array<string,mixed>>
     */
    private function unique(array $graph): array
    {
        $nodes = [];

        foreach ($graph as $node) {
            $id = $node['@id'] ?? null;

            if (is_string($id) && isset($nodes[$id])) {
                $nodes[$id] = [...$nodes[$id], ...$node];
            } elseif (is_string($id)) {
                $nodes[$id] = $node;
            } else {
                $nodes[] = $node;
            }
        }

        return array_values($nodes);
    }
}
