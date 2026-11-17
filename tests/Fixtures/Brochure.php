<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Catalog\Models\Product;
use Atrium\Seo\Contracts\SitemapImages;

/**
 * A record that lists blank pictures around the one it has.
 */
final class Brochure extends Product implements SitemapImages
{
    public function sitemapImages(): array
    {
        return ['', 'brochures/'.$this->code.'.jpg', '', 'brochures/'.$this->code.'-back.jpg'];
    }
}
