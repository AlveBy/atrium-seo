<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Site;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Models\RedirectImport;
use Atrium\Seo\Redirects\CsvImporter;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * Redirects of an old site come by the hundred, as a spreadsheet.
 */
final class CsvImportTest extends TestCase
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
        return Redirect::query()->orderBy('id')->get()
            ->mapWithKeys(fn (Redirect $r): array => [$r->source => [$r->target, $r->status]])->all();
    }

    public function test_commas_with_a_header_and_a_status_per_line(): void
    {
        $report = $this->import("source,target,status\n/old,/new\n/sale,/catalog,302\n/gone,,410\n");

        $this->assertSame(['created' => 3, 'updated' => 0, 'skipped' => 0, 'errors' => []], $report);
        $this->assertSame(['/old' => ['/new', 301], '/sale' => ['/catalog', 302], '/gone' => [null, 410]], $this->redirects());
    }

    public function test_semicolons_tabs_a_bom_and_windows_line_ends(): void
    {
        $this->import("\xEF\xBB\xBF/a;/b\r\n/c;/d\r\n");
        $this->import("/e\t/f\n\n/g\t\"/h\"\n");

        $this->assertSame(['/a', '/c', '/e', '/g'], array_keys($this->redirects()));
        $this->assertSame(['/h', 301], $this->redirects()['/g']);
    }

    public function test_the_default_status_applies_to_lines_without_one(): void
    {
        $this->import("/a,/b\n/c,/d,301\n", 302);

        $this->assertSame(['/a' => ['/b', 302], '/c' => ['/d', 301]], $this->redirects());
    }

    public function test_bad_lines_are_skipped_with_their_number_and_reason(): void
    {
        $report = $this->import("/ok,/fine\nnot-an-address,/x\n/odd,/y,299\n/empty,\n/fine,/ok\n");

        $this->assertSame(1, $report['created']);
        $this->assertSame(4, $report['skipped']);
        $this->assertSame([
            __('atrium-seo::seo.import.line', ['line' => 2, 'error' => __('atrium-seo::seo.import.bad_source')]),
            __('atrium-seo::seo.import.line', ['line' => 3, 'error' => __('atrium-seo::seo.import.bad_status', ['status' => 299])]),
            __('atrium-seo::seo.import.line', ['line' => 4, 'error' => __('atrium-seo::seo.import.no_target')]),
            __('atrium-seo::seo.import.line', ['line' => 5, 'error' => __('atrium-seo::seo.errors.loop', ['chain' => '/fine → /ok → /fine'])]),
        ], $report['errors']);
        $this->assertSame(['/ok'], array_keys($this->redirects()));
    }

    public function test_an_existing_redirect_stays_unless_told_to_overwrite(): void
    {
        $this->import("/old,/first\n");

        $kept = $this->import("/old,/second\n");
        $this->assertSame([0, 0, 1], [$kept['created'], $kept['updated'], $kept['skipped']]);
        $this->assertSame([__('atrium-seo::seo.import.line', ['line' => 1, 'error' => __('atrium-seo::seo.import.exists')])], $kept['errors']);
        $this->assertSame('/first', $this->redirects()['/old'][0]);

        $replaced = $this->import("/old,/second\n", overwrite: true);
        $this->assertSame([0, 1, 0], [$replaced['created'], $replaced['updated'], $replaced['skipped']]);
        $this->assertSame('/second', $this->redirects()['/old'][0]);
    }

    public function test_overwriting_a_redirect_does_not_count_its_own_old_target_as_a_loop(): void
    {
        $this->import("/a,/b\n/b,/c\n");

        // /b → /a would close a loop; /b → /d replaces /b → /c and does not.
        $this->assertSame(1, $this->import("/b,/a\n", overwrite: true)['skipped']);
        $this->assertSame(1, $this->import("/b,/d\n", overwrite: true)['updated']);
    }

    public function test_upload_in_the_panel(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('redirects.csv', "source;target\n/old;/new\n/bad\n");

        $upload = $this->actingAs($this->admin(), 'admin')
            ->postJson('/admin/seo/redirect-csv', ['file' => $file])
            ->assertOk()
            ->assertJsonPath('url', 'redirects.csv')
            ->json('path');

        $this->assertStringStartsWith('seo-imports/', $upload);
        Storage::disk('local')->assertExists($upload);

        // The form saves what the field makes of the answer.
        $this->post('/admin/seo_redirect_imports', ['file' => '/storage/'.$upload, 'status' => 301, 'overwrite' => false])
            ->assertSessionHasNoErrors();

        $import = RedirectImport::query()->sole();
        $this->assertSame([1, 0, 1], [$import->created_count, $import->updated_count, $import->skipped_count]);
        $this->assertCount(1, $import->errors);
        $this->assertSame(__('atrium-seo::seo.import.result', ['created' => 1, 'updated' => 0, 'skipped' => 1]), $import->result);
        $this->assertSame(['/old' => ['/new', 301]], $this->redirects());

        // Opening an import shows its report, line by line.
        $this->get("/admin/seo_redirect_imports/{$import->id}/edit")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fields.0.name', 'report')
                ->where('item.report', fn (string $html): bool => str_contains($html, e($import->result)) && str_contains($html, '<li>'.e($import->errors[0]).'</li>')));

        // Saving it again does not import twice.
        $this->put("/admin/seo_redirect_imports/{$import->id}", [])->assertSessionHasNoErrors();
        $this->assertSame(1, Redirect::query()->count());
    }

    public function test_the_upload_takes_only_text_files_from_those_who_may_import(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/seo/redirect-csv', ['file' => UploadedFile::fake()->createWithContent('run.php', '<?php')])
            ->assertUnprocessable();
        $this->postJson('/admin/seo/redirect-csv', [])->assertUnprocessable();

        $admin->forceFill(['is_superadmin' => false])->save();
        $this->postJson('/admin/seo/redirect-csv', ['file' => UploadedFile::fake()->createWithContent('r.csv', '/a,/b')])
            ->assertForbidden();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_files_of_the_import_folder_are_read(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('secret.csv', "/a,/b\n");
        Storage::disk('local')->put('seo-imports/list.csv', "/c,/d\n");

        foreach (['/storage/secret.csv', '/storage/seo-imports/../secret.csv', 'seo-imports/missing.csv', ''] as $path) {
            $import = RedirectImport::query()->create(['file' => $path]);
            $import->afterAdminSave([]);
        }

        $this->assertSame([], $this->redirects());

        RedirectImport::query()->create(['file' => '/storage/seo-imports/list.csv'])->afterAdminSave([]);
        $this->assertSame(['/c' => ['/d', 301]], $this->redirects());
    }

    public function test_an_import_without_a_report_yet_shows_none(): void
    {
        $this->assertSame('', (new RedirectImport)->result);
    }

    public function test_the_command(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        $file = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, "/a,/b\n/a,/c\n");

        $this->artisan('atrium:seo:redirects:import', ['file' => $file, '--site' => $second->id, '--status' => 302])
            ->expectsOutput('Created 1, updated 0, skipped 1.')
            ->assertSuccessful();

        $this->assertSame($second->id, Redirect::query()->withoutGlobalScopes()->sole()->site_id);
        $this->assertSame(302, Redirect::query()->withoutGlobalScopes()->sole()->status);

        $this->artisan('atrium:seo:redirects:import', ['file' => $file])->expectsOutput('Created 1, updated 0, skipped 1.')->assertSuccessful();
        $this->assertSame(1, Redirect::query()->withoutGlobalScopes()->where('site_id', 1)->count());

        unlink($file);
    }

    public function test_the_command_refuses_a_missing_file_or_site(): void
    {
        $this->artisan('atrium:seo:redirects:import', ['file' => '/nonexistent.csv'])->expectsOutput('Cannot read /nonexistent.csv.')->assertFailed();

        $file = tempnam(sys_get_temp_dir(), 'csv');
        $this->artisan('atrium:seo:redirects:import', ['file' => $file, '--site' => 999])->expectsOutput('No such site.')->assertFailed();
        unlink($file);

        $this->assertSame(0, Redirect::query()->withoutGlobalScopes()->count());
    }
}
