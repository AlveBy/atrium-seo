<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\IndexNow;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tells Bing and Yandex about a changed page right away (IndexNow), instead
 * of waiting for their next visit. The key is derived from the app key, one
 * per site, and served at /{key}.txt, so nothing has to be set up.
 *
 * Only in production, when config('atrium-seo.indexnow.enabled') is on and
 * the site has not switched it off in the module's settings. The same
 * address is not sent twice within config('atrium-seo.indexnow.throttle_minutes').
 */
final class IndexNow
{
    /** @var array<int,array<int,string>> addresses waiting for the end of the request, by site */
    private array $queue = [];

    private bool $scheduled = false;

    public function __construct(private readonly Sites $sites) {}

    public function key(?Site $site = null): string
    {
        $site ??= $this->sites->current();

        return substr(hash_hmac('sha256', 'indexnow:'.$site->id, (string) config('app.key')), 0, 32);
    }

    public function enabled(?Site $site = null): bool
    {
        $site ??= $this->sites->current();

        return app()->isProduction()
            && config('atrium-seo.indexnow.enabled', true)
            && Setting::get('seo.indexnow', site: $site); // the settings screen defaults it to on
    }

    /**
     * Queues addresses, sent once the response is out.
     *
     * @param  iterable<string>  $urls
     */
    public function queue(iterable $urls, ?Site $site = null): void
    {
        $site ??= $this->sites->current();

        if (! $this->enabled($site)) {
            return;
        }

        foreach ($urls as $url) {
            $this->queue[$site->id][] = $url;
        }

        if (! $this->scheduled) {
            $this->scheduled = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /**
     * Sends what is queued. Returns how many addresses went out.
     */
    public function flush(): int
    {
        $sent = 0;
        $queue = $this->queue;
        $this->queue = [];
        $this->scheduled = false;

        foreach ($queue as $siteId => $urls) {
            $site = $this->sites->find($siteId);

            if ($site !== null) {
                $sent += $this->send($site, $urls);
            }
        }

        return $sent;
    }

    /**
     * @param  array<int,string>  $urls
     */
    public function send(Site $site, array $urls): int
    {
        $minutes = (int) config('atrium-seo.indexnow.throttle_minutes', 10);
        $fresh = [];

        foreach (array_unique($urls) as $url) {
            if ($minutes <= 0 || Cache::add('atrium.seo.indexnow.'.sha1($url), $url, now()->addMinutes($minutes))) {
                $fresh[] = $url;
            }
        }

        if ($fresh === []) {
            return 0;
        }

        $host = (string) parse_url($fresh[0], PHP_URL_HOST);
        $key = $this->key($site);
        $payload = [
            'host' => $host,
            'key' => $key,
            'keyLocation' => $site->url().'/'.$key.'.txt',
            'urlList' => $fresh,
        ];

        foreach ((array) config('atrium-seo.indexnow.endpoints', []) as $endpoint) {
            try {
                Http::timeout(5)->acceptJson()->post($endpoint, $payload);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return count($fresh);
    }
}
