<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Unit;

use Atrium\Seo\Support\Paths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One spelling for the addresses of a site.
 */
final class PathsTest extends TestCase
{
    /**
     * @return iterable<string,array{string,string}>
     */
    public static function spellings(): iterable
    {
        yield 'spaces around' => ['  /News/ ', '/News'];
        yield 'no leading slash' => ['news', '/news'];
        yield 'nothing at all' => ['', '/'];
        yield 'only a query mark' => ['?', '/'];
        yield 'the root' => ['/', '/'];
        yield 'a scheme and a host are dropped' => ['http://example.com/news/', '/news'];
        yield 'in any case, with a port' => ['HTTPS://Example.com:8080/News', '/News'];
        yield 'a host alone is the root' => ['https://example.com', '/'];
        yield 'a fragment is dropped' => ['/news#top', '/news'];
        yield 'at the first fragment mark' => ['/news#top#more', '/news'];
        yield 'a query before the fragment' => ['/news?page=2#top', '/news?page=2'];
        yield 'percent escapes are decoded' => ['/caf%C3%A9/%41', '/café/A'];
        yield 'slashes are collapsed' => ['//news///today//', '/news/today'];
        yield 'the query is sorted' => ['/news?b=2&a=1', '/news?a=1&b=2'];
        yield 'a second question mark belongs to the query' => ['/news?b=1?c', '/news?b=1%3Fc'];
        yield 'an empty query is dropped' => ['/news?', '/news'];
    }

    #[DataProvider('spellings')]
    public function test_an_address_has_one_spelling(string $given, string $expected): void
    {
        $this->assertSame($expected, Paths::normalize($given));
    }

    /**
     * @return iterable<string,array{string,string,?string}>
     */
    public static function relatives(): iterable
    {
        yield 'a page' => ['http://localhost/news/x', 'http://localhost', '/news/x'];
        yield 'a page, the root with a slash' => ['http://localhost/news/x', 'http://localhost/', '/news/x'];
        yield 'the root itself' => ['http://localhost', 'http://localhost', '/'];
        yield 'the root with a slash' => ['http://localhost/', 'http://localhost', '/'];
        yield 'a query of the root' => ['http://localhost?page=2', 'http://localhost', '/?page=2'];
        yield 'a query of a page' => ['http://localhost/news?page=2', 'http://localhost', '/news?page=2'];
        yield 'a page of a site in a folder' => ['http://localhost/site2/x', 'http://localhost/site2', '/x'];
        yield 'another folder' => ['http://localhost/site22/x', 'http://localhost/site2', null];
        yield 'another host that starts the same' => ['http://localhost.evil.example/x', 'http://localhost', null];
        yield 'another host that starts the same, without a path' => ['http://localhostx', 'http://localhost', null];
        yield 'another host' => ['https://example.com/x', 'http://localhost', null];
        yield 'a query alone' => ['?x=1', 'http://localhost', null];
        yield 'a path alone' => ['/news', 'http://localhost', null];
    }

    #[DataProvider('relatives')]
    public function test_an_address_of_the_site_is_a_path_and_any_other_is_none(string $url, string $root, ?string $expected): void
    {
        $this->assertSame($expected, Paths::relative($url, $root));
    }

    public function test_the_hash_of_a_path_is_the_sha1_of_it(): void
    {
        $this->assertSame(sha1('/news'), Paths::hash('/news'));
        $this->assertNotSame(Paths::hash('/news'), Paths::hash('/news/'));
    }

    public function test_a_pattern_matches_in_any_case_and_a_star_is_anything(): void
    {
        $this->assertTrue(Paths::matches('/news/*', '/NEWS/today/1'));
        $this->assertTrue(Paths::matches('/news', '/News'));
        $this->assertFalse(Paths::matches('/news/*', '/blog/today'));
        $this->assertFalse(Paths::matches('/news', '/news/today'));
        $this->assertFalse(Paths::matches('/news', 'x/news'));
        $this->assertFalse(Paths::matches('/news.html', '/news-html'));
        $this->assertTrue(Paths::matches('/news/*', "/news/a\nb"));
        $this->assertTrue(Paths::matches('/news/*', '/news/'.str_repeat('a', 2000)));
        $this->assertFalse(Paths::matches('/blog/*', '/news/'.str_repeat('a', 2000)));
    }
}
