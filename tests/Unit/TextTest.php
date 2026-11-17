<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Unit;

use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Support\Text;
use Atrium\Seo\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The text a template or a check takes from a record.
 */
final class TextTest extends TestCase
{
    public function test_an_attribute_in_one_language_without_falling_back(): void
    {
        $news = new News(['name' => ['en' => ' We moved ', 'de' => 'Umgezogen']]);

        $this->assertSame('We moved', Text::of($news, 'name', 'en'));
        $this->assertSame('', Text::of($news, 'name', 'fr'));
        $this->assertSame('2026-03-01', Text::of(new Product(['released_on' => '2026-03-01']), 'released_on', 'en'));
        $this->assertSame('', Text::of(new Product(['colors' => ['Red']]), 'colors', 'en'));
    }

    /**
     * @return iterable<string,array{string,int,string}>
     */
    public static function excerpts(): iterable
    {
        yield 'short, without tags' => ['<p>Light&nbsp;and <b>fast</b>.</p>', 50, 'Light and fast.'];
        yield 'blocks part words, inline tags do not' => ['<h2>Title</h2><p>One<br>two <i>th</i>ree</p><ul><li>a</li><li>b</li></ul>', 100, 'Title One two three a b'];
        yield 'cut after a sentence in the second half' => ['First sentence here. Second one goes on and on.', 30, 'First sentence here.'];
        yield 'a sentence too short is not enough: cut at a word' => ['Hi. This line is long enough to be cut somewhere.', 30, 'Hi. This line is long…'];
        yield 'empty' => ['<br>', 10, ''];
        yield 'two short sentences are no reason to cut' => ['One. Two.', 50, 'One. Two.'];
        yield 'exactly the limit stays whole' => ['abcdefghij', 10, 'abcdefghij'];
        yield 'one over the limit is cut at the limit' => ['abcdefghijk', 10, 'abcdefghi…'];
        yield 'letters of another alphabet are counted as letters' => ['Привет', 8, 'Привет'];
        yield 'quotes and apostrophes are text' => ['Tom &amp; &quot;Jerry&quot; &#039;s &apos;x&apos;', 100, 'Tom & "Jerry" \'s \'x\''];
        yield 'a sentence of exactly half the limit is enough' => ['Short here okk. And then this goes on and on forever.', 30, 'Short here okk.'];
        yield 'a sentence one letter short of half the limit is not' => ['Short here ok. And then this goes on and on forever.', 30, 'Short here ok. And then…'];
        yield 'a sentence is half the limit in letters, not bytes' => ['Привет, мир. Это длинный текст который продолжается дальше.', 30, 'Привет, мир. Это длинный…'];
        yield 'a sentence is looked for in the first letters, not bytes' => ['Привет, мир, как дела? Хорошо и так далее и далее.', 30, 'Привет, мир, как дела?'];
        yield 'text that is not text' => ["caf\xe9 au lait", 100, ''];
    }

    #[DataProvider('excerpts')]
    public function test_an_excerpt(string $html, int $limit, string $expected): void
    {
        $this->assertSame($expected, Text::excerpt($html, $limit));
        $this->assertLessThanOrEqual($limit, mb_strlen(Text::excerpt($html, $limit)));
    }

    public function test_an_excerpt_is_three_hundred_characters_unless_told_otherwise(): void
    {
        $exact = str_repeat('a', 300);

        $this->assertSame($exact, Text::excerpt($exact));
        // The limit counts characters, not bytes.
        $this->assertSame(str_repeat('я', 200), Text::excerpt(str_repeat('я', 200), 200));
        $this->assertSame(str_repeat('word ', 59).'word…', Text::excerpt(str_repeat('word ', 61)));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function dangling(): iterable
    {
        yield 'nothing' => ['', ''];
        yield 'a clean title' => ['Shoes | Lumen', 'Shoes | Lumen'];
        yield 'a separator left at the end' => ['Shoes — ', 'Shoes'];
        yield 'a separator left at the start' => ['| Shoes', 'Shoes'];
        yield 'two separators in a row' => ['Shoes - - Lumen', 'Shoes - Lumen'];
        yield 'two different separators in a row' => ['Shoes | — Lumen', 'Shoes | Lumen'];
        yield 'three separators in a row' => ['Shoes — | – Lumen', 'Shoes — Lumen'];
        yield 'a hyphen inside a word' => ['T-shirt | Lumen', 'T-shirt | Lumen'];
        yield 'a space before a punctuation mark' => ['Shoes , socks ; hats', 'Shoes, socks; hats'];
        yield 'a space before an exclamation mark' => ['Hello !', 'Hello!'];
        yield 'spaces of every kind' => ["Shoes\t\n  and\u{00A0}\u{00A0}socks", 'Shoes and socks'];
        yield 'every separator at the start and the end' => ['|—–·-,:;Shoes;:,-·–—|', 'Shoes'];
        yield 'a tab around' => ["\tShoes\t", 'Shoes'];
        yield 'brackets stay' => ['(Shoes)', '(Shoes)'];
        yield 'a question mark stays' => ['Shoes?', 'Shoes?'];
        yield 'text that is not text' => ["caf\xe9 | Lumen", ''];
    }

    #[DataProvider('dangling')]
    public function test_a_text_is_tidied(string $text, string $expected): void
    {
        $this->assertSame($expected, Text::tidy($text));
    }
}
