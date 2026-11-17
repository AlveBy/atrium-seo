<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Admin\Columns\Column;
use Atrium\Admin\Fields\Field;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Template;
use Atrium\Seo\SeoSettings;
use Atrium\Seo\Tests\TestCase;

/**
 * The forms and lists the panel draws for this module's records: what an
 * editor is asked for and what is checked.
 */
final class SeoDefinitionsTest extends TestCase
{
    /**
     * @param  array<int,Field>  $fields
     */
    private function field(array $fields, string $name): Field
    {
        foreach ($fields as $field) {
            if ($field->getName() === $name) {
                return $field;
            }
        }

        $this->fail('No field '.$name);
    }

    /**
     * The first rules a field is given: its type adds its own after them.
     *
     * @param  array<int,Field>  $fields
     * @return array<int,mixed>
     */
    private function rules(array $fields, string $name, int $count): array
    {
        return array_slice($this->field($fields, $name)->getValidationRules(), 0, $count);
    }

    /**
     * @param  array<int,Column>  $columns
     * @return array<string,mixed>
     */
    private function column(array $columns, string $name): array
    {
        foreach ($columns as $column) {
            $array = $column->toArray();

            if ($array['name'] === $name) {
                return $array;
            }
        }

        $this->fail('No column '.$name);
    }

    public function test_a_template_asks_for_a_module_and_the_texts(): void
    {
        $fields = Template::getAdministrationFields();

        $this->assertSame(['model', 'title', 'description', 'keywords'], array_map(static fn (Field $field): string => $field->getName(), $fields));
        $this->assertSame(['required', 'string', 'max:255'], $this->rules($fields, 'model', 3));
        $this->assertSame(['nullable'], $this->rules($fields, 'title', 1));
        $this->assertSame(__('atrium-seo::seo.templates.variables'), $this->field($fields, 'title')->toArray()['hint']);
        $this->assertSame(__('atrium-seo::seo.templates.variables'), $this->field($fields, 'description')->toArray()['hint']);
    }

    public function test_a_template_is_listed_by_module_and_title_cut_at_eighty_characters(): void
    {
        $columns = Template::getAdministrationListColumns();

        $this->assertSame(['module', 'title'], array_map(static fn (Column $column): string => $column->toArray()['name'], $columns));
        $this->assertSame(80, $this->column($columns, 'title')['limit']);
        $this->assertTrue($this->column($columns, 'title')['searchable']);
    }

    public function test_the_address_meta_asks_for_a_path_and_the_meta(): void
    {
        $fields = PageMeta::getAdministrationFields();

        $this->assertSame(['path', 'title', 'description', 'keywords', 'og_image', 'robots', 'canonical_url'], array_map(static fn (Field $field): string => $field->getName(), $fields));
        $this->assertSame(['required', 'string', 'max:2048'], $this->rules($fields, 'path', 3));
        $this->assertSame(['nullable', 'string', 'max:2048'], $this->rules($fields, 'canonical_url', 3));
        $this->assertSame(['nullable'], $this->rules($fields, 'robots', 1));
    }

    public function test_the_address_meta_is_listed_by_path_title_and_robots(): void
    {
        $columns = PageMeta::getAdministrationListColumns();

        $this->assertSame(['path', 'title', 'robots'], array_map(static fn (Column $column): string => $column->toArray()['name'], $columns));
        $this->assertSame(80, $this->column($columns, 'title')['limit']);
    }

    public function test_the_settings_ask_for_the_owner_the_type_the_pictures_and_the_currency(): void
    {
        $fields = (new SeoSettings)->fields();

        $this->assertSame(
            ['organization_name', 'organization_type', 'logo', 'phones', 'email', 'address', 'same_as', 'search_url', 'og_image', 'currency', 'indexnow'],
            array_map(static fn (Field $field): string => $field->getName(), $fields),
        );
        $this->assertSame(['nullable', 'email'], $this->rules($fields, 'email', 2));
        $this->assertSame(['nullable', 'string', 'size:3'], $this->rules($fields, 'currency', 3));
    }

    public function test_the_organization_types_are_chosen_by_their_own_names(): void
    {
        $options = $this->field((new SeoSettings)->fields(), 'organization_type')->toArray()['options'];

        $this->assertCount(count(SeoSettings::TYPES), $options);
        $this->assertSame(['value' => 'Organization', 'label' => 'Organization'], $options[0]);
        $this->assertSame(['value' => 'NGO', 'label' => 'NGO'], $options[array_key_last($options)]);
        foreach ($options as $option) {
            $this->assertSame($option['value'], $option['label']);
        }
        $this->assertSame('Organization', $this->field((new SeoSettings)->fields(), 'organization_type')->toArray()['default']);
    }
}
