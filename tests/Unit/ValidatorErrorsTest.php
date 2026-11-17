<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Unit;

use Atrium\Seo\Schema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * The exact lines the schema.org check reports, one case at a time: what
 * is wrong, where, and nothing else.
 */
final class ValidatorErrorsTest extends TestCase
{
    private const CONTEXT = 'https://schema.org';

    /**
     * @param  array<string,mixed>  $properties
     * @return list<string>
     */
    private function errorsOf(string|array $type, array $properties): array
    {
        return Validator::errors(['@context' => self::CONTEXT, '@type' => $type, ...$properties]);
    }

    public function test_text_that_is_not_json_is_reported_with_the_parser_message(): void
    {
        $this->assertSame(['not JSON: Syntax error'], Validator::errors('{'));
    }

    public function test_json_that_is_not_an_object_is_reported(): void
    {
        $this->assertSame(['not a JSON object'], Validator::errors('"text"'));
        $this->assertSame(['not a JSON object'], Validator::errors('12'));
    }

    public function test_a_wrong_context_and_a_graph_that_is_not_a_list_are_both_reported(): void
    {
        $this->assertSame(
            ['@context: not schema.org', '@graph: not a list of nodes'],
            Validator::errors(['@context' => 'https://example.com', '@graph' => ['node' => ['@type' => 'Thing']]]),
        );
        $this->assertSame(['@graph: not a list of nodes'], Validator::errors(['@context' => self::CONTEXT, '@graph' => 'text']));
        $this->assertSame(['@graph: not a list of nodes'], Validator::errors(['@context' => self::CONTEXT, '@graph' => []]));
    }

    public function test_the_context_may_be_any_of_the_four_schema_org_spellings(): void
    {
        foreach (['https://schema.org', 'http://schema.org', 'https://schema.org/', 'http://schema.org/'] as $context) {
            $this->assertSame([], Validator::errors(['@context' => $context, '@type' => 'Thing', 'name' => 'x']), $context);
        }

        $this->assertSame(['@context: not schema.org'], Validator::errors(['@type' => 'Thing', 'name' => 'x']));
    }

    public function test_every_node_of_a_graph_is_reported_under_its_index(): void
    {
        $this->assertSame(
            ['@graph[0]: no @type', '@graph[1]: not a node', '@graph[2]: unknown type "Nope"'],
            Validator::errors(['@context' => self::CONTEXT, '@graph' => [['name' => 'x'], 'text', ['@type' => 'Nope']]]),
        );
    }

    public function test_every_error_of_a_single_node_is_reported_in_order(): void
    {
        $this->assertSame(
            ['"name" is required of Product', 'first: Product has no such property', 'second: Product has no such property'],
            $this->errorsOf('Product', ['first' => 1, 'second' => 2]),
        );
    }

    public function test_a_node_without_a_type_is_reported_and_its_values_are_left_alone(): void
    {
        $this->assertSame(['no @type'], Validator::errors(['@context' => self::CONTEXT, 'name' => '']));
        $this->assertSame(['offers: no @type'], Validator::errors(['@context' => self::CONTEXT, '@type' => 'Product', 'name' => 'x', 'offers' => ['url' => '']]));
        $this->assertSame([], Validator::errors(['@context' => self::CONTEXT, '@id' => 'https://example.com/#a']));
    }

    public function test_an_unknown_type_does_not_hide_the_problems_of_the_others(): void
    {
        $this->assertSame(
            ['unknown type "Nope"', '"startDate" is required of Nope, Event'],
            $this->errorsOf(['Nope', 'Event'], ['name' => 'Party', 'location' => 'Hall']),
        );
        $this->assertSame(['unknown type 5'], $this->errorsOf([5], ['name' => 'x']));
    }

    public function test_a_node_of_two_types_has_the_properties_and_the_requirements_of_both_in_either_order(): void
    {
        $this->assertSame([], $this->errorsOf(['Car', 'Product'], ['name' => 'Hatchback', 'vehicleEngine' => 'x']));
        $this->assertSame([], $this->errorsOf(['Product', 'Car'], ['name' => 'Hatchback', 'vehicleEngine' => 'x']));
        $this->assertSame(
            ['"startDate" is required of Event, Product', '"location" is required of Event, Product'],
            $this->errorsOf(['Event', 'Product'], ['name' => 'x']),
        );
        $this->assertSame(['"name" is required of Product, Product'], $this->errorsOf(['Product', 'Product'], []));
    }

