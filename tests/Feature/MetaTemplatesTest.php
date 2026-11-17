<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\Sites;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Meta\Templates;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Where the template of a title, description or keywords comes from, from
 * the panel down to the shipped default.
 */
final class MetaTemplatesTest extends TestCase
{
    private function templates(): Templates
    {
        return app(Templates::class);
    }

    private function site(): Site
    {
        return app(Sites::class)->current();
    }

    public function test_the_template_of_a_module_is_trimmed_and_beats_the_one_for_all_modules(): void
    {
        Template::query()->create(['model' => Templates::ANY, 'title' => ['en' => ' Any: {name} ']]);

        $this->assertSame('Any: {name}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));

        Template::query()->create(['model' => Product::class, 'title' => ['en' => "\t{name} at {price}\n"]]);

        $this->assertSame('{name} at {price}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));
        $this->assertSame('Any: {name}', $this->templates()->for(News::class, 'title', 'en', $this->site()));
    }

    public function test_a_template_of_spaces_is_no_template(): void
    {
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '   '], 'description' => ['en' => "\n"]]);
        Template::query()->create(['model' => Templates::ANY, 'title' => ['en' => ' ']]);

        $this->assertSame('{name}[ — {price}] | {site}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));
        $this->assertSame($this->templates()->default('product', 'description', 'en'), $this->templates()->for(Product::class, 'description', 'en', $this->site()));
    }

    public function test_a_template_in_the_config_is_used_as_written_unless_it_is_blank(): void
    {
        config(['atrium.seo.templates.'.Product::class.'.title' => ' Shop: {name} ']);

        $this->assertSame(' Shop: {name} ', $this->templates()->for(Product::class, 'title', 'en', $this->site()));

        config(['atrium.seo.templates.'.Product::class.'.title' => '   ']);

        $this->assertSame('{name}[ — {price}] | {site}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));

        config(['atrium.seo.templates.'.Product::class.'.title' => ['not' => 'text']]);

        $this->assertSame('{name}[ — {price}] | {site}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));
    }

    public function test_a_kind_is_the_configured_one_or_default(): void
    {
        $this->assertSame('product', $this->templates()->kind(Product::class));
        $this->assertSame('article', $this->templates()->kind(News::class));
        $this->assertSame('default', $this->templates()->kind('App\\Models\\Unknown'));

        config(['atrium-seo.kinds.App\\Models\\Blank' => '']);
        $this->assertSame('default', $this->templates()->kind('App\\Models\\Blank'));

        config(['atrium-seo.kinds.App\\Models\\Odd' => ['product']]);
        $this->assertSame('default', $this->templates()->kind('App\\Models\\Odd'));
    }

    public function test_the_default_of_a_kind_falls_back_to_the_default_kind_then_to_nothing(): void
    {
        $this->assertSame('{name}[ — {price}] | {site}', $this->templates()->default('product', 'title', 'en'));
        // "home" has a title only.
        $this->assertSame('{excerpt}', $this->templates()->default('home', 'description', 'en'));
        $this->assertSame('{excerpt}', $this->templates()->default('default', 'description', 'en'));
        $this->assertSame('', $this->templates()->default('default', 'keywords', 'en'));
        $this->assertSame('', $this->templates()->default('product', 'keywords', 'en'));
        $this->assertSame('{name}[ für {price}] kaufen.[ {excerpt}]', $this->templates()->default('product', 'description', 'de'));
    }

    public function test_a_site_reads_its_templates_once(): void
    {
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{name}']]);
        $this->templates()->flush();
        DB::enableQueryLog();

        $this->templates()->for(Product::class, 'title', 'en', $this->site());
        $this->templates()->for(Product::class, 'description', 'en', $this->site());
        $this->templates()->for(News::class, 'title', 'en', $this->site());

        $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains((string) $query['query'], 'seo_templates'));
        $this->assertCount(1, $reads);
    }

    public function test_saving_or_deleting_a_template_is_seen_at_once(): void
    {
        $this->assertSame('{name}[ — {price}] | {site}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));

        $template = Template::query()->create(['model' => Product::class, 'title' => ['en' => 'First: {name}']]);
        $this->assertSame('First: {name}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));

        $template->update(['title' => ['en' => 'Second: {name}']]);
        $this->assertSame('Second: {name}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));

        $template->delete();
        $this->assertSame('{name}[ — {price}] | {site}', $this->templates()->for(Product::class, 'title', 'en', $this->site()));
    }

    public function test_when_the_templates_table_is_missing_the_defaults_stand(): void
    {
        $site = $this->site();
        Template::query()->create(['model' => Product::class, 'title' => ['en' => 'Panel: {name}']]);
        $this->templates()->flush();
        Template::addGlobalScope('missing', static function (): never {
            throw new QueryException('testing', 'select * from "seo_templates"', [], new \Exception('no such table: seo_templates'));
        });

        try {
            $title = $this->templates()->for(Product::class, 'title', 'en', $site);
        } finally {
            Model::clearBootedModels();
        }

        $this->assertSame('{name}[ — {price}] | {site}', $title);
    }

    public function test_the_modules_to_choose_from_are_all_but_the_panels_and_this_ones(): void
    {
        $options = Template::moduleOptions();

        $this->assertSame(__('atrium-seo::seo.templates.any'), $options[Templates::ANY]);
        $this->assertSame(Product::getAdminLabel(), $options[Product::class]);
        $this->assertSame(News::getAdminLabel(), $options[News::class]);
        $this->assertSame([], array_filter(array_keys($options), static fn (string $key): bool => str_starts_with($key, 'Atrium\\Seo\\') || str_starts_with($key, 'Atrium\\Admin\\')));
    }

    public function test_a_template_is_listed_under_the_name_of_its_module(): void
    {
        $this->assertSame(Product::getAdminLabel(), (new Template(['model' => Product::class]))->getModuleAttribute());
        $this->assertSame(__('atrium-seo::seo.templates.any'), (new Template(['model' => Templates::ANY]))->module);
        $this->assertSame('App\\Gone', (new Template(['model' => 'App\\Gone']))->getModuleAttribute());
        $this->assertSame('', (new Template)->getModuleAttribute());
        $this->assertSame(Product::getAdminLabel(), Template::query()->create(['model' => Product::class])->toArray()['module']);
    }
}
