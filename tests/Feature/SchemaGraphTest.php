<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Hook;
use Atrium\Seo\Schema\SchemaGraph;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Tests\Fixtures\SloppySchema;
use Atrium\Seo\Tests\TestCase;

/**
 * What goes into the one graph of a page and what is left out of it.
 */
final class SchemaGraphTest extends TestCase
{
    private function page(): SeoPage
    {
        $page = new SeoPage;
        $page->url = 'https://example.com/page';

        return $page;
    }

    public function test_only_nodes_with_a_type_get_into_the_graph_and_nodes_of_one_id_become_one(): void
    {
        config(['atrium-seo.schema' => [SloppySchema::class]]);

        $this->assertSame([
            ['@type' => 'Thing', 'name' => 'Anonymous'],
            ['@type' => 'WebPage', '@id' => 'https://example.com/#same', 'name' => 'Second', 'url' => 'https://example.com/first', 'description' => 'Later'],
            ['@type' => 'Thing', 'name' => 'Enriched'],
        ], app(SchemaGraph::class)->nodes($this->page()));
    }

    public function test_a_node_the_page_added_comes_after_the_providers_and_before_the_enrichers(): void
    {
        config(['atrium-seo.schema' => [SloppySchema::class]]);
        $page = $this->page()->addSchema(['@type' => 'Thing', 'name' => 'Own']);

        $names = array_column(app(SchemaGraph::class)->nodes($page), 'name');

        $this->assertSame(['Anonymous', 'Second', 'Own', 'Enriched'], $names);
    }

    public function test_a_provider_listed_twice_speaks_once(): void
    {
        config(['atrium-seo.schema' => [SloppySchema::class, SloppySchema::class]]);
        Hook::filter('atrium.seo.schema', static fn (array $classes): array => [...$classes, SloppySchema::class]);

        $names = array_column(app(SchemaGraph::class)->nodes($this->page()), 'name');

        $this->assertSame(['Anonymous', 'Second', 'Enriched'], $names);
    }

    public function test_entries_that_are_not_class_names_are_skipped(): void
    {
        config(['atrium-seo.schema' => [SloppySchema::class]]);
        Hook::filter('atrium.seo.schema', static fn (array $classes): array => [...$classes, 5, null, ['App\\Nested'], 'App\\Missing']);

        $names = array_column(app(SchemaGraph::class)->nodes($this->page()), 'name');

        $this->assertSame(['Anonymous', 'Second', 'Enriched'], $names);
    }

    public function test_a_page_without_providers_or_nodes_has_no_script(): void
    {
        config(['atrium-seo.schema' => []]);

        $this->assertSame([], app(SchemaGraph::class)->nodes($this->page()));
        $this->assertNull(app(SchemaGraph::class)->json($this->page()));
    }

    public function test_the_script_keeps_text_readable_and_cannot_be_closed_by_it(): void
    {
        config(['atrium-seo.schema' => []]);
        $page = $this->page()->addSchema(['@type' => 'Thing', 'name' => 'Café <b> & "Co"', 'url' => 'https://example.com/a']);

        $this->assertSame(
            strtr('{"@context":"https://schema.org","@graph":[{"@type":"Thing","name":"Café #3Cb#3E #26 \\"Co\\"","url":"https://example.com/a"}]}', ['#' => '\\u00']),
            app(SchemaGraph::class)->json($page),
        );
    }

    public function test_text_that_is_not_utf_8_fails_loudly_instead_of_printing_nothing(): void
    {
        config(['atrium-seo.schema' => []]);
        $page = $this->page()->addSchema(['@type' => 'Thing', 'name' => "bad \xB1 text"]);

        $this->expectException(\JsonException::class);

        app(SchemaGraph::class)->json($page);
    }
}
