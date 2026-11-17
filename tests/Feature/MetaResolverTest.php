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
use Atrium\Seo\Meta\Meta;
use Atrium\Seo\Meta\MetaResolver;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Robots;
use Atrium\Seo\Tests\Fixtures\LocalizedProduct;
use Atrium\Seo\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The meta of a record and of an address, worked out on its own: the page
 * around it (the theme) has meta of its own that would hide a mistake here.
 */
final class MetaResolverTest extends TestCase
{
    private function resolver(): MetaResolver
    {
        return app(MetaResolver::class);
    }

    private function mouse(): Product
    {
        return Product::query()->create(['name' => 'Mouse', 'code' => 'mouse']);
    }

    public function test_the_seo_tab_of_a_record_is_its_meta_in_every_part(): void
    {
        $mouse = $this->mouse();
        $mouse->seoMeta()->create([
            'title' => 'The best {name}',
            'description' => 'Hand made',
            'keywords' => '  mouse, wireless  ',
            'og_image' => 'seo/mouse.jpg',
            'robots' => Robots::NOINDEX,
            'canonical_url' => 'https://example.com/mouse',
        ]);
        $mouse->forceFill(['detail_picture' => 'detail.jpg', 'preview_picture' => 'preview.jpg'])->save();

        $meta = $this->resolver()->forRecord($mouse->fresh(), 'en', app(Sites::class)->current());

        $this->assertSame('The best Mouse', $meta->title);
        $this->assertSame('Hand made', $meta->description);
        $this->assertSame('mouse, wireless', $meta->keywords);
        $this->assertSame($this->root().'/storage/seo/mouse.jpg', $meta->image);
        $this->assertSame(Robots::NOINDEX, $meta->robots);
        $this->assertSame('https://example.com/mouse', $meta->canonical);
    }

    public function test_a_record_without_a_seo_tab_takes_its_template_and_its_own_picture(): void
    {
        $mouse = $this->mouse();
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{name} for sale'], 'description' => ['en' => 'About {name}'], 'keywords' => ['en' => 'buy {name}']]);
        $mouse->forceFill(['detail_picture' => 'detail.jpg', 'preview_picture' => 'preview.jpg'])->save();

        $meta = $this->resolver()->forRecord($mouse->fresh(), 'en', app(Sites::class)->current());

        $this->assertSame('Mouse for sale', $meta->title);
        $this->assertSame('About Mouse', $meta->description);
        $this->assertSame('buy Mouse', $meta->keywords);
        $this->assertSame($this->root().'/storage/detail.jpg', $meta->image);
        $this->assertNull($meta->robots);
        $this->assertNull($meta->canonical);
    }

    public function test_a_record_falls_back_from_the_detail_picture_to_the_preview_one_and_to_the_site(): void
    {
        $site = app(Sites::class)->current();
        $mouse = $this->mouse();

        $this->assertNull($this->resolver()->forRecord($mouse, 'en', $site)->image);

        Setting::save('seo', ['og_image' => 'brand/share.png']);
        $this->assertSame($this->root().'/storage/brand/share.png', $this->resolver()->forRecord($mouse, 'en', $site)->image);

        $mouse->forceFill(['preview_picture' => 'preview.jpg']);
        $this->assertSame($this->root().'/storage/preview.jpg', $this->resolver()->forRecord($mouse, 'en', $site)->image);

        $mouse->forceFill(['detail_picture' => 'detail.jpg']);
        $this->assertSame($this->root().'/storage/detail.jpg', $this->resolver()->forRecord($mouse, 'en', $site)->image);
    }

    public function test_the_picture_of_the_seo_tab_beats_the_pictures_of_the_record_and_the_site(): void
    {
        $site = app(Sites::class)->current();
        $mouse = $this->mouse();
        $mouse->seoMeta()->create(['og_image' => 'seo/mouse.jpg']);
        $mouse->forceFill(['detail_picture' => 'detail.jpg']);
        Setting::save('seo', ['og_image' => 'brand/share.png']);

        $this->assertSame($this->root().'/storage/seo/mouse.jpg', $this->resolver()->forRecord($mouse->fresh(), 'en', $site)->image);
    }

    public function test_an_empty_seo_tab_says_nothing_but_the_name_and_the_text(): void
    {
        $site = app(Sites::class)->current();
        $mouse = $this->mouse();
        $mouse->seoMeta()->create(['title' => '', 'description' => '', 'keywords' => '', 'robots' => '', 'canonical_url' => '']);
        $mouse->forceFill(['preview_text' => 'Light.'])->save();

        $meta = $this->resolver()->forRecord($mouse->fresh(), 'en', $site);

        $this->assertSame('Mouse | Demo shop', $meta->title);
        $this->assertSame('Buy Mouse. Light.', $meta->description);
        $this->assertSame('', $meta->keywords);
        $this->assertNull($meta->robots);
        $this->assertNull($meta->canonical);
    }

