<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Brands\BrandsServiceProvider;
use Atrium\Brands\Models\Brand;
use Atrium\Catalog\Models\Product;
use Atrium\Seo\Schema\Validator;
use Atrium\Seo\SeoServiceProvider;
use Atrium\Seo\Tests\TestCase;
use ReflectionClass;

/**
 * With the Brands module the product's node names its brand.
 */
final class ProductBrandTest extends TestCase
{
    protected array $modules = ['alveby/atrium-catalog', 'alveby/atrium-brands', SeoServiceProvider::PACKAGE];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), BrandsServiceProvider::class];
    }

    /**
     * MySQL and PostgreSQL keep the tables of the first test of the run,
     * which has no brands.
     */
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        $this->loadMigrationsFrom(dirname((string) (new ReflectionClass(BrandsServiceProvider::class))->getFileName(), 2).'/database/migrations');
    }

    public function test_the_brand_of_a_product(): void
    {
        $brand = Brand::query()->create(['name' => 'Lumen', 'code' => 'lumen']);
        $nameless = Brand::query()->create(['name' => '', 'code' => 'nameless']);
        Product::query()->create(['name' => 'Lamp', 'code' => 'lamp', 'brand_id' => $brand->id]);
        Product::query()->create(['name' => 'Desk', 'code' => 'desk', 'brand_id' => $nameless->id]);
        Product::query()->create(['name' => 'Chair', 'code' => 'chair']);

        $this->assertSame(['@type' => 'Brand', 'name' => 'Lumen'], $this->product('/catalog/lamp')['brand']);
        $this->assertArrayNotHasKey('brand', $this->product('/catalog/desk'));
        $this->assertArrayNotHasKey('brand', $this->product('/catalog/chair'));
    }

    /**
     * @return array<string,mixed>
     */
    private function product(string $url): array
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', (string) $this->get($url)->assertOk()->getContent(), $match);
        $this->assertSame([], Validator::errors($match[1]));

        return collect(json_decode($match[1], true)['@graph'])->firstWhere('@type', 'Product');
    }
}
