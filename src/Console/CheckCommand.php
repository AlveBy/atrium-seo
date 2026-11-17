<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Console;

use Atrium\Admin\Support\Sites;
use Atrium\Seo\Audit\Audit;
use Illuminate\Console\Command;

/**
 * Runs the SEO check of every site (or one), for a schedule on big sites
 * where opening the list should not run it.
 */
final class CheckCommand extends Command
{
    protected $signature = 'atrium:seo:check {--site= : The id of one site}';

    protected $description = 'Run the SEO check and fill SEO → Check';

    public function handle(Audit $audit, Sites $sites): int
    {
        $only = $this->option('site');
        $found = false;

        foreach ($sites->all() as $site) {
            if ($only !== null && (string) $site->id !== (string) $only) {
                continue;
            }

            $count = $audit->run($site);
            $found = true;
            $this->line($site->name.': '.$count);
        }

        if (! $found) {
            $this->error('No such site.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
