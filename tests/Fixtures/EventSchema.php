<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Seo\Contracts\SchemaProvider;
use Atrium\Seo\SeoPage;

/**
 * A module's provider, as the docs show it: an Event on the page of a
 * record that is one.
 */
final class EventSchema implements SchemaProvider
{
    public function nodes(SeoPage $page): array
    {
        if ($page->kind !== SeoPage::RECORD) {
            return [];
        }

        return [[
            '@type' => 'Event',
            '@id' => $page->mainEntityId(),
            'name' => 'Launch party',
            'startDate' => '2026-12-01T19:00:00+01:00',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => ['@type' => 'Place', 'name' => 'Main hall', 'address' => '1 Main Street'],
        ]];
    }
}
