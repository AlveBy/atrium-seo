<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use Atrium\Seo\Contracts\SchemaProvider;
use Atrium\Seo\SeoPage;

/**
 * BreadcrumbList of every page but the home one: the home page, the
 * sections above, then the page itself.
 */
final class BreadcrumbSchema implements SchemaProvider
{
    public function nodes(SeoPage $page): array
    {
        if ($page->kind === SeoPage::HOME || ($page->breadcrumbs === [] && $page->record === null && $page->kind !== SeoPage::INDEX)) {
            return [];
        }

        $crumbs = [['label' => (string) ($page->site()->name ?: config('app.name')), 'url' => $page->homeUrl()], ...$page->breadcrumbs];
        $current = $page->record !== null ? Nodes::name($page->record, $page->language()) : $page->meta->title;

        if ($current !== '') {
            $crumbs[] = ['label' => $current, 'url' => strtok($page->canonical(), '?')];
        }

        $items = [];

        foreach ($crumbs as $position => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $crumb['label'], 'item' => $crumb['url']];
        }

        return count($items) < 2 ? [] : [['@type' => 'BreadcrumbList', 'itemListElement' => $items]];
    }
}
