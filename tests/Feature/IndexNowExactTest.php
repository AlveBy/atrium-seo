<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\Language;
use Atrium\Admin\Models\Site;
use Atrium\Seo\IndexNow\IndexNow;
use Atrium\Seo\IndexNow\RecordSaved;
use Atrium\Seo\Tests\Fixtures\Placard;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use ReflectionProperty;
use RuntimeException;

/**
 * IndexNow to the letter: the key, the switches, the throttle, the request
 * and the pages of a record in every language.
 */
final class IndexNowExactTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function indexNow(): IndexNow
    {
        return app(IndexNow::class);
    }

    private function site(): Site
    {
        return Site::query()->findOrFail(1);
    }

    private function second(): Site
    {
        return Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);
    }

    private function terminating(): int
    {
        return count((new ReflectionProperty($this->app, 'terminatingCallbacks'))->getValue($this->app));
    }

    public function test_the_key_is_thirty_two_characters_of_an_hmac_of_the_site_by_the_app_key(): void
    {
        $second = $this->second();

        $this->assertSame(substr(hash_hmac('sha256', 'indexnow:'.$this->site()->id, (string) config('app.key')), 0, 32), $this->indexNow()->key());
        $this->assertSame($this->indexNow()->key(), $this->indexNow()->key($this->site()));
        $this->assertSame(substr(hash_hmac('sha256', 'indexnow:'.$second->id, (string) config('app.key')), 0, 32), $this->indexNow()->key($second));

        config(['app.key' => 'another-key']);
        $this->assertSame(substr(hash_hmac('sha256', 'indexnow:'.$this->site()->id, 'another-key'), 0, 32), $this->indexNow()->key());
    }

    public function test_a_site_switches_it_off_for_itself_only(): void
    {
        $second = $this->second();
        Setting::save('seo', ['indexnow' => false], $second);

        $this->assertTrue($this->indexNow()->enabled());
        $this->assertTrue($this->indexNow()->enabled($this->site()));
        $this->assertFalse($this->indexNow()->enabled($second));

        $this->indexNow()->queue(['https://example.com/site2/a'], $second);
        $this->assertSame(0, $this->indexNow()->flush());
        Http::assertNothingSent();
    }

    public function test_it_is_on_where_nothing_was_decided_and_off_outside_production_or_by_config(): void
    {
        $this->assertTrue($this->indexNow()->enabled());

        config(['atrium-seo.indexnow' => []]);
        $this->assertTrue($this->indexNow()->enabled());

        $this->app->detectEnvironment(static fn (): string => 'staging');
        $this->assertFalse($this->indexNow()->enabled());
    }

    public function test_the_queue_is_sent_once_the_response_is_out_and_the_callback_is_added_once_a_batch(): void
    {
        $before = $this->terminating();

        $this->indexNow()->queue(['https://example.com/a']);
        $this->indexNow()->queue(['https://example.com/b']);
        $this->assertSame($before + 1, $this->terminating());
        Http::assertNothingSent();

        $this->assertSame(2, $this->indexNow()->flush());
        $this->assertSame(0, $this->indexNow()->flush());
        Http::assertSentCount(2);

        $this->indexNow()->queue(['https://example.com/c']);
        $this->assertSame($before + 2, $this->terminating());
        $this->assertSame(1, $this->indexNow()->flush());
    }

    public function test_the_queue_of_each_site_goes_with_its_own_key_and_the_total_is_returned(): void
    {
        config(['atrium-seo.indexnow.endpoints' => ['https://engine.test/indexnow']]);
        $second = $this->second();

        $this->indexNow()->queue(['https://example.com/a', 'https://example.com/b']);
        $this->indexNow()->queue(['https://example.com/site2/c'], $second);

        $this->assertSame(3, $this->indexNow()->flush());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request['key'] === $this->indexNow()->key()
            && $request['keyLocation'] === $this->site()->url().'/'.$this->indexNow()->key().'.txt'
            && $request['urlList'] === ['https://example.com/a', 'https://example.com/b']);
        Http::assertSent(fn (Request $request): bool => $request['key'] === $this->indexNow()->key($second)
            && $request['keyLocation'] === $second->url().'/'.$this->indexNow()->key($second).'.txt'
            && $request['urlList'] === ['https://example.com/site2/c']);
    }

    public function test_the_request_has_a_host_a_short_timeout_and_asks_for_json(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = [$request->url(), $request->hasHeader('Accept', 'application/json'), $options['timeout'] ?? null, $request->method()];

            return Http::response('');
        });

        $this->assertSame(1, $this->indexNow()->send($this->site(), ['https://shop.example.org/a']));

        $this->assertSame([
            ['https://www.bing.com/indexnow', true, 5, 'POST'],
            ['https://yandex.com/indexnow', true, 5, 'POST'],
        ], $seen);
        Http::assertSent(fn (Request $request): bool => $request['host'] === 'shop.example.org');
    }

    public function test_a_relative_address_has_an_empty_host(): void
    {
        $this->assertSame(1, $this->indexNow()->send($this->site(), ['/a']));

        Http::assertSent(fn (Request $request): bool => $request['host'] === '' && $request['urlList'] === ['/a']);
    }

    public function test_the_engines_may_be_named_by_one_string_and_only_strings_are_used(): void
    {
        config(['atrium-seo.indexnow.endpoints' => 'https://engine.test/indexnow']);
        $this->indexNow()->send($this->site(), ['https://example.com/a']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://engine.test/indexnow');

        config(['atrium-seo.indexnow.endpoints' => [5, null, 'https://engine.test/other']]);
        $this->indexNow()->send($this->site(), ['https://example.com/b']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://engine.test/other');
    }

    public function test_an_engine_that_fails_is_reported_and_the_next_one_still_hears(): void
    {
        Exceptions::fake();
        $urls = [];
        Http::fake(function (Request $request) use (&$urls) {
            $urls[] = $request->url();

            if ($request->url() === 'https://www.bing.com/indexnow') {
                throw new RuntimeException('down');
            }

            return Http::response('');
        });

        $this->assertSame(1, $this->indexNow()->send($this->site(), ['https://example.com/a']));

        $this->assertSame(['https://www.bing.com/indexnow', 'https://yandex.com/indexnow'], $urls);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'down');
    }

    public function test_an_address_is_held_back_for_ten_minutes_by_default(): void
    {
        config(['atrium-seo.indexnow' => []]);
        Carbon::setTestNow('2026-05-01 12:00:00');

        $this->assertSame(1, $this->indexNow()->send($this->site(), ['https://example.com/a']));
        $this->assertSame('https://example.com/a', Cache::get('atrium.seo.indexnow.'.sha1('https://example.com/a')));

        Carbon::setTestNow('2026-05-01 12:09:30');
        $this->assertSame(0, $this->indexNow()->send($this->site(), ['https://example.com/a']));

        Carbon::setTestNow('2026-05-01 12:10:30');
        $this->assertSame(1, $this->indexNow()->send($this->site(), ['https://example.com/a']));
    }

    public function test_the_throttle_may_be_written_as_a_string_of_minutes(): void
    {
        config(['atrium-seo.indexnow.throttle_minutes' => '3']);
        Carbon::setTestNow('2026-05-01 12:00:00');

        $this->assertSame(1, $this->indexNow()->send($this->site(), ['https://example.com/a']));

        Carbon::setTestNow('2026-05-01 12:02:30');
        $this->assertSame(0, $this->indexNow()->send($this->site(), ['https://example.com/a']));

        Carbon::setTestNow('2026-05-01 12:03:30');
        $this->assertSame(1, $this->indexNow()->send($this->site(), ['https://example.com/a']));
    }

    public function test_without_a_throttle_nothing_is_remembered_and_an_address_goes_out_once_a_batch(): void
    {
        foreach ([0, -3] as $minutes) {
            config(['atrium-seo.indexnow.throttle_minutes' => $minutes]);
            Http::fake();

            $this->assertSame(2, $this->indexNow()->send($this->site(), ['https://example.com/a', 'https://example.com/a', 'https://example.com/b']), (string) $minutes);
            $this->assertSame(2, $this->indexNow()->send($this->site(), ['https://example.com/a', 'https://example.com/b']), (string) $minutes);
            $this->assertFalse(Cache::has('atrium.seo.indexnow.'.sha1('https://example.com/a')), (string) $minutes);
            Http::assertSent(fn (Request $request): bool => $request['urlList'] === ['https://example.com/a', 'https://example.com/b']);
        }
    }

    public function test_a_batch_of_only_addresses_sent_lately_makes_no_request(): void
    {
        $this->assertSame(1, $this->indexNow()->send($this->site(), ['https://example.com/a']));
        Http::fake();

        $this->assertSame(0, $this->indexNow()->send($this->site(), ['https://example.com/a']));
        Http::assertNothingSent();
    }

    public function test_a_record_has_one_address_in_each_language_it_has_a_page_in(): void
    {
        foreach (['fr', 'de', 'es'] as $code) {
            Language::query()->create(['code' => $code, 'name' => strtoupper($code)]);
        }
        $placard = Placard::query()->create(['name' => 'Placard', 'code' => 'big', 'active' => true]);

        $this->assertSame([$this->root().'/placards/big'], app(RecordSaved::class)->urls($placard));
    }
}
