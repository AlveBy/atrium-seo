<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Models\RedirectImport;
use Atrium\Seo\Redirects\CsvImporter;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * The finer rules of a CSV import: what a line may look like, which files
 * of the disk are read, what the panel shows of it.
 */
final class CsvImportRulesTest extends TestCase
{
    /**
     * @return array{created:int,updated:int,skipped:int,errors:array<int,string>}
     */
    private function import(string $csv, int $status = 301, bool $overwrite = false): array
    {
        return app(CsvImporter::class)->import($csv, 1, $status, $overwrite);
    }

    /**
     * @return array<string,array{0:string|null,1:int}>
     */
    private function redirects(): array
    {
        return Redirect::query()->withoutGlobalScopes()->orderBy('id')->get()
            ->mapWithKeys(fn (Redirect $r): array => [$r->source => [$r->target, $r->status]])->all();
    }

    private function line(int $number, string $error): string
    {
        return __('atrium-seo::seo.import.line', ['line' => $number, 'error' => $error]);
    }

    // -- the importer -----------------------------------------------------

    public function test_without_arguments_the_import_is_permanent_and_leaves_what_exists(): void
    {
        $importer = app(CsvImporter::class);

        $this->assertSame(1, $importer->import("/a,/b\n", siteId: 1)['created']);
        $kept = $importer->import("/a,/c\n", siteId: 1);

        $this->assertSame([0, 0, 1], [$kept['created'], $kept['updated'], $kept['skipped']]);
        $this->assertSame(301, Redirect::query()->withoutGlobalScopes()->sole()->status);
        $this->assertSame('/b', Redirect::query()->withoutGlobalScopes()->sole()->target);
    }

