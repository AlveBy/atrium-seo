<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Catalog\Models\Product;

/**
 * A product with an SEO tab whose name is written per language: the
 * combination of a SEO tab (one text for all languages) and a translated
 * record that no module of the suite has. The German name is "Maus".
 */
final class LocalizedProduct extends Product
{
    public function isTranslatableAttribute(string $key): bool
    {
        return $key === 'name';
    }

    public function getTranslation(string $key, string $locale, bool $useFallbackLocale = true): mixed
    {
        return $locale === 'de' ? 'Maus' : $this->attributes[$key] ?? null;
    }
}