    public function test_a_property_with_a_number_for_a_name_is_not_a_failure(): void
    {
        $unknown = ['@context' => self::CONTEXT, '@type' => 'Nope'];
        $unknown[5] = 'x';
        $known = ['@context' => self::CONTEXT, '@type' => 'Product'];
        $known[5] = 'x';

        $this->assertSame(['unknown type "Nope"'], Validator::errors($unknown));
        $this->assertSame(['"name" is required of Product', '5: Product has no such property'], Validator::errors($known));
    }

    public function test_what_a_parent_requires_a_child_requires_too(): void
    {
        Validator::extend(['ValidatorErrorsFaqPage' => ['parents' => ['FAQPage']]]);

        $this->assertSame(['"mainEntity" is required of ValidatorErrorsFaqPage'], $this->errorsOf('ValidatorErrorsFaqPage', ['name' => 'x']));
        $this->assertSame([], $this->errorsOf('ValidatorErrorsFaqPage', ['mainEntity' => ['@type' => 'Question', 'name' => 'x', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'y']]]));
    }

    public function test_a_required_property_that_is_blank_is_missing(): void
    {
        $this->assertSame(['"name" is required of Product', 'name: empty'], $this->errorsOf('Product', ['name' => '']));
        $this->assertSame(['"name" is required of Product', 'name: empty'], $this->errorsOf('Product', ['name' => null]));
        $this->assertSame(['"name" is required of Product'], $this->errorsOf('Product', ['name' => []]));
        $this->assertSame([], $this->errorsOf('Product', ['name' => '0']));
    }

    public function test_an_empty_value_is_reported_once_and_not_as_anything_else(): void
    {
        $this->assertSame(['url: empty'], $this->errorsOf('Product', ['name' => 'x', 'url' => '']));
        $this->assertSame(['datePublished: empty'], $this->errorsOf('NewsArticle', ['headline' => 'x', 'datePublished' => null]));
        $this->assertSame(['offers.price: empty'], $this->errorsOf('Product', ['name' => 'x', 'offers' => ['@type' => 'Offer', 'price' => '']]));
    }

    public function test_a_node_in_a_property_is_checked_as_a_node_and_nothing_more(): void
    {
        $this->assertSame([], $this->errorsOf('NewsArticle', ['headline' => 'x', 'datePublished' => ['@type' => 'Thing', 'name' => 'x']]));
        $this->assertSame([], $this->errorsOf('Product', ['name' => 'x', 'offers' => ['@type' => 'Offer', 'price' => ['@type' => 'Thing'], 'priceCurrency' => ['@type' => 'Thing']]]));
    }

    public function test_a_list_of_values_is_checked_one_by_one(): void
    {
        $this->assertSame(
            ['image[1]: not an absolute address', 'image[2]: empty'],
            $this->errorsOf('Product', ['name' => 'x', 'image' => ['https://example.com/a.jpg', '/b.jpg', '']]),
        );
    }

    public function test_an_address_must_be_absolute(): void
    {
        $this->assertSame([], $this->errorsOf('Product', ['name' => 'x', 'url' => 'https://example.com/a', 'logo' => 'http://example.com']));
        $this->assertSame(['url: not an absolute address'], $this->errorsOf('Product', ['name' => 'x', 'url' => 'example.com/a']));
        $this->assertSame(['url: not an absolute address'], $this->errorsOf('Product', ['name' => 'x', 'url' => 'https:///a']));
    }

    public function test_dates_are_iso_8601_from_the_first_character_to_the_last(): void
    {
        foreach (['2026-01-01', '2026-01-01T10:00', '2026-01-01T10:00:30', '2026-01-01T10:00:30.5Z', '2026-01-01T10:00:30+0100', '2026-01-01T10:00:30-01:00'] as $date) {
            $this->assertSame([], $this->errorsOf('NewsArticle', ['headline' => 'x', 'datePublished' => $date]), $date);
        }

        foreach (['2026-01-01 and more', 'on 2026-01-01', ' 2026-01-01', '12026-01-01', '2026-01-01T10', '01.03.2026', '2026-13-45', 20260101] as $date) {
            $this->assertSame(['datePublished: not an ISO 8601 date'], $this->errorsOf('NewsArticle', ['headline' => 'x', 'datePublished' => $date]), (string) $date);
        }
    }

    public function test_numbers_may_be_text_but_must_be_numeric(): void
    {
        $this->assertSame([], $this->errorsOf('Product', ['name' => 'x', 'offers' => ['@type' => 'Offer', 'price' => '12.50']]));
        $this->assertSame(['offers.price: not a number'], $this->errorsOf('Product', ['name' => 'x', 'offers' => ['@type' => 'Offer', 'price' => '12,50']]));
    }

    public function test_a_currency_is_three_capitals_or_a_list_of_them(): void
    {
        $offer = static fn (string $property, string $currency): array => ['name' => 'x', 'offers' => ['@type' => 'Offer', $property => $currency]];

        $this->assertSame([], $this->errorsOf('Product', $offer('priceCurrency', 'EUR')));
        $this->assertSame([], $this->errorsOf('MonetaryAmount', ['currency' => 'EUR']));

        foreach (['EUR, USD', 'EUR,USD', 'EUR'] as $currency) {
            $this->assertSame([], $this->errorsOf('LocalBusiness', ['name' => 'x', 'address' => 'y', 'currenciesAccepted' => $currency]), $currency);
        }

        $this->assertSame(['currenciesAccepted: not an ISO 4217 currency'], $this->errorsOf('LocalBusiness', ['name' => 'x', 'address' => 'y', 'currenciesAccepted' => 'EUR;USD']));

        foreach (['EURO', 'EUR1', 'eur', 'EUR; USD', 'EUR,  USD', 'EUR,'] as $currency) {
            $this->assertSame(['offers.priceCurrency: not an ISO 4217 currency'], $this->errorsOf('Product', $offer('priceCurrency', $currency)), $currency);
        }
    }

    public function test_an_enumeration_is_the_address_of_a_schema_org_member(): void
    {
        $offer = static fn (string $availability): array => ['name' => 'x', 'offers' => ['@type' => 'Offer', 'availability' => $availability]];

        foreach (['https://schema.org/InStock', 'http://schema.org/OutOfStock'] as $member) {
            $this->assertSame([], $this->errorsOf('Product', $offer($member)), $member);
        }

        foreach (['InStock', 'xhttps://schema.org/InStock', 'https://schema.org/InStock1', 'https://schema.org/inStock', 'https://schema.org/'] as $member) {
            $this->assertSame(['offers.availability: not a schema.org enumeration member'], $this->errorsOf('Product', $offer($member)), $member);
        }
    }

    public function test_the_properties_of_a_type_are_its_own_then_its_parents_each_once(): void
    {
        Validator::extend(['ValidatorErrorsTwin' => ['parents' => ['Thing'], 'properties' => ['name', 'twinOnly']]]);

        $properties = Validator::properties('ValidatorErrorsTwin');

        $this->assertSame(['name', 'twinOnly', 'description', 'url', 'image', 'sameAs', 'identifier', 'alternateName', 'mainEntityOfPage', 'potentialAction', 'disambiguatingDescription', 'subjectOf'], $properties);
        $this->assertSame([], Validator::properties('NoSuchType'));
    }

    public function test_types_that_are_each_others_parents_are_walked_once(): void
    {
        Validator::extend([
            'ValidatorErrorsLoopA' => ['parents' => ['ValidatorErrorsLoopB'], 'properties' => ['a']],
            'ValidatorErrorsLoopB' => ['parents' => ['ValidatorErrorsLoopA'], 'properties' => ['b']],
        ]);

        $this->assertSame(['a', 'b'], Validator::properties('ValidatorErrorsLoopA'));
        $this->assertSame(['b', 'a'], Validator::properties('ValidatorErrorsLoopB'));
    }

    public function test_a_type_is_known_when_the_vocabulary_has_it(): void
    {
        $this->assertTrue(Validator::knows('Product'));
        $this->assertFalse(Validator::knows('Prodcut'));
    }
}