    public function test_the_name_and_the_excerpt_stand_in_when_even_the_template_is_empty(): void
    {
        $site = app(Sites::class)->current();
        $mouse = $this->mouse();
        $mouse->forceFill(['preview_text' => 'Light and <b>fast</b>.'])->save();
        // A template that comes to nothing for this record: a variable it lacks.
        Template::query()->create(['model' => Product::class, 'title' => ['en' => '{missing}'], 'description' => ['en' => '{missing}']]);

        $meta = $this->resolver()->forRecord($mouse->fresh(), 'en', $site);

        $this->assertSame('Mouse', $meta->title);
        $this->assertSame('Light and fast.', $meta->description);
    }

    public function test_a_record_without_a_seo_tab_relation_has_only_its_templates(): void
    {
        $site = app(Sites::class)->current();

        $meta = $this->resolver()->forRecord(new Template(['model' => '*']), 'en', $site);

        $this->assertSame('', $meta->title);
        $this->assertSame('', $meta->description);
        $this->assertSame('', $meta->keywords);
        $this->assertNull($meta->robots);
    }

    public function test_the_seo_tab_has_one_text_which_is_the_default_languages_unless_the_record_is_written_in_one_language(): void
    {
        $site = app(Sites::class)->current();
        $localized = new LocalizedProduct;
        $localized->forceFill(['name' => 'Mouse', 'code' => 'mouse'])->save();
        $localized->seoMeta()->create(['title' => 'Own {name}', 'description' => 'Own text', 'keywords' => ' own, words ']);
        $localized = LocalizedProduct::query()->findOrFail($localized->id);

        // The default is the site's default language: the tab's text.
        $meta = $this->resolver()->forRecord($localized, 'en', $site);
        $this->assertSame(['Own Mouse', 'Own text', 'own, words'], [$meta->title, $meta->description, $meta->keywords]);

        $meta = $this->resolver()->forRecord($localized, 'en', $site, true);
        $this->assertSame(['Own Mouse', 'Own text', 'own, words'], [$meta->title, $meta->description, $meta->keywords]);

        // Another language of a translated record: the template (a subclass is of the "default" kind), in that language.
        $meta = $this->resolver()->forRecord($localized, 'de', $site, false);
        $this->assertSame(['Maus | Demo shop', '', ''], [$meta->title, $meta->description, $meta->keywords]);
    }

    public function test_a_record_written_in_one_language_has_its_seo_tab_in_every_language(): void
    {
        $site = app(Sites::class)->current();
        $mouse = $this->mouse();
        $mouse->seoMeta()->create(['title' => 'Own {name}', 'description' => 'Own text', 'keywords' => 'own']);
        $mouse = $mouse->fresh();

        $meta = $this->resolver()->forRecord($mouse, 'de', $site, false);

        $this->assertSame(['Own Mouse', 'Own text', 'own'], [$meta->title, $meta->description, $meta->keywords]);
    }

    /**
     * @return iterable<string,array{?string,?string}>
     */
    public static function addresses(): iterable
    {
        yield 'nothing' => [null, null];
        yield 'empty' => ['', null];
        yield 'a path' => ['/pictures/a.jpg', 'http://localhost/pictures/a.jpg'];
        yield 'a relative path' => ['pictures/a.jpg', 'http://localhost/pictures/a.jpg'];
        yield 'a secure address' => ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/a.jpg'];
        yield 'an address in capitals' => ['HTTPS://CDN.EXAMPLE.COM/A.JPG', 'HTTPS://CDN.EXAMPLE.COM/A.JPG'];
        yield 'an address' => ['http://cdn.example.com/a.jpg', 'http://cdn.example.com/a.jpg'];
        yield 'a protocol-relative address' => ['//cdn.example.com/a.jpg', '//cdn.example.com/a.jpg'];
        yield 'a path with double slashes inside' => ['a//b.jpg', 'http://localhost/a//b.jpg'];
        yield 'an address inside a path' => ['/go/https://cdn.example.com/a.jpg', 'http://localhost/go/https://cdn.example.com/a.jpg'];
    }

    #[DataProvider('addresses')]
    public function test_an_address_is_made_absolute(?string $given, ?string $expected): void
    {
        $this->assertSame($expected, MetaResolver::absolute($given));
    }

    public function test_the_picture_of_the_site_is_the_one_in_its_settings(): void
    {
        $site = app(Sites::class)->current();

        $this->assertNull(MetaResolver::defaultImage($site));

        Setting::save('seo', ['og_image' => 'brand/share.png']);
        $this->assertSame($this->root().'/storage/brand/share.png', MetaResolver::defaultImage($site));

        Setting::save('seo', ['og_image' => ['not' => 'a path']]);
        $this->assertNull(MetaResolver::defaultImage($site));
    }

