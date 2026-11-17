<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Fields\TextField;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Modules\ModuleSettings;
use Atrium\Admin\Modules\Settings;
use Atrium\Admin\Support\Sites;
use Atrium\Catalog\Models\CatalogSection;
use Atrium\Catalog\Models\Product;
use Atrium\News\Models\News;
use Atrium\Seo\Meta\Variables;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Tests\TestCase;
use Atrium\Stores\Models\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * What each {variable} of a template comes to, for a record in a language.
 */
final class MetaVariablesTest extends TestCase
{
    private function variables(): Variables
    {
        return app(Variables::class);
    }

    /**
     * The shop module's settings, which are not installed with this package.
     */
    private function declareShopSettings(): void
    {
        $declaration = new class extends ModuleSettings
        {
            public function fields(): array
            {
                return [TextField::make('currency')];
            }
        };
        $declarations = new \ReflectionProperty(Settings::class, 'declarations');
        $declarations->setValue(app(Settings::class), ['shop' => $declaration]);
    }

    private function site(string $name = 'Lumen'): Site
    {
        return new Site(['name' => $name]);
    }

    public function test_the_site_variable_is_the_trimmed_name_of_the_site(): void
    {
        $this->assertSame('Boutique', $this->variables()->value('site', null, 'en', $this->site('  Boutique  ')));
        $this->assertSame('Boutique', $this->variables()->value('site', new Product, 'en', $this->site('Boutique')));
    }

    public function test_a_site_without_a_name_is_called_by_the_name_of_the_application(): void
    {
        $this->assertSame('Demo shop', $this->variables()->value('site', null, 'en', $this->site('')));
    }

    public function test_every_variable_but_the_site_is_empty_without_a_record(): void
    {
        foreach (['name', 'price', 'section', 'excerpt', 'date', 'address'] as $name) {
            $this->assertSame('', $this->variables()->value($name, null, 'en', $this->site()), $name);
        }
    }

    public function test_the_name_is_the_title_when_a_record_has_no_name(): void
    {
        $this->assertSame('Mouse', $this->variables()->value('name', new Product(['name' => 'Mouse']), 'en', $this->site()));
        $this->assertSame('Hello', $this->variables()->value('name', (new Product)->setAttribute('title', ' Hello '), 'en', $this->site()));
        $this->assertSame('', $this->variables()->value('name', new Product, 'en', $this->site()));
    }

    public function test_a_value_given_by_the_page_is_trimmed_and_needs_no_record(): void
    {
        $site = $this->site();

        $this->assertSame('Products | Lumen', $this->variables()->render('{name} | {site}', null, 'en', $site, ['name' => '  Products  ']));
        $this->assertSame('', $this->variables()->render('{name} | {site}', null, 'en', $site, ['name' => '   ']));
        $this->assertSame('Mouse | Lumen', $this->variables()->render('{name} | {site}', new Product(['name' => 'Mouse']), 'en', $site, ['other' => 'x']));
        $this->assertSame('Products | Mouse', $this->variables()->render('{name} | {title}', (new Product)->setAttribute('title', 'Mouse'), 'en', $site, ['name' => 'Products']));
    }

    public function test_a_template_that_is_not_text_is_empty(): void
    {
        $this->assertSame('', $this->variables()->render("caf\xe9 {name}", null, 'en', $this->site(), ['name' => 'x']));
        $this->assertSame('', $this->variables()->render("[caf\xe9] {site}", null, 'en', $this->site()));
    }

    public function test_a_part_in_brackets_is_dropped_whole_and_the_rest_is_tidied(): void
    {
        $site = $this->site();
        $mouse = new Product(['name' => 'Mouse']);

        $this->assertSame('Mouse | Lumen', $this->variables()->render('{name}[ for {price}] | {site}', $mouse, 'en', $site));
        $this->assertSame('Mouse for €5.00 | Lumen', $this->variables()->render('{name}[ for {price}] | {site}', (new Product(['name' => 'Mouse']))->setAttribute('price', 5), 'en', $site));
    }

    public function test_the_excerpt_is_cut_at_two_hundred_characters(): void
    {
        $exact = str_repeat('a', 200);
        $words = str_repeat('word ', 41);

        $this->assertSame($exact, $this->variables()->value('excerpt', new Product(['preview_text' => $exact]), 'en', $this->site()));

        $cut = $this->variables()->value('excerpt', new Product(['preview_text' => $words]), 'en', $this->site());
        $this->assertSame(str_repeat('word ', 39).'word…', $cut);
    }

    public function test_the_excerpt_takes_the_first_text_a_record_has(): void
    {
        $site = $this->site();

        $this->assertSame('Preview', $this->variables()->value('excerpt', (new Product)->forceFill(['preview_text' => 'Preview', 'detail_text' => 'Detail', 'description' => 'Description']), 'en', $site));
        $this->assertSame('Detail', $this->variables()->value('excerpt', (new Product)->forceFill(['detail_text' => 'Detail', 'description' => 'Description']), 'en', $site));
        $this->assertSame('Description', $this->variables()->value('excerpt', (new Product)->forceFill(['description' => 'Description']), 'en', $site));
        $this->assertSame('', $this->variables()->value('excerpt', new Product, 'en', $site));
    }

    public function test_any_attribute_is_cut_at_three_hundred_characters(): void
    {
        $site = $this->site();
        $exact = str_repeat('a', 300);

        $this->assertSame($exact, $this->variables()->value('address', new Store(['address' => $exact]), 'en', $site));
        $this->assertSame(str_repeat('word ', 59).'word…', $this->variables()->value('address', new Store(['address' => str_repeat('word ', 61)]), 'en', $site));
    }

