<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use Atrium\Admin\Modules\Modules;
use Atrium\Seo\Contracts\SchemaProvider;
use Atrium\Seo\SeoPage;

/**
 * FAQPage on the FAQ list and on a category's page, with the questions
 * the page shows. Takes the place of the FAQ module's own script.
 */
final class FaqSchema implements SchemaProvider
{
    public const CATEGORY = 'Atrium\\Faq\\Models\\Category';

    public const SERVICE = 'Atrium\\Faq\\Faq';

    public const PACKAGE = 'alveby/atrium-faq';

    public function nodes(SeoPage $page): array
    {
        if (! app(Modules::class)->isInstalled(self::PACKAGE)) {
            return [];
        }

        $faq = app(self::SERVICE);

        // The questions the FAQ's own views showed, when they were drawn.
        $questions = $page->context['faq.questions'] ?? (is_a($page->record, self::CATEGORY) ? $faq->questions([$page->record]) : null);

        if (! is_iterable($questions)) {
            return [];
        }

        $schema = $faq->schema($questions);

        if ($schema['mainEntity'] === []) {
            return [];
        }

        unset($schema['@context']);

        return [['@id' => strtok($page->canonical(), '?').'#faq', ...$schema]];
    }
}
