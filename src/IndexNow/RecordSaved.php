<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\IndexNow;

use Atrium\Admin\Contracts\Sitemapable;
use Atrium\Admin\Models\SeoMeta;
use Atrium\Admin\Support\ContentLanguages;
use Atrium\Admin\Support\PublicUrl;
use Atrium\Seo\Audit\PageCollector;
use Atrium\Seo\Robots;
use Illuminate\Database\Eloquent\Model;

/**
 * "atrium.record.saved": the public pages of a record saved in the panel
 * go to IndexNow, in every language. Records without a public page, or
 * kept out of search engines, send nothing.
 */
final class RecordSaved
{
    public function __construct(
        private readonly IndexNow $indexNow,
        private readonly ContentLanguages $languages,
    ) {}

    public function __invoke(mixed $record): void
    {
        if (! $record instanceof Model || ! $this->indexNow->enabled()) {
            return;
        }

        if (method_exists($record, 'seoMeta')) {
            $meta = $record->load('seoMeta')->getRelationValue('seoMeta');

            if ($meta instanceof SeoMeta && Robots::isNoindex($meta->robots)) {
                return;
            }
        }

        $this->indexNow->queue($this->urls($record));
    }

    /**
     * @return array<int,string>
     */
    public function urls(Model $record): array
    {
        $urls = [];

        if ($record instanceof Sitemapable) {
            if (! $record::sitemapQuery()->whereKey($record->getKey())->exists()) {
                return [];
            }

            foreach ($this->languages->codes() as $code) {
                $urls[] = $record->sitemapUrl($code);
            }
        } elseif (class_exists(PageCollector::THEME_PAGES)) {
            $pages = app(PageCollector::THEME_PAGES);

            if (PublicUrl::ruleFor($record::class) === null
                || ! $pages->visible($record::class)->whereKey($record->getKey())->exists()) {
                return [];
            }

            foreach ($this->languages->codes() as $code) {
                $urls[] = $pages->url($record, $code);
            }
        }

        return array_values(array_unique(array_filter($urls, static fn (mixed $url): bool => is_string($url) && $url !== '')));
    }
}
