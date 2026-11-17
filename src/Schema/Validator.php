<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use DateTimeImmutable;
use Throwable;

/**
 * Checks JSON-LD against the schema.org types in resources/schema: known
 * types, properties those types have, the properties search engines
 * require, and the shape of addresses, dates, prices and enumerations.
 * Made for tests, the module's and any module's that ships a provider:
 *
 *     $this->assertSame([], Validator::errors($json));
 */
final class Validator
{
    /** Properties holding an absolute address when they hold text. */
    private const URLS = ['@id', 'url', 'item', 'logo', 'image', 'sameAs', 'contentUrl', 'thumbnailUrl', 'hasMap', 'urlTemplate', 'menu', 'tourBookingPage'];

    private const DATES = ['datePublished', 'dateModified', 'dateCreated', 'datePosted', 'startDate', 'endDate', 'validFrom', 'validThrough', 'priceValidUntil', 'releaseDate', 'previousStartDate', 'jobStartDate', 'availabilityStarts', 'availabilityEnds', 'productionDate', 'birthDate', 'foundingDate', 'lastReviewed'];

    private const NUMBERS = ['price', 'lowPrice', 'highPrice', 'minPrice', 'maxPrice', 'ratingValue', 'bestRating', 'worstRating', 'ratingCount', 'reviewCount', 'latitude', 'longitude', 'position', 'offerCount', 'numberOfItems', 'answerCount', 'numberOfRooms', 'totalJobOpenings'];

    /** Properties that name a member of a schema.org enumeration. */
    private const ENUMERATIONS = ['availability', 'itemCondition', 'eventStatus', 'eventAttendanceMode', 'dayOfWeek'];

    /** @var array<string,array{parents?:list<string>,properties?:list<string>,required?:list<string>}>|null */
    private static ?array $types = null;

    /**
     * Types of a module of one's own, with the same keys as the file.
     *
     * @param  array<string,array{parents?:list<string>,properties?:list<string>,required?:list<string>}>  $types
     */
    public static function extend(array $types): void
    {
        self::$types = [...self::types(), ...$types];
    }

    /**
     * What is wrong with a document, one line each; none when it is valid.
     *
     * @param  string|array<string,mixed>  $document  JSON-LD text or decoded
     * @return list<string>
     */
    public static function errors(string|array $document): array
    {
        if (is_string($document)) {
            try {
                $document = json_decode($document, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                return ['not JSON: '.$e->getMessage()];
            }

            if (! is_array($document)) {
                return ['not a JSON object'];
            }
        }

        $errors = [];
        $context = $document['@context'] ?? null;

        if (! in_array($context, ['https://schema.org', 'http://schema.org', 'https://schema.org/', 'http://schema.org/'], true)) {
            $errors[] = '@context: not schema.org';
        }

        if (array_key_exists('@graph', $document)) {
            if (! is_array($document['@graph']) || ! array_is_list($document['@graph']) || $document['@graph'] === []) {
                return [...$errors, '@graph: not a list of nodes'];
            }

            foreach ($document['@graph'] as $index => $node) {
                self::node($node, "@graph[{$index}]", $errors);
            }

            return $errors;
        }

        unset($document['@context']);
        self::node($document, '', $errors);

        return $errors;
    }

    /**
     * Every property a type has, its parents' included.
     *
     * @return list<string>
     */
    public static function properties(string $type): array
    {
        $types = self::types();
        $properties = [];
        $queue = [$type];
        $seen = [];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (in_array($current, $seen, true) || ! isset($types[$current])) {
                continue;
            }

            $seen[] = $current;
            $properties = [...$properties, ...($types[$current]['properties'] ?? [])];
            $queue = [...$queue, ...($types[$current]['parents'] ?? [])];
        }

        return array_values(array_unique($properties));
    }

    public static function knows(string $type): bool
    {
        return isset(self::types()[$type]);
    }

    /**
     * @param  list<string>  $errors
     */
    private static function node(mixed $node, string $path, array &$errors): void
    {
        $at = $path === '' ? '' : $path.': ';

        if (! is_array($node) || array_is_list($node)) {
            $errors[] = $at.'not a node';

            return;
        }

        $types = (array) ($node['@type'] ?? []);

        if ($types === []) {
            // A reference to a node described elsewhere.
            if (array_keys($node) !== ['@id']) {
                $errors[] = $at.'no @type';
            }

            return;
        }

        $allowed = [];
        $required = [];

        foreach ($types as $type) {
            if (! is_string($type) || ! self::knows($type)) {
                $errors[] = $at.'unknown type '.json_encode($type);

                continue;
            }

            $allowed = [...$allowed, ...self::properties($type)];
            $required = [...$required, ...self::required($type)];
        }

        foreach (array_unique($required) as $property) {
            if (self::blank($node[$property] ?? null)) {
                $errors[] = $at.'"'.$property.'" is required of '.implode(', ', $types);
            }
        }

        foreach ($node as $property => $value) {
            $property = (string) $property;
            $here = ltrim($path.'.'.$property, '.');

            if ($property === '@type' || $property === '@context') {
                continue;
            }

            if ($property !== '@id' && ! in_array($property, $allowed, true) && $allowed !== []) {
                $errors[] = $here.': '.implode(', ', $types).' has no such property';

                continue;
            }

            self::value($property, $value, $here, $errors);
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function value(string $property, mixed $value, string $path, array &$errors): void
    {
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::value($property, $item, "{$path}[{$index}]", $errors);
            }

            return;
        }

        if (is_array($value)) {
            self::node($value, $path, $errors);

            return;
        }

        if ($value === null || $value === '') {
            $errors[] = $path.': empty';

            return;
        }

        if (in_array($property, self::URLS, true) && is_string($value) && ! preg_match('#^https?://[^\s/]+#', $value)) {
            $errors[] = $path.': not an absolute address';
        }

        if (in_array($property, self::DATES, true) && ! self::date($value)) {
            $errors[] = $path.': not an ISO 8601 date';
        }

        if (in_array($property, self::NUMBERS, true) && ! is_numeric($value)) {
            $errors[] = $path.': not a number';
        }

        if (in_array($property, ['priceCurrency', 'currency', 'currenciesAccepted'], true) && ! preg_match('/^[A-Z]{3}(, ?[A-Z]{3})*$/', (string) $value)) {
            $errors[] = $path.': not an ISO 4217 currency';
        }

        if (in_array($property, self::ENUMERATIONS, true) && ! preg_match('#^https?://schema\.org/[A-Z][A-Za-z]+$#', (string) $value)) {
            $errors[] = $path.': not a schema.org enumeration member';
        }
    }

    /**
     * @return list<string>
     */
    private static function required(string $type): array
    {
        $types = self::types();
        $required = $types[$type]['required'] ?? [];

        foreach ($types[$type]['parents'] ?? [] as $parent) {
            $required = [...$required, ...self::required($parent)];
        }

        return $required;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function date(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value)) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string,array{parents?:list<string>,properties?:list<string>,required?:list<string>}>
     */
    private static function types(): array
    {
        return self::$types ??= require __DIR__.'/../../resources/schema/vocabulary.php';
    }
}
