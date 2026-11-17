<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Contracts;

use Atrium\Seo\SeoPage;

/**
 * A provider that changes what the others said instead of adding nodes of
 * its own, such as the reviews' rating added to the product. Runs after
 * every SchemaProvider.
 */
interface SchemaEnricher
{
    /**
     * @param  array<int,array<string,mixed>>  $graph
     * @return array<int,array<string,mixed>>
     */
    public function enrich(array $graph, SeoPage $page): array;
}
