<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A module whose records live in a database that is not there.
 */
final class Ghost extends Model
{
    protected $connection = 'nowhere';

    protected $table = 'ghosts';
}
