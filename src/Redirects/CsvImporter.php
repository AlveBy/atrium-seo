<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Redirects;

use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Support\Locale;
use Atrium\Seo\Support\Paths;
use Illuminate\Support\Str;

/**
 * Redirects from a CSV file: one per line, "source,target[,status]". The
 * delimiter is guessed (comma, semicolon, tab); a first line that is not an
 * address is a header. Lines that would close a loop, carry a status the
 * module does not know or have no target are skipped with a reason.
 */
final class CsvImporter
{
    public function __construct(private readonly RedirectMap $map) {}

    /**
     * @return array{created:int,updated:int,skipped:int,errors:array<int,string>}
     */
    public function import(string $csv, int $siteId, int $status = 301, bool $overwrite = false): array
    {
        $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $csv = Str::chopStart($csv, "\xEF\xBB\xBF");
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        $delimiter = $this->delimiter($lines[0] ?? '');
        $language = app()->getLocale();

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if (trim($line) === '') {
                continue;
            }

            $row = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            [$source, $target, $lineStatus] = [...$row, '', '', ''];

            if ($index === 0 && ! $this->looksLikeAddress($source)) {
                continue;
            }

            $lineStatus = $lineStatus === '' ? $status : (int) $lineStatus;
            $error = $this->problem($source, $target, $lineStatus, $siteId);

            if ($error !== null) {
                $report['skipped']++;
                $report['errors'][] = Locale::trans('atrium-seo::seo.import.line', $language, ['line' => $number, 'error' => $error]);

                continue;
            }

            $clean = Redirect::cleanSource($source);
            $existing = Redirect::query()->withoutGlobalScopes()->where('site_id', $siteId)->where('source_hash', Paths::hash($clean))->first();

            if ($existing !== null && ! $overwrite) {
                $report['skipped']++;
                $report['errors'][] = Locale::trans('atrium-seo::seo.import.line', $language, [
                    'line' => $number, 'error' => Locale::trans('atrium-seo::seo.import.exists', $language),
                ]);

                continue;
            }

            $redirect = $existing ?? new Redirect(['site_id' => $siteId]);
            $redirect->fill(['source' => $clean, 'target' => $target, 'status' => $lineStatus, 'active' => true])->save();
            $report[$existing === null ? 'created' : 'updated']++;
        }

        return $report;
    }

    private function problem(string $source, string $target, int $status, int $siteId): ?string
    {
        $language = app()->getLocale();

        if (! $this->looksLikeAddress($source)) {
            return Locale::trans('atrium-seo::seo.import.bad_source', $language);
        }

        if (! in_array($status, Redirect::STATUSES, true)) {
            return Locale::trans('atrium-seo::seo.import.bad_status', $language, ['status' => $status]);
        }

        $target = Redirect::cleanTarget($target, $status);

        if ($status !== 410 && $target === null) {
            return Locale::trans('atrium-seo::seo.import.no_target', $language);
        }

        if ($target !== null) {
            $source = Redirect::cleanSource($source);
            $loop = $this->map->loopWith($source, $target, null, $siteId);

            if ($loop !== null) {
                return Locale::trans('atrium-seo::seo.errors.loop', $language, ['chain' => implode(' → ', $loop)]);
            }
        }

        return null;
    }

    private function looksLikeAddress(string $value): bool
    {
        return str_starts_with($value, '/') || preg_match('#^https?://#i', $value) === 1;
    }

    private function delimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
