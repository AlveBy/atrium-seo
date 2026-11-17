<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Site;
use Atrium\Seo\Models\NotFound;
use Atrium\Seo\Redirects\NotFoundLog;
use Atrium\Seo\Support\Paths;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia;

/**
 * The finer rules of the 404 log: whose it is, how long it keeps what.
 */
final class NotFoundLogRulesTest extends TestCase
{
    private function log(): NotFoundLog
    {
        return app(NotFoundLog::class);
    }

    public function test_an_address_goes_to_the_log_of_the_site_it_was_asked_of(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);

        $this->log()->hit('/lost', $second->id);
        $this->log()->hit('/lost', null);

        $this->assertSame(
            [1, $second->id],
            NotFound::query()->withoutGlobalScopes()->orderBy('site_id')->pluck('site_id')->all(),
        );
    }

    public function test_the_latest_referer_wins_and_a_missing_one_keeps_the_old(): void
    {
        $this->log()->hit('/lost', 1, 'https://example.org/a');
        $this->log()->hit('/lost', 1, 'https://example.org/b');
        $this->assertSame('https://example.org/b', NotFound::query()->sole()->referer);

        $this->log()->hit('/lost', 1);
        $row = NotFound::query()->sole();
        $this->assertSame('https://example.org/b', $row->referer);
        $this->assertSame(3, $row->hits);
    }

    public function test_a_known_address_is_counted_not_added(): void
    {
        $created = 0;
        NotFound::creating(function () use (&$created): void {
            $created++;
        });

        $this->log()->hit('/lost', 1);
        $this->log()->hit('/lost', 1);

        $this->assertSame(1, $created);
        $this->assertSame(2, NotFound::query()->sole()->hits);
    }

    public function test_a_long_address_and_referer_are_cut_by_letters_not_by_bytes(): void
    {
        // The ignored patterns are checked against it too, past 1024 bytes.
        $path = '/'.str_repeat('ф', 2100);

        $this->log()->hit($path, 1, 'https://example.org/'.str_repeat('я', 2100));

        $row = NotFound::query()->sole();
        $this->assertSame(2048, mb_strlen($row->path));
        $this->assertSame(2048, mb_strlen((string) $row->referer));
        // The hash is of the whole address: the next visit finds the row.
        $this->assertSame(Paths::hash(Paths::normalize($path)), $row->path_hash);

        $this->log()->hit($path, 1, 'https://example.org/'.str_repeat('я', 2100));
        $this->assertSame(2, NotFound::query()->sole()->hits);
    }

    public function test_a_short_referer_is_kept_whole(): void
    {
        $this->log()->hit('/lost', 1, 'https://example.org/'.str_repeat('a', 2028));

        $this->assertSame(2048, strlen((string) NotFound::query()->sole()->referer));
    }

    public function test_the_ignored_addresses_may_be_given_as_one_pattern(): void
    {
        config(['atrium-seo.not_found.ignore' => '/skip*']);

        $this->log()->hit('/skipped', 1);
        $this->log()->hit('/kept', 1);

        $this->assertSame(['/kept'], NotFound::query()->pluck('path')->all());
    }

    public function test_the_log_keeps_at_least_one_address(): void
    {
        config(['atrium-seo.not_found.keep' => 0]);

        Date::setTestNow('2026-11-01 10:00');
        $this->log()->hit('/first', 1);
        $this->assertSame(['/first'], NotFound::query()->pluck('path')->all());

        Date::setTestNow('2026-11-02 10:00');
        $this->log()->hit('/second', 1);
        $this->assertSame(['/second'], NotFound::query()->pluck('path')->all());
    }

    public function test_by_default_the_log_keeps_five_thousand_addresses(): void
    {
        $rows = [];

        for ($i = 0; $i < 5000; $i++) {
            $rows[] = [
                'site_id' => 1, 'path' => "/p{$i}", 'path_hash' => Paths::hash("/p{$i}"), 'hits' => 1,
                'first_seen_at' => '2026-01-01 10:00:00', 'last_seen_at' => '2026-01-01 10:00:00',
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            NotFound::query()->withoutGlobalScopes()->insert($chunk);
        }

        config(['atrium-seo.not_found' => ['enabled' => true, 'ignore' => []]]);

        // Room for exactly the limit: nothing goes yet.
        $this->log()->hit('/p0', 1);
        $this->assertSame(5000, NotFound::query()->count());

        $this->log()->hit('/fresh', 1);

        $this->assertSame(5000, NotFound::query()->count());
        $this->assertTrue(NotFound::query()->where('path', '/fresh')->exists());
        $this->assertTrue(NotFound::query()->where('path', '/p0')->exists());
        $this->assertFalse(NotFound::query()->where('path', '/p1')->exists());
    }

    public function test_the_log_of_one_site_does_not_push_out_another(): void
    {
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
        config(['atrium-seo.not_found.keep' => 1]);

        $this->log()->hit('/a', 1);
        $this->log()->hit('/b', $second->id);
        $this->log()->hit('/c', 1);

        $this->assertSame(['/b', '/c'], NotFound::query()->withoutGlobalScopes()->orderBy('path')->pluck('path')->all());
    }

    public function test_the_log_in_the_panel(): void
    {
        $this->log()->hit('/lost', 1, 'https://example.org/links');

        $this->actingAs($this->admin(), 'admin')->get('/admin/seo_not_found')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('columns', fn ($columns): bool => collect($columns)->pluck('name')->all() === ['path', 'hits', 'referer', 'last_seen_at', 'create_redirect'])
                ->where('columns.0.searchable', true)
                ->where('columns.2.limit', 60));
    }

    public function test_the_log_is_read_only(): void
    {
        $row = NotFound::query()->withoutGlobalScopes()->create(['site_id' => 1, 'path' => '/lost', 'path_hash' => 'h', 'hits' => 1]);

        $this->assertFalse(NotFound::isCreatableInAdmin());
        $this->assertFalse(NotFound::isEditableInAdmin());
        $this->actingAs($this->admin(), 'admin')->put("/admin/seo_not_found/{$row->id}", ['hits' => 99])->assertForbidden();
        $this->assertSame(1, $row->fresh()->hits);
    }

    public function test_the_columns_of_a_row_are_read_by_name_too(): void
    {
        $row = (new NotFound)->forceFill(['path' => '/lost', 'hits' => '4']);

        $this->assertSame(4, $row->hits);
        $this->assertSame(__('atrium-seo::seo.actions.create_redirect'), $row->getCreateRedirectAttribute());
        $this->assertSame(route('admin.seo_redirects.create', ['source' => '/lost']), $row->getCreateRedirectUrlAttribute());
    }
}
