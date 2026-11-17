<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests;

use Atrium\Admin\AtriumServiceProvider;
use Atrium\Admin\Models\AdminUser;
use Atrium\Admin\Support\PublicUrl;
use Atrium\Catalog\CatalogServiceProvider;
use Atrium\Faq\FaqServiceProvider;
use Atrium\News\NewsServiceProvider;
use Atrium\Reviews\ReviewsServiceProvider;
use Atrium\Seo\SeoServiceProvider;
use Atrium\Stores\StoresServiceProvider;
use Atrium\Theme\ThemeServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Tighten\Ziggy\ZiggyServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * The modules a test installs; the SEO module itself is always one.
     *
     * @var list<string>
     */
    protected array $modules = [
        'alveby/atrium-catalog',
        'alveby/atrium-news',
        'alveby/atrium-faq',
        'alveby/atrium-stores',
        'alveby/atrium-reviews',
        SeoServiceProvider::PACKAGE,
    ];

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            ZiggyServiceProvider::class,
            AtriumServiceProvider::class,
            ThemeServiceProvider::class,
            CatalogServiceProvider::class,
            NewsServiceProvider::class,
            FaqServiceProvider::class,
            StoresServiceProvider::class,
            ReviewsServiceProvider::class,
            SeoServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('app.name', 'Demo shop');
        $target = env('DB_CONNECTION');
        $config->set('database.default', in_array($target, ['mysql', 'pgsql'], true) ? $target : 'testing');
        $config->set('atrium.demo', false);
        $config->set('atrium.modules', $this->modules);
        $config->set('atrium.auth.two_factor.enabled', false);
        $config->set('atrium.dashboard.timings.enabled', false);
        $config->set('atrium-reviews.models', ['Atrium\\Catalog\\Models\\Product' => 'Product']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        PublicUrl::forgetPaths();
    }

    /**
     * A test may switch to production. Rolling the module's migrations back
     * on MySQL and PostgreSQL would then ask for confirmation.
     */
    protected function tearDown(): void
    {
        $this->app?->detectEnvironment(static fn (): string => 'testing');

        parent::tearDown();
    }

    /**
     * On MySQL and PostgreSQL the whole suite shares one database, migrated
     * by whichever class runs first: the tables of every module the suite
     * uses come with each test, whatever modules that class turns on.
     */
    protected function defineDatabaseMigrations(): void
    {
        foreach ([CatalogServiceProvider::class, NewsServiceProvider::class, FaqServiceProvider::class, StoresServiceProvider::class, ReviewsServiceProvider::class] as $provider) {
            $this->loadMigrationsFrom(dirname((string) (new ReflectionClass($provider))->getFileName(), 2).'/database/migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * A test that drops or renames a table runs on SQLite alone: MySQL
     * commits the test's transaction on such a statement, and PostgreSQL
     * refuses every statement after the failed query the test is about.
     */
    protected function onlyOnSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Changes the tables inside the test transaction: SQLite alone keeps it.');
        }
    }

    protected function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $admin->forceFill(['is_superadmin' => true])->save();

        return $admin;
    }

    protected function root(): string
    {
        return rtrim(url('/'), '/');
    }

    /**
     * The page's JSON-LD graph, decoded: exactly one script is expected.
     *
     * @return list<array<string,mixed>>
     */
    protected function graph(string $html): array
    {
        $this->assertSame(1, preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches), 'One JSON-LD script expected');
        $data = json_decode($matches[1][0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('https://schema.org', $data['@context']);

        return $data['@graph'];
    }

    /**
     * The nodes of a graph of one type.
     *
     * @param  list<array<string,mixed>>  $graph
     * @return list<array<string,mixed>>
     */
    protected function nodes(array $graph, string $type): array
    {
        return array_values(array_filter($graph, static fn (array $node): bool => in_array($type, (array) $node['@type'], true)));
    }

    /**
     * hreflang => href of the page's alternate links.
     *
     * @return array<string,string>
     */
    protected function hreflangs(string $html): array
    {
        preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)">#', $html, $matches);

        return array_combine($matches[1], $matches[2]);
    }
}
