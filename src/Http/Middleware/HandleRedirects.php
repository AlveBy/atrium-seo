<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Http\Middleware;

use Atrium\Admin\Http\Middleware\EnterSiteFolder;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Redirects\NotFoundLog;
use Atrium\Seo\Redirects\RedirectMap;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends old addresses where the redirect manager says, in one hop however
 * long the chain, and writes down the addresses that end in a 404.
 * The panel, the API and anything but GET and HEAD pass untouched.
 */
final class HandleRedirects
{
    public function __construct(
        private readonly RedirectMap $map,
        private readonly NotFoundLog $log,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        if ($this->isPanel($request->getPathInfo())) {
            return $next($request);
        }

        $siteId = $request->attributes->get(EnterSiteFolder::SITE);
        $siteId = is_numeric($siteId) ? (int) $siteId : null;
        $query = (string) $request->getQueryString();
        $path = $request->getPathInfo().($query === '' ? '' : '?'.$query);

        $redirect = $this->redirect($path, $query, $siteId);

        if ($redirect !== null) {
            return $redirect;
        }

        $response = $next($request);

        if ($response->getStatusCode() === 404) {
            $this->log->hit($request->getPathInfo(), $siteId, $request->headers->get('referer'));
        }

        return $response;
    }

    private function redirect(string $path, string $query, ?int $siteId): ?Response
    {
        $chain = $this->map->chainFrom($path, $siteId);

        if ($chain['id'] === null || $chain['loop']) {
            return null;
        }

        $this->count($chain['id']);

        if (count($chain['hops']) === 1) {
            // The address itself is gone (410).
            abort(410);
        }

        $url = $chain['hops'][array_key_last($chain['hops'])];

        // The visitor's query (utm tags) goes along unless the redirect
        // was for that query or brings one of its own.
        if ($query !== '' && ! str_contains($url, '?') && ($this->map->match(strtok($path, '?') ?: '/', $siteId)['id'] ?? null) === $chain['id']) {
            $url .= '?'.$query;
        }

        return redirect()->to($url, (int) $chain['status']);
    }

    private function count(int $id): void
    {
        try {
            Redirect::query()->withoutGlobalScopes()->whereKey($id)->incrementEach(['hits' => 1], ['last_hit_at' => now()]);
        } catch (QueryException) {
            // A counter is not worth a failed redirect.
        }
    }

    private function isPanel(string $path): bool
    {
        $path = trim($path, '/').'/';

        foreach ([config('atrium.prefix', 'admin'), config('atrium.api.prefix', 'api/v1'), config('atrium.mcp.path', 'mcp')] as $prefix) {
            $prefix = trim((string) $prefix, '/');

            if ($prefix !== '' && str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
