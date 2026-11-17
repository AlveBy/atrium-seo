<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Support\Sites;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Schema\Nodes;
use Atrium\Seo\SeoPage;
use Atrium\Seo\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The small pieces every schema provider builds its nodes from.
 */
final class SchemaNodesTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    private function record(array $attributes): Model
    {
        return (new class extends Model {})->forceFill($attributes);
    }

    public function test_a_text_is_the_page_language_s_and_falls_back_to_the_default_language_only_when_it_is_missing(): void
    {
        $news = News::query()->create(['name' => ['en' => 'We moved', 'de' => 'Umgezogen'], 'code' => ['en' => 'we-moved', 'de' => 'umgezogen'], 'preview_text' => ['en' => 'Only English']]);

        $this->assertSame('Umgezogen', Nodes::text($news, 'name', 'de'));
        $this->assertSame('We moved', Nodes::text($news, 'name', 'en'));
        $this->assertSame('We moved', Nodes::text($news, 'name', 'fr'));
        $this->assertSame('Only English', Nodes::text($news, 'preview_text', 'de'));
        $this->assertSame('', Nodes::text($news, 'detail_text', 'de'));

        config(['app.fallback_locale' => 'de']);
        $this->assertSame('Umgezogen', Nodes::text($news, 'name', 'fr'));
        $this->assertSame('', Nodes::text($news, 'preview_text', 'fr'));
    }

    public function test_a_text_that_is_not_translated_is_the_same_in_every_language(): void
    {
        $product = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);

        $this->assertSame('Lamp', Nodes::text($product, 'name', 'de'));
        $this->assertSame('', Nodes::text($product, 'preview_text', 'de'));
    }

    public function test_a_text_is_plain_and_trimmed(): void
    {
        $record = $this->record(['name' => '<b> Lamp </b>', 'preview_text' => '<p>A <i>nice</i>   lamp.</p>']);

        $this->assertSame('Lamp', Nodes::text($record, 'name', 'en'));
        $this->assertSame('A nice lamp.', Nodes::text($record, 'preview_text', 'en', 300));
        $this->assertSame('A nice lamp.', Nodes::text($record, 'preview_text', 'en', 12));
        $this->assertSame('A…', Nodes::text($record, 'preview_text', 'en', 8));
        $this->assertSame('A nice   lamp.', Nodes::text($record, 'preview_text', 'en'));
    }

    public function test_a_name_is_the_name_or_else_the_title(): void
    {
        $this->assertSame('Name', Nodes::name($this->record(['name' => 'Name', 'title' => 'Title']), 'en'));
        $this->assertSame('Title', Nodes::name($this->record(['name' => '', 'title' => 'Title']), 'en'));
        $this->assertSame('Title', Nodes::name($this->record(['title' => 'Title']), 'en'));
        $this->assertSame('', Nodes::name($this->record([]), 'en'));
    }

    public function test_a_description_is_the_first_text_the_record_has(): void
    {
        $this->assertSame('Preview', Nodes::description($this->record(['preview_text' => 'Preview', 'detail_text' => 'Detail', 'description' => 'Description', 'text' => 'Text']), 'en'));
        $this->assertSame('Detail', Nodes::description($this->record(['preview_text' => '', 'detail_text' => 'Detail', 'description' => 'Description', 'text' => 'Text']), 'en'));
        $this->assertSame('Description', Nodes::description($this->record(['detail_text' => '  ', 'description' => 'Description', 'text' => 'Text']), 'en'));
        $this->assertSame('Text', Nodes::description($this->record(['text' => 'Text']), 'en'));
        $this->assertSame('', Nodes::description($this->record(['name' => 'No text']), 'en'));
    }

    public function test_a_description_of_exactly_three_hundred_characters_is_kept_whole_and_one_more_is_cut(): void
    {
        $exact = str_repeat('abcd ', 59).'abcde';
        $over = str_repeat('abcd ', 59).'abcdef';

        $this->assertSame(300, mb_strlen($exact));
        $this->assertSame(301, mb_strlen($over));
        $this->assertSame($exact, Nodes::description($this->record(['preview_text' => $exact]), 'en'));
        $this->assertSame(str_repeat('abcd ', 58).'abcd…', Nodes::description($this->record(['preview_text' => $over]), 'en'));
    }

    public function test_the_pictures_of_a_record_are_absolute_addresses_each_once_in_order(): void
    {
        $record = $this->record([
            'detail_picture' => 'a.jpg',
            'preview_picture' => 'a.jpg',
            'image' => '/upload/b.jpg',
            'photo' => null,
            'cover' => '',
            'photos' => [
                ['path' => 'p.jpg', 'url' => 'https://cdn.example.com/u.jpg'],
                ['url' => 'https://cdn.example.com/only-url.jpg'],
                ['other' => 'no address'],
                'a.jpg',
            ],
            'gallery' => ['g.jpg'],
        ]);

        $this->assertSame([
            $this->root().'/storage/a.jpg',
            $this->root().'/upload/b.jpg',
            $this->root().'/storage/p.jpg',
            'https://cdn.example.com/only-url.jpg',
            $this->root().'/storage/g.jpg',
        ], Nodes::images($record));
        $this->assertSame([], Nodes::images($this->record(['name' => 'No pictures'])));
    }

    public function test_a_date_is_iso_8601_and_anything_else_is_none(): void
    {
        $this->assertSame('2026-03-01T10:00:00+00:00', Nodes::date(new DateTimeImmutable('2026-03-01 10:00:00')));
        $this->assertNull(Nodes::date('2026-03-01'));
        $this->assertNull(Nodes::date(null));
    }

    public function test_the_organization_name_is_the_one_in_the_settings_trimmed_else_the_site_s_else_the_application_s(): void
    {
        $page = new SeoPage;

        $this->assertSame('Demo shop', Nodes::organizationName($page));

        Setting::save('seo', ['organization_name' => '  Demo Ltd  ']);
        $this->assertSame('Demo Ltd', Nodes::organizationName($page));

        Setting::save('seo', ['organization_name' => '   ']);
        $this->assertSame('Demo shop', Nodes::organizationName($page));

        $site = app(Sites::class)->current();
        $site->name = 'Main site';
        $site->save();
        $this->assertSame('Main site', Nodes::organizationName($page));

        Setting::save('seo', ['organization_name' => 5]);
        $this->assertSame('Main site', Nodes::organizationName($page));

        $site->name = '';
        $site->save();
        config(['app.name' => null]);
        $this->assertSame('', Nodes::organizationName($page));
    }

    public function test_the_publisher_is_the_organization_with_its_logo_when_there_is_one(): void
    {
        $page = new SeoPage;

        $this->assertSame([
            '@type' => 'Organization',
            '@id' => $this->root().'/#organization',
            'name' => 'Demo shop',
            'url' => $this->root().'/',
        ], Nodes::publisher($page));

        Setting::save('seo', ['logo' => 'brand/logo.png', 'organization_name' => 'Demo Ltd']);

        $this->assertSame([
            '@type' => 'Organization',
            '@id' => $this->root().'/#organization',
            'name' => 'Demo Ltd',
            'url' => $this->root().'/',
            'logo' => ['@type' => 'ImageObject', 'url' => $this->root().'/storage/brand/logo.png'],
        ], Nodes::publisher($page));
    }

    public function test_empty_values_are_dropped_and_zero_is_not_empty(): void
    {
        $this->assertSame(
            ['kept' => 'x', 'zero' => 0, 'no' => false, 'text' => '0'],
            Nodes::filled(['null' => null, 'empty' => '', 'list' => [], 'kept' => 'x', 'zero' => 0, 'no' => false, 'text' => '0']),
        );
    }
}
