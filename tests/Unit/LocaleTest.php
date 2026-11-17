<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Unit;

use Atrium\Seo\Support\Locale;
use Atrium\Seo\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Content languages are written "pt-br", interface translations "pt_BR".
 */
final class LocaleTest extends TestCase
{
    /**
     * @return iterable<string,array{string,string}>
     */
    public static function languages(): iterable
    {
        yield 'a language' => ['de', 'de'];
        yield 'a language in capitals' => ['DE', 'de'];
        yield 'a language with a region after a dash' => ['pt-br', 'pt_BR'];
        yield 'a language with a region after an underscore' => ['pt_BR', 'pt_BR'];
        yield 'a language and a region in the wrong case' => ['PT-Br', 'pt_BR'];
        yield 'a script and a region keep to the second part' => ['zh-hans-cn', 'zh_HANS-CN'];
        yield 'nothing' => ['', ''];
    }

    #[DataProvider('languages')]
    public function test_a_content_language_is_named_for_the_translator(string $language, string $expected): void
    {
        $this->assertSame($expected, Locale::forTranslator($language));
    }

    public function test_a_translation_in_a_content_language(): void
    {
        $this->assertSame('página 2', Locale::trans('atrium-seo::seo.page_number', 'pt-br', ['number' => 2]));
        $this->assertSame('strona 3', Locale::trans('atrium-seo::seo.page_number', 'PL', ['number' => 3]));
        $this->assertSame('第 4 页', Locale::trans('atrium-seo::seo.page_number', 'zh-cn', ['number' => 4]));
    }

    public function test_a_language_without_a_translation_gets_english(): void
    {
        $this->assertSame('page 2', Locale::trans('atrium-seo::seo.page_number', 'xx', ['number' => 2]));
    }

    public function test_a_key_that_is_a_group_of_translations_is_no_text(): void
    {
        $this->assertSame('', Locale::trans('atrium-seo::seo.modules', 'en'));
    }
}
