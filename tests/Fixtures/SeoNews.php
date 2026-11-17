<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Atrium\Admin\Traits\HasAdminSeo;
use Atrium\News\Models\News;

/**
 * News written in several languages that also has the SEO tab, which holds
 * one text for all of them.
 */
final class SeoNews extends News
{
    use HasAdminSeo;
}
