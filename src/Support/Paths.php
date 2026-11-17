<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Support;

use Illuminate\Support\Str;

/**
 * Addresses of a site in one spelling, so that "/News/", "news" and
 * "/news?" find the same redirect, page meta or 404 row.
 */
final class Paths
{
    /**
     * "/path?query", with a leading slash, no trailing one (except the
     * root), no fragment, percent-escapes decoded. The query is kept as
     * given, sorted: "?b=2&a=1" and "?a=1&b=2" are one address.
     */
    public static function normalize(string $path): string
    {
        $path = trim($path);
        $path = preg_replace('#^[a-z][a-z0-9+.-]*://[^/]*#i', '', $path) ?? $path;
        $parts = explode('?', Str::before($path, '#'), 2);

        $path = '/'.trim(rawurldecode($parts[0]), '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $query = $parts[1] ?? '';

        if ($query !== '') {
            parse_str($query, $params);
            ksort($params);
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return $query === '' ? $path : $path.'?'.$query;
    }

    public static function hash(string $path): string
    {
        return sha1($path);
    }

    /**
     * Whether a path matches a pattern where * is anything, line breaks
     * included. Not fnmatch(): it throws on a path over 1024 bytes.
     */
    public static function matches(string $pattern, string $path): bool
    {
        return preg_match('#\A'.str_replace('\\*', '.*', preg_quote($pattern, '#')).'\z#is', $path) === 1;
    }

    /**
     * A site-relative path of an absolute address of that site, or null for
     * an address elsewhere.
     */
    public static function relative(string $url, string $siteRoot): ?string
    {
        $root = rtrim($siteRoot, '/');

        if ($url === $root || str_starts_with($url, $root.'/') || str_starts_with($url, $root.'?')) {
            return self::normalize(substr($url, strlen($root)) ?: '/');
        }

        return null;
    }
}
