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
use RuntimeException;

/**
 * A provider with a bug: it must take only its own markup down.
 */
final class BrokenSchema implements SchemaEnricher, SchemaProvider
{
    public function nodes(SeoPage $page): array
    {
        throw new RuntimeException('Provider bug');
    }

    public function enrich(array $graph, SeoPage $page): array
    {
        throw new RuntimeException('Enricher bug');
    }
}
