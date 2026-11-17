<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Fields;

use Atrium\Admin\Fields\FileField;

/**
 * A file field that uploads to the module's own address, into the private
 * disk, instead of the panel's public uploads.
 */
final class CsvFileField extends FileField
{
    protected function getFieldProps(): array
    {
        return [...parent::getFieldProps(), 'uploadUrl' => route('atrium.seo.redirect-csv', [], false)];
    }
}
