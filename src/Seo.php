<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo;

use Atrium\Seo\Schema\SchemaGraph;

/**
 * What the module prints into <head> of the current page: the robots meta,
 * hreflang links across sites and languages, and the JSON-LD graph.
 */
final class Seo
{
    public function __construct(
        private readonly SeoPage $page,
        private readonly Hreflang $hreflang,
        private readonly SchemaGraph $schema,
    ) {}

    /**
     * @return array{robots:string|null,hreflang:array<string,string>,jsonLd:string|null}
     */
    public function head(): array
    {
        $robots = $this->page->meta->robots;
        $noindex = Robots::isNoindex($robots);

        return [
            'robots' => $robots,
            // A page kept out of the index has no language versions to offer.
            'hreflang' => $noindex ? [] : $this->hreflang->for($this->page),
            'jsonLd' => $this->schema->json($this->page),
        ];
    }
}
