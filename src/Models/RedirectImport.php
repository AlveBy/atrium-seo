<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Models;

use Atrium\Admin\Columns\DateColumn;
use Atrium\Admin\Columns\TextColumn;
use Atrium\Admin\Contracts\Administrable;
use Atrium\Admin\Fields\CheckboxField;
use Atrium\Admin\Fields\DisplayField;
use Atrium\Admin\Fields\SelectField;
use Atrium\Admin\Traits\BelongsToSite;
use Atrium\Admin\Traits\InteractsWithAdmin;
use Atrium\Seo\Fields\CsvFileField;
use Atrium\Seo\Redirects\CsvImporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A CSV file of redirects, imported when it is uploaded: "source,target"
 * or "source,target,status" per line, with or without a header row, with
 * commas, semicolons or tabs.
 *
 * @property int $id
 * @property int|null $site_id
 * @property string|null $file
 * @property int $status
 * @property bool $overwrite
 * @property int|null $created_count
 * @property int|null $updated_count
 * @property int|null $skipped_count
 * @property array<int,string>|null $errors
 */
class RedirectImport extends Model implements Administrable
{
    use BelongsToSite, InteractsWithAdmin;

    public const DISK = 'local';

    public const DIRECTORY = 'seo-imports';

    protected $table = 'seo_redirect_imports';

    protected $fillable = ['site_id', 'file', 'status', 'overwrite'];

    /** @var array<string,mixed> */
    protected $attributes = ['status' => 301, 'overwrite' => false];

    /** @var list<string> */
    protected $appends = ['result'];

    protected function casts(): array
    {
        return [
            'status' => 'integer', 'overwrite' => 'boolean', 'errors' => 'array',
            'created_count' => 'integer', 'updated_count' => 'integer', 'skipped_count' => 'integer',
        ];
    }

    public static function getAdminLabel(): string
    {
        return __('atrium-seo::seo.modules.imports');
    }

    public static function getAdminCreateLabel(): string
    {
        return __('atrium-seo::seo.create.import');
    }

    /** Service records, not content: no custom fields, no link target. */
    public static function isContentModule(): bool
    {
        return false;
    }

    public static function getAdminRoutePrefix(): string
    {
        return 'seo_redirect_imports';
    }

    public function getResultAttribute(): string
    {
        if ($this->created_count === null) {
            return '';
        }

        return __('atrium-seo::seo.import.result', [
            'created' => $this->created_count,
            'updated' => (int) $this->updated_count,
            'skipped' => (int) $this->skipped_count,
        ]);
    }

    public static function getAdministrationFields(): array
    {
        return [
            CsvFileField::make('file')->label(__('atrium-seo::seo.fields.file'))->hint(__('atrium-seo::seo.hints.csv'))
                ->disk(self::DISK)->directory(self::DIRECTORY)->accept('.csv,.txt')->maxSize(10240)->required()->showOnEdit(false),
            SelectField::make('status')->label(__('atrium-seo::seo.fields.default_status'))->options(Redirect::statusOptions())->default(301)->showOnEdit(false),
            CheckboxField::make('overwrite')->label(__('atrium-seo::seo.fields.overwrite'))->default(false)->showOnEdit(false),
            DisplayField::make('report')->showOnCreate(false)->label(__('atrium-seo::seo.fields.result'))->html(static function (Model $record): string {
                $errors = array_map(static fn (string $error): string => '<li>'.e($error).'</li>', (array) $record->getAttribute('errors'));

                return '<p>'.e((string) $record->getAttribute('result')).'</p>'.($errors === [] ? '' : '<ul>'.implode('', $errors).'</ul>');
            }),
        ];
    }

    public static function getAdministrationListColumns(): array
    {
        return [
            TextColumn::make('id')->label(__('atrium::fields.standard.id'))->sortable(),
            TextColumn::make('result')->label(__('atrium-seo::seo.fields.result')),
            DateColumn::make('created_at')->label(__('atrium::fields.standard.created_at'))->sortable(),
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function afterAdminSave(array $data): void
    {
        if ($this->created_count !== null || ! is_string($this->file) || $this->file === '') {
            return;
        }

        // The field saves "/storage/seo-imports/….csv"; nothing outside
        // the import folder is read.
        $path = Str::chopStart($this->file, ['/storage/', 'storage/']);
        $disk = Storage::disk(self::DISK);
        $inside = str_starts_with($path, self::DIRECTORY.'/') && ! str_contains($path, '..');

        $this->runImport($inside && $disk->exists($path) ? ($disk->get($path) ?? '') : '');
    }

    public function runImport(string $csv): void
    {
        $report = app(CsvImporter::class)->import($csv, (int) $this->site_id, (int) $this->status, $this->overwrite);

        $this->forceFill([
            'created_count' => $report['created'],
            'updated_count' => $report['updated'],
            'skipped_count' => $report['skipped'],
            'errors' => $report['errors'],
        ])->save();
    }
}
