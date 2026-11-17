<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Reading records the way a page in one language shows them.
 */
final class Text
{
    /**
     * An attribute in a language, without falling back to another one: a
     * German page must not get a Russian title because the German one is
     * missing.
     */
    public static function of(Model $record, string $attribute, string $language): string
    {
        if (self::isTranslatable($record, $attribute)) {
            /** @phpstan-ignore method.notFound */
            $value = $record->getTranslation($attribute, $language, false);
        } else {
            $value = $record->getAttribute($attribute);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public static function isTranslatable(Model $record, string $attribute): bool
    {
        return method_exists($record, 'isTranslatableAttribute') && $record->isTranslatableAttribute($attribute);
    }

    /**
     * Plain text of an HTML fragment, cut at a sentence when one ends in the
     * second half of the limit, else at a word.
     */
    public static function excerpt(string $html, int $limit = 300): string
    {
        // Blocks and line breaks part words; inline tags (<b>fast</b>.) do not.
        $spaced = Str::of($html)
            ->replaceMatches('#<(/?(?:p|div|br|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|section|article|hr)\b)#i', ' <$1')
            ->stripTags()
            ->toString();
        $text = trim(Str::of(html_entity_decode($spaced, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->replaceMatches('/[\s\x{00A0}]+/u', ' ')
            ->toString());

        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        if (preg_match('/(.*[.!?])\s/', mb_substr($text, 0, $limit), $m) && mb_strlen($m[1]) >= intdiv($limit, 2)) {
            return $m[1];
        }

        return Str::limit($text, $limit - 1, '…', preserveWords: true);
    }

    /**
     * Collapse spaces and drop separators left dangling at the ends when a
     * variable came out empty ("Shoes — " → "Shoes").
     */
    public static function tidy(string $text): string
    {
        return Str::of($text)
            ->replaceMatches('/[\s\x{00A0}]+/u', ' ')
            ->replaceMatches('/\s+([,.:;!?])/u', '$1')
            ->replaceMatches('/([|—–·-])(\s*[|—–·-])+/u', '$1')
            ->trim(" \t|—–·-,:;")
            ->toString();
    }
}