    public function test_a_translated_attribute_is_read_in_the_language_and_cut_at_three_hundred_characters(): void
    {
        $site = $this->site();
        $exact = str_repeat('a', 300);
        $news = new News(['detail_text' => ['en' => $exact, 'de' => 'Deutsch']]);

        $this->assertSame($exact, $this->variables()->value('detail_text', $news, 'en', $site));
        $this->assertSame('Deutsch', $this->variables()->value('detail_text', $news, 'de', $site));
        $this->assertSame('', $this->variables()->value('detail_text', $news, 'fr', $site));
        $this->assertSame(str_repeat('word ', 59).'word…', $this->variables()->value('detail_text', new News(['detail_text' => ['en' => str_repeat('word ', 61)]]), 'en', $site));
    }

    public function test_a_flag_or_an_object_is_not_text(): void
    {
        $site = $this->site();
        $record = (new Store)->setAttribute('flag', true)->setAttribute('thing', new stdClass)->setAttribute('count', 5)->setAttribute('off', false);

        $this->assertSame('', $this->variables()->value('flag', $record, 'en', $site));
        $this->assertSame('', $this->variables()->value('off', $record, 'en', $site));
        $this->assertSame('', $this->variables()->value('thing', $record, 'en', $site));
        $this->assertSame('5', $this->variables()->value('count', $record, 'en', $site));
        $this->assertSame('', $this->variables()->value('missing', $record, 'en', $site));
    }

    public function test_the_first_text_of_a_list_stands_for_it(): void
    {
        $record = (new Store)->setAttribute('tags', [['x'], 'Red', 'Blue'])->setAttribute('none', [['x']]);

        $this->assertSame('Red', $this->variables()->value('tags', $record, 'en', $this->site()));
        $this->assertSame('', $this->variables()->value('none', $record, 'en', $this->site()));
    }

    /**
     * @return iterable<string,array{mixed,string}>
     */
    public static function prices(): iterable
    {
        yield 'a price' => [10, '€10.00'];
        yield 'a price in a string' => ['1250.5', '€1,250.50'];
        yield 'a cent' => [0.01, '€0.01'];
        yield 'free' => [0, ''];
        yield 'free in a string' => ['0.00', ''];
        yield 'negative' => [-5, ''];
        yield 'not a number' => ['abc', ''];
        yield 'a list' => [['10'], ''];
        yield 'nothing' => [null, ''];
    }

    #[DataProvider('prices')]
    public function test_a_price_is_worked_out_only_for_a_positive_number(mixed $price, string $expected): void
    {
        $record = (new Store)->setAttribute('price', $price);

        $this->assertSame($expected, $this->variables()->value('price', $record, 'en', $this->site()));
    }

    public function test_a_price_is_written_in_the_currency_of_the_site(): void
    {
        $record = (new Store)->setAttribute('price', 10);
        Setting::save('seo', ['currency' => 'USD']);

        $this->assertSame('$10.00', $this->variables()->value('price', $record, 'en', app(Sites::class)->current()));
    }

    public function test_the_currency_is_the_seo_one_then_the_shops_then_euro(): void
    {
        $this->declareShopSettings();
        $site = app(Sites::class)->current();

        $this->assertSame('EUR', Variables::currency($site));

        Setting::save('shop', ['currency' => 'PLN']);
        $this->assertSame('PLN', Variables::currency($site));

        Setting::save('seo', ['currency' => 'USD']);
        $this->assertSame('USD', Variables::currency($site));
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidCurrencies(): iterable
    {
        yield 'a fourth letter' => ['USDX'];
        yield 'a letter before' => ['xUSD'];
        yield 'lower case' => ['usd'];
        yield 'two letters' => ['US'];
        yield 'a space' => [' USD'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidCurrencies')]
    public function test_a_currency_that_is_not_three_capital_letters_is_passed_over(string $currency): void
    {
        $this->declareShopSettings();
        $site = app(Sites::class)->current();
        Setting::save('seo', ['currency' => $currency]);
        Setting::save('shop', ['currency' => 'PLN']);

        $this->assertSame('PLN', Variables::currency($site));

        Setting::save('shop', ['currency' => $currency]);
        $this->assertSame('EUR', Variables::currency($site));
    }

    public function test_the_section_is_the_name_of_the_section_a_record_is_in(): void
    {
        $laptops = CatalogSection::query()->create(['name' => 'Laptops', 'code' => 'laptops']);
        $site = $this->site();

        $this->assertSame('Laptops', $this->variables()->value('section', new Product(['section_id' => $laptops->id]), 'en', $site));
        $this->assertSame('', $this->variables()->value('section', new Product, 'en', $site));
        $this->assertSame('', $this->variables()->value('section', new Product(['section_id' => $laptops->id + 100]), 'en', $site));
    }

    public function test_a_record_without_a_public_page_has_no_section(): void
    {
        $this->assertSame('', $this->variables()->value('section', new Template(['model' => '*']), 'en', $this->site()));
    }

    public function test_the_date_is_the_publication_then_the_activity_then_the_creation(): void
    {
        $site = $this->site();

        $this->assertSame('March 1, 2026', $this->variables()->value('date', (new News)->setAttribute('active_from', '2026-03-01 10:00:00')->setAttribute('created_at', '2020-01-01 00:00:00'), 'en', $site));
        $this->assertSame('', $this->variables()->value('date', new News, 'en', $site));
    }

    public function test_a_date_is_written_in_the_language_of_the_page(): void
    {
        $news = new News;
        $news->active_from = '2026-03-01 10:00:00';

        $this->assertSame('1. März 2026', $this->variables()->value('date', $news, 'de', $this->site()));
    }
}
