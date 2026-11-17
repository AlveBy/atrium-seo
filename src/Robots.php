<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo;

/**
 * The values of the robots meta tag the panel offers.
 */
final class Robots
{
    public const INDEX = 'index, follow';

    public const NOINDEX = 'noindex, follow';

    public const NONE = 'noindex, nofollow';

    /**
     * @return array<string,string>
     */
    public static function options(): array
    {
        return [
            self::INDEX => __('atrium-seo::seo.robots.index'),
            self::NOINDEX => __('atrium-seo::seo.robots.noindex'),
            self::NONE => __('atrium-seo::seo.robots.none'),
        ];
    }

    public static function isNoindex(?string $robots): bool
    {
        return $robots !== null && str_contains(strtolower($robots), 'noindex');
    }
}
