<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Unit;

use Atrium\Seo\Schema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The schema.org check the modules' tests run on their markup: the types
 * the docs promise to the other modules pass, broken markup does not.
 */
final class ValidatorTest extends TestCase
{
    /**
     * The nodes docs/seo.md shows for the modules that bring their own.
     *
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function modules(): array
    {
        $place = ['@type' => 'Place', 'name' => 'Main hall', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => '1 Main Street', 'addressLocality' => 'Springfield', 'addressCountry' => 'US']];
        $organization = ['@type' => 'Organization', 'name' => 'Demo Ltd', 'url' => 'https://example.com/'];
        $offer = ['@type' => 'Offer', 'price' => '120.00', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock', 'url' => 'https://example.com/x'];

        return [
            'tour' => [['@type' => 'TouristTrip', 'name' => 'Seven lakes', 'description' => 'A week on foot', 'touristType' => 'Hikers', 'itinerary' => ['@type' => 'ItemList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'item' => ['@type' => 'TouristAttraction', 'name' => 'First lake']]]], 'offers' => $offer, 'provider' => $organization]],
            'event' => [['@type' => 'Event', 'name' => 'Launch party', 'startDate' => '2026-12-01T19:00:00+01:00', 'endDate' => '2026-12-01T23:00', 'eventStatus' => 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'location' => $place, 'organizer' => $organization, 'offers' => $offer, 'image' => ['https://example.com/party.jpg']]],
            'job' => [['@type' => 'JobPosting', 'title' => 'Cook', 'description' => '<p>Cook for a small team.</p>', 'datePosted' => '2026-11-01', 'validThrough' => '2026-12-31T23:59:59+00:00', 'employmentType' => 'FULL_TIME', 'hiringOrganization' => $organization, 'jobLocation' => $place, 'baseSalary' => ['@type' => 'MonetaryAmount', 'currency' => 'EUR', 'value' => ['@type' => 'QuantitativeValue', 'minValue' => 2000, 'maxValue' => 2600, 'unitText' => 'MONTH']]]],
            'hotel' => [['@type' => 'Hotel', 'name' => 'Lakeside', 'address' => $place['address'], 'telephone' => '+1 555 0100', 'checkinTime' => '14:00', 'checkoutTime' => '12:00', 'starRating' => ['@type' => 'Rating', 'ratingValue' => 4], 'amenityFeature' => [['@type' => 'LocationFeatureSpecification', 'name' => 'Pool', 'value' => true]], 'priceRange' => '€€']],
            'clinic' => [['@type' => 'MedicalClinic', 'name' => 'Family clinic', 'address' => $place['address'], 'medicalSpecialty' => 'https://schema.org/Pediatric', 'openingHoursSpecification' => [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/Monday', 'opens' => '08:00', 'closes' => '18:00']]]],
            'physician' => [['@type' => 'Physician', 'name' => 'Dr. Jane Roe', 'address' => $place['address'], 'medicalSpecialty' => 'https://schema.org/Dermatology', 'hospitalAffiliation' => ['@type' => 'Organization', 'name' => 'City hospital']]],
            'realty' => [['@type' => 'RealEstateListing', 'name' => 'Two rooms by the park', 'url' => 'https://example.com/flat', 'datePosted' => '2026-11-10', 'offers' => $offer, 'about' => ['@type' => 'Apartment', 'name' => 'Flat 12', 'numberOfRooms' => 2, 'floorSize' => ['@type' => 'QuantitativeValue', 'value' => 54, 'unitCode' => 'MTK'], 'address' => $place['address']]]],
            'classified' => [['@type' => 'Car', 'name' => 'Hatchback 2019', 'brand' => ['@type' => 'Brand', 'name' => 'Demo motors'], 'mileageFromOdometer' => ['@type' => 'QuantitativeValue', 'value' => 54000, 'unitCode' => 'KMT'], 'offers' => [...$offer, 'itemCondition' => 'https://schema.org/UsedCondition', 'seller' => ['@type' => 'Person', 'name' => 'Sam']]]],
            'blog' => [['@type' => 'BlogPosting', 'headline' => 'Ten tips', 'datePublished' => '2026-11-01T09:00:00Z', 'dateModified' => '2026-11-02', 'author' => ['@type' => 'Person', 'name' => 'Sam', 'url' => 'https://example.com/sam'], 'publisher' => $organization, 'image' => 'https://example.com/tips.jpg', 'mainEntityOfPage' => 'https://example.com/tips', 'keywords' => 'tips, how-to', 'wordCount' => 900]],
        ];
    }

    /**
     * @param  array<string,mixed>  $node
     */
    #[DataProvider('modules')]
    public function test_the_types_promised_to_the_modules_pass(array $node): void
    {
        $this->assertSame([], Validator::errors(['@context' => 'https://schema.org', ...$node]));
        $this->assertSame([], Validator::errors(json_encode(['@context' => 'https://schema.org', '@graph' => [$node]], JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string,array{0:array<string,mixed>|string,1:string}>
     */
    public static function broken(): array
    {
        $product = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Mouse'];

        return [
            'not JSON' => ['{"@context":', 'not JSON'],
            'not an object' => ['"text"', 'not a JSON object'],
            'another vocabulary' => [['@context' => 'https://example.com', '@type' => 'Product', 'name' => 'Mouse'], '@context: not schema.org'],
            'empty graph' => [['@context' => 'https://schema.org', '@graph' => []], '@graph: not a list of nodes'],
            'a graph of text' => [['@context' => 'https://schema.org', '@graph' => ['x']], '@graph[0]: not a node'],
            'no type' => [['@context' => 'https://schema.org', '@graph' => [['name' => 'x']]], '@graph[0]: no @type'],
            'unknown type' => [[...$product, '@type' => 'Prodcut'], 'unknown type "Prodcut"'],
            'unknown property' => [[...$product, 'prize' => 10], 'prize: Product has no such property'],
            'property of another type' => [[...$product, 'startDate' => '2026-01-01'], 'startDate: Product has no such property'],
            'required missing' => [['@context' => 'https://schema.org', '@type' => 'Event', 'name' => 'Party', 'location' => 'Hall'], '"startDate" is required of Event'],
            'required nested' => [[...$product, 'review' => [['@type' => 'Review', 'reviewBody' => 'Nice']]], 'review[0]: "author" is required of Review'],
            'relative address' => [[...$product, 'url' => '/catalog/mouse'], 'url: not an absolute address'],
            'bad date' => [['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => 'x', 'datePublished' => '01.03.2026'], 'datePublished: not an ISO 8601 date'],
            'impossible date' => [['@context' => 'https://schema.org', '@type' => 'NewsArticle', 'headline' => 'x', 'datePublished' => '2026-13-45'], 'datePublished: not an ISO 8601 date'],
            'price as text' => [[...$product, 'offers' => ['@type' => 'Offer', 'price' => 'ten', 'priceCurrency' => 'EUR']], 'offers.price: not a number'],
            'currency' => [[...$product, 'offers' => ['@type' => 'Offer', 'price' => 10, 'priceCurrency' => 'euro']], 'offers.priceCurrency: not an ISO 4217 currency'],
            'enumeration' => [[...$product, 'offers' => ['@type' => 'Offer', 'price' => 10, 'availability' => 'in stock']], 'offers.availability: not a schema.org enumeration member'],
            'empty value' => [[...$product, 'sku' => ''], 'sku: empty'],
            'list of text where a node is due' => [[...$product, 'offers' => [['price' => 10]]], 'offers[0]: no @type'],
        ];
    }

    /**
     * @param  array<string,mixed>|string  $document
     */
    #[DataProvider('broken')]
    public function test_broken_markup_is_caught(array|string $document, string $error): void
    {
        $errors = Validator::errors($document);

        $this->assertNotSame([], $errors);
        $this->assertTrue(
            (bool) array_filter($errors, static fn (string $line): bool => str_contains($line, $error)),
            'Expected "'.$error.'" among: '.implode(' | ', $errors),
        );
    }

    public function test_a_reference_to_another_node_is_enough(): void
    {
        $this->assertSame([], Validator::errors(['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'Organization', '@id' => 'https://example.com/#org', 'name' => 'Demo'],
            ['@type' => 'WebSite', 'url' => 'https://example.com/', 'publisher' => ['@id' => 'https://example.com/#org']],
        ]]));
    }

    public function test_a_node_of_two_types_has_the_properties_of_both(): void
    {
        $this->assertSame([], Validator::errors(['@context' => 'https://schema.org', '@type' => ['Product', 'Car'], 'name' => 'Hatchback', 'vehicleEngine' => 'x']));
        $this->assertSame(['unknown type "Nope"'], Validator::errors(['@context' => 'https://schema.org', '@type' => ['Product', 'Nope'], 'name' => 'x']));
    }

    public function test_a_module_teaches_it_a_type_of_its_own(): void
    {
        $node = ['@context' => 'https://schema.org', '@type' => 'ExerciseGym', 'name' => 'Gym', 'address' => '1 Main Street'];
        $this->assertSame(['unknown type "ExerciseGym"'], Validator::errors($node));

        Validator::extend(['ExerciseGym' => ['parents' => ['SportsActivityLocation']]]);

        $this->assertTrue(Validator::knows('ExerciseGym'));
        $this->assertSame([], Validator::errors($node));
        $this->assertContains('openingHours', Validator::properties('ExerciseGym'));
        $this->assertContains('geo', Validator::properties('ExerciseGym'));
    }
}
