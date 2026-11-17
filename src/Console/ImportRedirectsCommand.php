<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Console;

use Atrium\Admin\Support\Sites;
use Atrium\Seo\Redirects\CsvImporter;
use Illuminate\Console\Command;

/**
 * Imports redirects from a CSV file: source,target[,status] per line.
 */
final class ImportRedirectsCommand extends Command
{
    protected $signature = 'atrium:seo:redirects:import {file} {--site= : The id of the site} {--status=301} {--overwrite}';

    protected $description = 'Import redirects from a CSV file (source,target[,status])';

    public function handle(CsvImporter $importer, Sites $sites): int
    {
        $file = strval($this->argument('file'));

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $site = $this->option('site') === null ? $sites->default() : $sites->find($this->option('site'));

        if ($site === null) {
            $this->error('No such site.');

            return self::FAILURE;
        }

        $report = $importer->import((string) file_get_contents($file), (int) $site->id, (int) $this->option('status'), (bool) $this->option('overwrite'));

        $this->info("Created {$report['created']}, updated {$report['updated']}, skipped {$report['skipped']}.");

        foreach ($report['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
