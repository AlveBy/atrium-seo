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
 * NewsArticle on a news item's page.
 */
final class NewsArticleSchema implements SchemaProvider
{
    public const MODEL = 'Atrium\\News\\Models\\News';

    public function nodes(SeoPage $page): array
    {
        if (! is_a($page->record, self::MODEL)) {
            return [];
        }

        $news = $page->record;
        $language = $page->language();
        $published = Nodes::date($news->getAttribute('active_from')) ?? Nodes::date($news->getAttribute('created_at'));

        return [Nodes::filled([
            '@type' => 'NewsArticle',
            '@id' => $page->mainEntityId(),
            'headline' => mb_substr(Nodes::name($news, $language), 0, 110),
            'description' => Nodes::description($news, $language),
            'image' => Nodes::images($news),
            'datePublished' => $published,
            'dateModified' => Nodes::date($news->getAttribute('updated_at')) ?? $published,
            'inLanguage' => str_replace('_', '-', $language),
            'mainEntityOfPage' => strtok($page->canonical(), '?'),
            'author' => ['@type' => 'Organization', 'name' => Nodes::organizationName($page), 'url' => $page->homeUrl()],
            'publisher' => Nodes::publisher($page),
        ])];
    }
}
