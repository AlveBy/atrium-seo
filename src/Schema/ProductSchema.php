<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Schema;

use Atrium\Seo\Contracts\SchemaProvider;
use Atrium\Seo\Meta\Variables;
use Atrium\Seo\SeoPage;
use Illuminate\Database\Eloquent\Model;

/**
 * Product with its Offer on a catalog product's page: the price in the
 * shop's currency, in stock or not, the brand and the article number.
 */
final class ProductSchema implements SchemaProvider
{
    public const MODEL = 'Atrium\\Catalog\\Models\\Product';

    public function nodes(SeoPage $page): array
    {
        if (! is_a($page->record, self::MODEL)) {
            return [];
        }

        $product = $page->record;
        $language = $page->language();
        $price = $product->getAttribute('price');

        return [Nodes::filled([
            '@type' => 'Product',
            '@id' => $page->mainEntityId(),
            'name' => Nodes::name($product, $language),
            'description' => Nodes::description($product, $language),
            'image' => Nodes::images($product),
            'sku' => $product->getAttribute('article') ?: null,
            'brand' => self::brand($product, $language),
            'url' => strtok($page->canonical(), '?'),
            'offers' => is_numeric($price) && $price > 0 ? [
                '@type' => 'Offer',
                'price' => number_format((float) $price, 2, '.', ''),
                'priceCurrency' => Variables::currency($page->site()),
                // The catalog's "In stock" box: Y when ticked, empty when not.
                'availability' => in_array($product->getAttribute('in_stock'), ['Y', true, 1, '1'], true)
                    ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => strtok($page->canonical(), '?'),
            ] : null,
        ])];
    }

    /**
     * @return array<string,string>|null
     */
    private static function brand(Model $product, string $language): ?array
    {
        $brand = $product->getRelationValue('brand');

        if (! $brand instanceof Model) {
            return null;
        }

        $name = Nodes::name($brand, $language);

        return $name === '' ? null : ['@type' => 'Brand', 'name' => $name];
    }
}
