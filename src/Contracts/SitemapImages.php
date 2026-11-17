<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Contracts;

/**
 * The pictures of a record for the sitemap's <image:image>. Without it the
 * module takes the record's picture fields (detail_picture, preview_picture,
 * picture, image, photo, cover, photos, gallery).
 */
interface SitemapImages
{
    /**
     * @return array<int,string> addresses or paths of the images
     */
    public function sitemapImages(): array;
}
