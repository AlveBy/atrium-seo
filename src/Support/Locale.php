<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Support;

/**
 * Content languages are written "pt-br", interface translations "pt_BR".
 */
final class Locale
{
    public static function forTranslator(string $language): string
    {
        $parts = explode('-', str_replace('_', '-', $language), 2);

        return isset($parts[1]) ? strtolower($parts[0]).'_'.strtoupper($parts[1]) : strtolower($parts[0]);
    }

    /**
     * A translation of this package in a content language, English when the
     * language has none.
     *
     * @param  array<string,mixed>  $replace
     */
    public static function trans(string $key, string $language, array $replace = []): string
    {
        $value = trans($key, $replace, self::forTranslator($language));

        return is_string($value) ? $value : '';
    }
}