    public function test_the_meta_of_an_address_is_none_when_nobody_wrote_it(): void
    {
        $this->assertNull($this->resolver()->forPath('/catalog', 'en', app(Sites::class)->current()));
    }

    public function test_the_meta_of_an_address_in_every_part(): void
    {
        PageMeta::query()->create([
            'path' => '/about',
            'title' => ['en' => 'About | {site}', 'de' => 'Über uns'],
            'description' => ['en' => ' About us '],
            'keywords' => ['en' => 'about, us'],
            'og_image' => 'pages/about.jpg',
            'robots' => Robots::NONE,
            'canonical_url' => 'https://example.com/about',
        ]);
        $site = app(Sites::class)->current();

        $meta = $this->resolver()->forPath('/about/', 'en', $site);

        $this->assertInstanceOf(Meta::class, $meta);
        $this->assertSame('About | Demo shop', $meta->title);
        $this->assertSame('About us', $meta->description);
        $this->assertSame('about, us', $meta->keywords);
        $this->assertSame($this->root().'/storage/pages/about.jpg', $meta->image);
        $this->assertSame(Robots::NONE, $meta->robots);
        $this->assertSame('https://example.com/about', $meta->canonical);

        $german = $this->resolver()->forPath('/about', 'de', $site);
        $this->assertSame('Über uns', $german?->title);
        $this->assertSame('', $german?->description);
    }

    public function test_an_address_without_robots_or_canonical_leaves_them_alone(): void
    {
        PageMeta::query()->create(['path' => '/about', 'title' => ['en' => 'About'], 'robots' => '', 'canonical_url' => '']);

        $meta = $this->resolver()->forPath('/about', 'en', app(Sites::class)->current());

        $this->assertNull($meta?->robots);
        $this->assertNull($meta?->canonical);
        $this->assertNull($meta?->image);
    }

    public function test_when_the_pages_table_is_missing_no_address_has_meta(): void
    {
        PageMeta::query()->create(['path' => '/about', 'title' => ['en' => 'About']]);
        $site = app(Sites::class)->current();
        PageMeta::addGlobalScope('missing', static function (): never {
            throw new QueryException('testing', 'select * from "seo_pages"', [], new \Exception('no such table: seo_pages'));
        });

        try {
            $meta = $this->resolver()->forPath('/about', 'en', $site);
        } finally {
            Model::clearBootedModels();
        }

        $this->assertNull($meta);
    }

    public function test_one_meta_fills_the_gaps_of_another(): void
    {
        $first = new Meta('First', 'First text', 'first', 'https://a/1.jpg', Robots::NOINDEX, 'https://a/1');
        $second = new Meta('Second', 'Second text', 'second', 'https://b/2.jpg', Robots::NONE, 'https://b/2');

        foreach ([$first->over($second), $first->over(new Meta)] as $kept) {
            $this->assertSame(['First', 'First text', 'first', 'https://a/1.jpg', Robots::NOINDEX, 'https://a/1'], $this->parts($kept));
        }

        $this->assertSame(['Second', 'Second text', 'second', 'https://b/2.jpg', Robots::NONE, 'https://b/2'], $this->parts((new Meta)->over($second)));
        $this->assertSame([null, null, null, null, null, null], array_map(static fn ($part) => $part === '' ? null : $part, $this->parts((new Meta)->over(new Meta))));
    }

    public function test_every_part_of_a_meta_is_filled_on_its_own(): void
    {
        $second = new Meta('Second', 'Second text', 'second', 'https://b/2.jpg', Robots::NONE, 'https://b/2');

        $this->assertSame(['Own', 'Second text', 'second', 'https://b/2.jpg', Robots::NONE, 'https://b/2'], $this->parts((new Meta(title: 'Own'))->over($second)));
        $this->assertSame(['Second', 'Own', 'second', 'https://b/2.jpg', Robots::NONE, 'https://b/2'], $this->parts((new Meta(description: 'Own'))->over($second)));
        $this->assertSame(['Second', 'Second text', 'Own', 'https://b/2.jpg', Robots::NONE, 'https://b/2'], $this->parts((new Meta(keywords: 'Own'))->over($second)));
        $this->assertSame(['Second', 'Second text', 'second', 'own.jpg', Robots::NONE, 'https://b/2'], $this->parts((new Meta(image: 'own.jpg'))->over($second)));
        $this->assertSame(['Second', 'Second text', 'second', 'https://b/2.jpg', Robots::INDEX, 'https://b/2'], $this->parts((new Meta(robots: Robots::INDEX))->over($second)));
        $this->assertSame(['Second', 'Second text', 'second', 'https://b/2.jpg', Robots::NONE, 'own'], $this->parts((new Meta(canonical: 'own'))->over($second)));
    }

    /**
     * @return list<string|null>
     */
    private function parts(Meta $meta): array
    {
        return [$meta->title, $meta->description, $meta->keywords, $meta->image, $meta->robots, $meta->canonical];
    }
}
