<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Redirects;

use Atrium\Admin\Support\Sites;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Support\Paths;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The redirects of a site, kept in the cache, and the questions asked of
 * them: where does an address lead (following the whole chain), does a new
 * redirect close a loop.
 */
final class RedirectMap
{
    public const MAX_HOPS = 10;

    private const VERSION_KEY = 'atrium.seo.redirects.version';

    /** @var array<int,array{exact:array<string,array{0:int,1:string|null,2:int}>,prefix:array<string,array{0:int,1:string|null,2:int}>}> */
    private array $maps = [];

    public function __construct(private readonly Sites $sites) {}

    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, (string) Str::ulid());
        app(self::class)->maps = [];
    }

    /**
     * The redirect an address hits directly, without following the chain.
     *
     * @return array{id:int,status:int,target:string|null}|null
     */
    public function match(string $path, ?int $siteId = null): ?array
    {
        $map = $this->map($siteId);
        $path = Paths::normalize($path);
        $bare = strtok($path, '?') ?: '/';

        // A source with a query matches only that query; "/old" also
        // matches "/old?utm=1".
        $exact = $map['exact'][strtolower($path)] ?? $map['exact'][strtolower($bare)] ?? null;

        if ($exact !== null) {
            [$id, $target, $status] = $exact;

            return ['id' => $id, 'status' => $status, 'target' => $target];
        }

        foreach ($map['prefix'] as $prefix => [$id, $target, $status]) {
            if (self::under($prefix, $bare)) {
                // "/old/*" → "/new/*" keeps the rest of the path.
                if ($target !== null && str_ends_with($target, '*')) {
                    $target = rtrim(substr($target, 0, -1), '/').substr($bare, strlen($prefix));
                }

                return ['id' => $id, 'status' => $status, 'target' => $target];
            }
        }

        return null;
    }

    /**
     * Follows an address through every redirect it meets.
     *
     * @return array{hops:array<int,string>,loop:bool,status:int|null,id:int|null} hops start with the address itself
     */
    public function chainFrom(string $path, ?int $siteId = null): array
    {
        $hops = [Paths::normalize($path)];
        $first = null;
        $status = null;

        // MAX_HOPS redirects are followed; the one after them makes it a loop.
        for ($i = 0; $i <= self::MAX_HOPS; $i++) {
            $hit = $this->match(end($hops), $siteId);

            if ($hit === null) {
                return ['hops' => $hops, 'loop' => false, 'status' => $status, 'id' => $first];
            }

            $first ??= $hit['id'];
            $status ??= $hit['status'];

            if ($hit['target'] === null) {
                return ['hops' => $hops, 'loop' => false, 'status' => $hit['status'], 'id' => $first];
            }

            $next = $this->local($hit['target'], $siteId);

            if ($next === null) {
                $hops[] = $hit['target'];

                return ['hops' => $hops, 'loop' => false, 'status' => $status, 'id' => $first];
            }

            if (in_array($next, $hops, true)) {
                $hops[] = $next;

                return ['hops' => $hops, 'loop' => true, 'status' => $status, 'id' => $first];
            }

            $hops[] = $next;
        }

        return ['hops' => $hops, 'loop' => true, 'status' => $status, 'id' => $first];
    }

    /**
     * The circle a redirect from $source to $target would close, or null.
     *
     * @return array<int,string>|null
     */
    public function loopWith(string $source, string $target, ?int $ignoreId = null, ?int $siteId = null): ?array
    {
        $hops = [$source];
        $next = $this->local($target, $siteId);

        for ($i = 0; $next !== null && $i < self::MAX_HOPS; $i++) {
            $hops[] = $next;

            if ($this->covers($source, $next)) {
                return $hops;
            }

            $hit = $this->match($next, $siteId);

            if ($hit === null || $hit['id'] === $ignoreId || $hit['target'] === null) {
                return null;
            }

            $next = $this->local($hit['target'], $siteId);
        }

        return $next === null ? null : $hops;
    }

    /**
     * Every chain (more than one hop) and loop of a site.
     *
     * @return array<int,array{id:int,source:string,hops:array<int,string>,loop:bool}>
     */
    public function problems(?int $siteId = null): array
    {
        $problems = [];

        foreach ($this->map($siteId)['exact'] as $source => [$id]) {
            $chain = $this->chainFrom($source, $siteId);

            if ($chain['loop'] || count($chain['hops']) > 2) {
                $problems[] = ['id' => $id, 'source' => $source, 'hops' => $chain['hops'], 'loop' => $chain['loop']];
            }
        }

        return $problems;
    }

    /**
     * Whether a source (maybe "/old/*") covers an address.
     */
    private function covers(string $source, string $path): bool
    {
        $bare = strtok($path, '?') ?: '/';

        if (str_ends_with($source, '/*')) {
            return self::under(strtolower(substr($source, 0, -2)), $bare);
        }

        return strtolower($source) === strtolower($path) || strtolower($source) === strtolower($bare);
    }

    /**
     * Whether a path is the folder itself or something inside it; the folder
     * comes lowercased, "" is the whole site.
     */
    private static function under(string $folder, string $path): bool
    {
        $path = strtolower($path);

        return $path === $folder || str_starts_with($path, $folder.'/');
    }

    /**
     * The path of a target on this site; null for another host.
     */
    private function local(string $target, ?int $siteId): ?string
    {
        if (! preg_match('#^https?://#i', $target)) {
            return Paths::normalize($target);
        }

        $site = ($siteId === null ? null : $this->sites->find($siteId)) ?? $this->sites->current();

        return Paths::relative($target, $site->url());
    }

    /**
     * @return array{exact:array<string,array{0:int,1:string|null,2:int}>,prefix:array<string,array{0:int,1:string|null,2:int}>}
     */
    private function map(?int $siteId): array
    {
        $siteId ??= $this->sites->current()->getKey();

        if ($siteId === null) {
            // No site is saved yet (a fresh install, or a demo in the middle of its reset): nothing redirects.
            return ['exact' => [], 'prefix' => []];
        }

        return $this->maps[$siteId] ??= Cache::rememberForever(
            'atrium.seo.redirects.'.$siteId.'.'.Cache::get(self::VERSION_KEY, '0'),
            static fn (): array => self::build($siteId),
        );
    }

    /**
     * @return array{exact:array<string,array{0:int,1:string|null,2:int}>,prefix:array<string,array{0:int,1:string|null,2:int}>}
     */
    private static function build(int $siteId): array
    {
        $map = ['exact' => [], 'prefix' => []];

        try {
            $rows = Redirect::query()->withoutGlobalScopes()->where('site_id', $siteId)->where('active', true)
                ->orderBy('id')->get(['id', 'source', 'target', 'status']);
        } catch (QueryException) {
            // The tables are not migrated yet: nothing redirects.
            return $map;
        }

        foreach ($rows as $row) {
            $entry = [$row->id, $row->target, $row->status];

            if (str_ends_with($row->source, '/*')) {
                $map['prefix'][strtolower(substr($row->source, 0, -2))] ??= $entry;
            } else {
                $map['exact'][strtolower($row->source)] ??= $entry;
            }
        }

        // The longest prefix first: "/old/a/*" before "/old/*".
        uksort($map['prefix'], static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $map;
    }
}
