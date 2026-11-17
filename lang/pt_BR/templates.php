<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

return [
    'default' => [
        'title' => '{name} | {site}',
        'description' => '{excerpt}',
    ],
    'home' => [
        'title' => '{site}',
    ],
    'index' => [
        'title' => '{name} | {site}',
    ],
    'product' => [
        'title' => '{name}[ — {price}] | {site}',
        'description' => 'Compre {name}[ por {price}].[ {excerpt}]',
    ],
    'section' => [
        'title' => '{name} | {site}',
        'description' => '{excerpt}',
    ],
    'article' => [
        'title' => '{name} | {site}',
        'description' => '[{date}. ]{excerpt}',
    ],
    'place' => [
        'title' => '{name}[, {address}] | {site}',
        'description' => '{name}[: {address}].[ {excerpt}]',
    ],
];