    public function test_empty_lines_around_and_inside_do_not_matter(): void
    {
        $report = $this->import("\n\n/a;/b\n   \n\t\n/c;/d\n\n  \n");

        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0, 'errors' => []], $report);
        $this->assertSame(['/a', '/c'], array_keys($this->redirects()));
    }

    public function test_the_delimiter_is_found_after_the_empty_lines_too(): void
    {
        $this->assertSame(1, $this->import("\n\n/a;/b\n")['created']);
        $this->assertSame(['/a' => ['/b', 301]], $this->redirects());
    }

    public function test_cells_are_trimmed(): void
    {
        $this->assertSame(1, $this->import(" /a ; /b ; 302 \n")['created']);

        $this->assertSame(['/a' => ['/b', 302]], $this->redirects());
    }

    public function test_cells_of_later_lines_are_trimmed_too(): void
    {
        $report = $this->import("/x,/y\n /a , /b ,410\n \t/c,/d \n");

        $this->assertSame(['created' => 3, 'updated' => 0, 'skipped' => 0, 'errors' => []], $report);
        $this->assertSame(['/x' => ['/y', 301], '/a' => [null, 410], '/c' => ['/d', 301]], $this->redirects());
    }

    public function test_a_first_line_that_is_not_an_address_is_a_header_even_if_it_mentions_one(): void
    {
        $report = $this->import("see http://old.example/x,see /new\n/c,/d\n");

        $this->assertSame(1, $report['created']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(['/c' => ['/d', 301]], $this->redirects());
    }

    public function test_an_address_may_be_a_full_one_in_any_case(): void
    {
        $report = $this->import("HTTP://Old.example/x,/new\nhttps://old.example/y,/other\n");

        $this->assertSame(2, $report['created']);
        $this->assertSame(['/x' => ['/new', 301], '/y' => ['/other', 301]], $this->redirects());
    }

    public function test_a_source_that_only_mentions_an_address_is_refused(): void
    {
        $report = $this->import("/ok,/fine\nsee http://old.example/x,/new\n");

        $this->assertSame([$this->line(2, __('atrium-seo::seo.import.bad_source'))], $report['errors']);
        $this->assertSame(['/ok'], array_keys($this->redirects()));
    }

    public function test_a_line_with_one_cell_has_no_target(): void
    {
        $report = $this->import("/lonely\n/ok,/fine\n");

        $this->assertSame(1, $report['created']);
        $this->assertSame([$this->line(1, __('atrium-seo::seo.import.no_target'))], $report['errors']);
    }

    public function test_the_lines_after_a_known_address_are_read_too(): void
    {
        $this->import("/old,/first\n");

        $report = $this->import("/old,/second\n/other,/third\n");

        $this->assertSame([1, 0, 1], [$report['created'], $report['updated'], $report['skipped']]);
        $this->assertSame(['/old' => ['/first', 301], '/other' => ['/third', 301]], $this->redirects());
    }

    public function test_a_page_gone_for_good_needs_no_target_and_others_do(): void
    {
        $report = $this->import("/gone,,410\n/lost,,301\n");

        $this->assertSame(1, $report['created']);
        $this->assertSame([$this->line(2, __('atrium-seo::seo.import.no_target'))], $report['errors']);
    }

    // -- the command ------------------------------------------------------

    public function test_the_command_tells_what_it_skipped(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, "/a,/b\n/a,/c\n");

        $this->artisan('atrium:seo:redirects:import', ['file' => $file])
            ->expectsOutput('Created 1, updated 0, skipped 1.')
            ->expectsOutputToContain($this->line(2, __('atrium-seo::seo.import.exists')))
            ->assertSuccessful();

        unlink($file);
    }

    public function test_the_command_does_not_take_a_folder_for_a_file(): void
    {
        $this->artisan('atrium:seo:redirects:import', ['file' => sys_get_temp_dir()])
            ->expectsOutput('Cannot read '.sys_get_temp_dir().'.')
            ->assertFailed();
    }

    public function test_the_command_overwrites_when_told_to(): void
    {
        $this->import("/a,/b\n");
        $file = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, "/a,/c\n");

        $this->artisan('atrium:seo:redirects:import', ['file' => $file, '--overwrite' => true])
            ->expectsOutput('Created 0, updated 1, skipped 0.')
            ->assertSuccessful();

        unlink($file);
        $this->assertSame('/c', $this->redirects()['/a'][0]);
    }

    // -- the upload -------------------------------------------------------

    public function test_the_upload_answers_with_the_name_and_a_private_path(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/seo/redirect-csv', ['file' => UploadedFile::fake()->createWithContent('my list.txt', "/a,/b\n")])
            ->assertOk();

        $json = $response->json();
        ksort($json);

        $this->assertSame(['filename', 'path', 'url'], array_keys($json));
        $this->assertSame('my list.txt', $json['filename']);
        $this->assertSame('my list.txt', $json['url']);
        // A file of its own, whatever it was called, and a .csv.
        $this->assertMatchesRegularExpression('#^seo-imports/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.csv$#', $json['path']);
        Storage::disk('local')->assertExists($json['path']);
    }

    public function test_the_upload_field_knows_where_to_upload(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_redirect_imports/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fields', fn ($fields): bool => collect($fields)->pluck('name')->all() === ['file', 'status', 'overwrite'])
                ->where('fields.0.type', 'file')
                ->where('fields.0.required', true)
                ->where('fields.0.disk', 'local')
                ->where('fields.0.directory', 'seo-imports')
                ->where('fields.0.accept', '.csv,.txt')
                ->where('fields.0.maxSize', 10240)
                ->where('fields.0.uploadUrl', '/admin/seo/redirect-csv')
                ->where('fields.1.default', 301)
                ->where('fields.2.default', false));
    }

    // -- the import record ------------------------------------------------

    public function test_the_numbers_of_an_import_are_numbers(): void
    {
        $import = (new RedirectImport)->forceFill(['status' => '302', 'overwrite' => '1', 'created_count' => '3', 'updated_count' => '2', 'skipped_count' => '1']);

        $this->assertSame(302, $import->status);
        $this->assertTrue($import->overwrite);
        $this->assertSame(3, $import->created_count);
    }

    public function test_the_result_counts_what_was_not_told_as_none(): void
    {
        $import = (new RedirectImport)->forceFill(['created_count' => 3]);

        $this->assertSame(__('atrium-seo::seo.import.result', ['created' => 3, 'updated' => 0, 'skipped' => 0]), $import->getResultAttribute());
        $this->assertSame('', (new RedirectImport)->getResultAttribute());
    }

    public function test_a_report_is_a_paragraph_and_a_list_of_the_lines(): void
    {
        $quiet = RedirectImport::query()->create(['file' => '']);
        $quiet->forceFill(['created_count' => 2, 'updated_count' => 0, 'skipped_count' => 0, 'errors' => []])->save();
        $noisy = RedirectImport::query()->create(['file' => '']);
        $noisy->forceFill(['created_count' => 0, 'updated_count' => 0, 'skipped_count' => 2, 'errors' => ['Line 1: a<b', 'Line 2: c']])->save();

        $this->actingAs($this->admin(), 'admin');
        $this->get("/admin/seo_redirect_imports/{$quiet->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fields', fn ($fields): bool => collect($fields)->pluck('name')->all() === ['report'])
                ->where('item.report', '<p>'.e($quiet->result).'</p>'));
        $this->get("/admin/seo_redirect_imports/{$noisy->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('item.report', '<p>'.e($noisy->result).'</p><ul><li>Line 1: a&lt;b</li><li>Line 2: c</li></ul>'));
    }

    public function test_a_file_is_imported_once_and_only_when_there_is_one(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('seo-imports/list.csv', "/c,/d\n");

        foreach ([null, ''] as $file) {
            $import = RedirectImport::query()->create(['file' => $file]);
            $import->afterAdminSave([]);
            $this->assertNull($import->created_count);
        }

        $done = RedirectImport::query()->create(['file' => '/storage/seo-imports/list.csv']);
        $done->forceFill(['created_count' => 5]);
        $done->afterAdminSave([]);

        $this->assertSame([5, []], [$done->created_count, $this->redirects()]);

        $fresh = RedirectImport::query()->create(['file' => '/storage/seo-imports/list.csv']);
        $fresh->afterAdminSave([]);

        $this->assertSame(1, $fresh->created_count);
        $this->assertSame(['/c' => ['/d', 301]], $this->redirects());
    }

    public function test_the_file_may_be_named_the_way_the_field_saves_it(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put('seo-imports/a.csv', "/a,/target\n");
        Storage::disk('local')->put('seo-imports/b.csv', "/b,/target\n");
        Storage::disk('local')->put('seo-imports/c.csv', "/c,/target\n");

        foreach (['/storage/seo-imports/a.csv', 'storage/seo-imports/b.csv', 'seo-imports/c.csv'] as $name) {
            $import = RedirectImport::query()->create(['file' => $name]);
            $import->afterAdminSave([]);

            $this->assertSame(1, $import->created_count, $name);
        }

        $this->assertSame(['/a', '/b', '/c'], array_keys($this->redirects()));
    }

    public function test_a_folder_that_only_starts_like_the_import_folder_is_not_read(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('seo-imports-old/list.csv', "/c,/d\n");
        Storage::disk('local')->put('seo-imports.csv', "/e,/f\n");

        foreach (['/storage/seo-imports-old/list.csv', 'seo-imports.csv', '/seo-imports/list.csv'] as $file) {
            $import = RedirectImport::query()->create(['file' => $file]);
            $import->afterAdminSave([]);

            $this->assertSame(0, $import->created_count, $file);
        }

        $this->assertSame([], $this->redirects());
    }

    public function test_an_import_can_be_run_on_a_text(): void
    {
        $import = RedirectImport::query()->create(['file' => null, 'status' => 302]);
        $import->runImport("/a,/b\n/a,/c\n");

        $this->assertSame([1, 0, 1], [$import->created_count, $import->updated_count, $import->skipped_count]);
        $this->assertSame(['/a' => ['/b', 302]], $this->redirects());
        $this->assertSame(1, $import->fresh()->created_count);
        $this->assertCount(1, $import->fresh()->errors);
    }
}
