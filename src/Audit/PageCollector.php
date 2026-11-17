<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Audit;

use Atrium\Admin\Contracts\Sitemapable;
use Atrium\Admin\Resources\ResourceRegistry;
use Atrium\Admin\Support\ContentLanguages;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Every public page of the current site that is a record, in every
 * language it has an address in: the modules' Sitemapable and the
 * records the theme draws.
 */
final class PageCollector
{
    public const THEME_PAGES = 'Atrium\\Theme\\Pages';

    public function __construct(
        private readonly ResourceRegistry $resources,
        private readonly ContentLanguages $languages,
    ) {}

    /**
     * @return iterable<array{record:Model,language:string,url:string}>
     */
    public function pages(): iterable
    {
        $seen = [];
        $codes = $this->languages->codes();

        foreach ($this->resources->all() as $model) {
            if (! is_subclass_of($model, Sitemapable::class)) {
                continue;
            }

            /** @var Model&Sitemapable $record */
            foreach ($model::sitemapQuery()->lazy(200) as $record) {
                $seen[$record::class.'#'.$record->getKey()] = true;

                foreach ($codes as $code) {
                    $url = $record->sitemapUrl($code);

                    if (is_string($url) && $url !== '') {
                        yield ['record' => $record, 'language' => $code, 'url' => $url];
                    }
                }
            }
        }

        if (! app()->bound(self::THEME_PAGES)) {
            return;
        }

        $pages = app(self::THEME_PAGES);

        foreach ($pages->prefixes() as $prefix) {
            foreach (array_keys($pages->module($prefix)) as $class) {
                try {
                    $records = $pages->visible($class)->lazy(200);
                } catch (Throwable) {
                    continue;
                }

                foreach ($records as $record) {
                    if ($seen[$record::class.'#'.$record->getKey()] ?? false) {
                        continue;
                    }

                    foreach ($codes as $code) {
                        $url = $pages->url($record, $code);

                        if (is_string($url) && $url !== '') {
                            yield ['record' => $record, 'language' => $code, 'url' => $url];
                        }
                    }
                }
            }
        }
    }

    /**
     * Pages that are not records: the home page and the modules' lists.
     *
     * @return array<int,string>
     */
    public function otherUrls(): array
    {
        if (! app()->bound(self::THEME_PAGES)) {
            return [];
        }

        $pages = app(self::THEME_PAGES);
        $urls = [];

        foreach ($this->languages->codes() as $code) {
            $urls[] = $pages->homeUrl($code);

            foreach ($pages->prefixes() as $prefix) {
                $urls[] = $pages->indexUrl($prefix, $code);
            }
        }

        return $urls;
    }
}
