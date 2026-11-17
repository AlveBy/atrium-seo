<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Site;
use Atrium\Catalog\Models\Product;
use Atrium\Seo\Models\Issue;
use Atrium\Seo\Tests\TestCase;

/**
 * atrium:seo:check: one site, every site, or a site that is not there.
 */
final class CheckCommandTest extends TestCase
{
    public function test_a_site_that_is_not_the_first_can_be_checked_on_its_own(): void
    {
        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'detail_text' => '<img src="/a.png">']);
        $second = Site::query()->create(['code' => 'second', 'name' => 'Second', 'folder' => 'site2']);

        $this->artisan('atrium:seo:check', ['--site' => $second->id])->expectsOutput('Second: 1')->doesntExpectOutput('Demo shop: 1')->assertSuccessful();

        $this->assertSame([$second->id], Issue::query()->withoutGlobalScopes()->pluck('site_id')->unique()->values()->all());
    }

    public function test_a_failure_is_reported_when_no_site_is_checked(): void
    {
        $this->artisan('atrium:seo:check', ['--site' => 'nope'])->expectsOutput('No such site.')->assertFailed();
        $this->artisan('atrium:seo:check')->assertSuccessful();
    }
}
