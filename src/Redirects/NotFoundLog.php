<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Redirects;

use Atrium\Admin\Support\Sites;
use Atrium\Seo\Models\NotFound;
use Atrium\Seo\Support\Paths;
use Illuminate\Database\QueryException;

/**
 * The 404 log: one row per address, with how many times it was asked for,
 * the last page that linked to it and when. The oldest rows go once there
 * are more than config('atrium-seo.not_found.keep').
 */
final class NotFoundLog
{
    public function __construct(private readonly Sites $sites) {}

    public function hit(string $path, ?int $siteId, ?string $referer = null): void
    {
        if (! config('atrium-seo.not_found.enabled', true)) {
            return;
        }

        $path = Paths::normalize($path);

        foreach ((array) config('atrium-seo.not_found.ignore', []) as $pattern) {
            if (Paths::matches((string) $pattern, $path)) {
                return;
            }
        }

        $siteId ??= $this->sites->current()->getKey();
        $referer = $referer === null ? null : mb_substr($referer, 0, 2048);

        try {
            $row = NotFound::query()->withoutGlobalScopes()->where('site_id', $siteId)->where('path_hash', Paths::hash($path))->first();

            if ($row === null) {
                NotFound::query()->withoutGlobalScopes()->create([
                    'site_id' => $siteId, 'path' => mb_substr($path, 0, 2048), 'path_hash' => Paths::hash($path),
                    'hits' => 1, 'referer' => $referer, 'first_seen_at' => now(), 'last_seen_at' => now(),
                ]);

                $this->prune($siteId);
            } else {
                $row->forceFill(['hits' => $row->hits + 1, 'last_seen_at' => now(), 'referer' => $referer ?? $row->referer])->save();
            }
        } catch (QueryException) {
            // The log must never break the page that is already a 404.
        }
    }

    private function prune(int $siteId): void
    {
        $keep = max(1, (int) config('atrium-seo.not_found.keep', 5000));
        $cut = NotFound::query()->withoutGlobalScopes()->where('site_id', $siteId)
            ->orderByDesc('last_seen_at')->orderByDesc('id')->skip($keep)->limit(PHP_INT_MAX)->pluck('id');
        NotFound::query()->withoutGlobalScopes()->whereIn('id', $cut)->delete();
    }
}
