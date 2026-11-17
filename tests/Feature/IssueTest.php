<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Catalog\Models\Product;
use Atrium\Seo\Models\Issue;
use Atrium\Seo\Tests\TestCase;
use Inertia\Testing\AssertableInertia;

/**
 * The rows of SEO → Check: what a row says about itself and where it leads.
 */
final class IssueTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $attributes
     */
    private function issue(array $attributes): Issue
    {
        return (new Issue)->forceFill($attributes);
    }

    public function test_the_check_is_named_and_a_language_is_added_when_there_is_one(): void
    {
        $this->assertSame(__('atrium-seo::seo.checks.title_long'), $this->issue(['check' => 'title_long', 'language' => null])->getCheckLabelAttribute());
        $this->assertSame(__('atrium-seo::seo.checks.title_long'), $this->issue(['check' => 'title_long', 'language' => ''])->check_label);
        $this->assertSame(__('atrium-seo::seo.checks.title_long').' (de)', $this->issue(['check' => 'title_long', 'language' => 'de'])->getCheckLabelAttribute());
    }

    public function test_the_check_leads_to_the_list_of_its_kind(): void
    {
        $issue = $this->issue(['check' => 'broken_link']);

        $this->assertSame(route('admin.seo_issues.index', ['check' => 'broken_link']), $issue->getCheckUrlAttribute());
    }

    public function test_the_row_leads_to_the_form_of_the_record_to_fix(): void
    {
        $product = Product::query()->create(['name' => 'Lamp', 'code' => 'lamp']);
        $issue = $this->issue(['model' => Product::class, 'record_id' => $product->id, 'url' => 'http://localhost/catalog/lamp']);

        $this->assertSame(route('admin.catalog_products.edit', $product->id), $issue->getEditUrlAttribute());
    }

    public function test_the_row_leads_to_the_page_when_there_is_no_record_to_fix(): void
    {
        $page = 'http://localhost/en/calendar';

        $this->assertSame($page, $this->issue(['model' => null, 'record_id' => null, 'url' => $page])->getEditUrlAttribute());
        $this->assertSame($page, $this->issue(['model' => null, 'record_id' => 5, 'url' => $page])->getEditUrlAttribute());
        $this->assertSame($page, $this->issue(['model' => Product::class, 'record_id' => null, 'url' => $page])->getEditUrlAttribute());
        $this->assertSame($page, $this->issue(['model' => 'App\\Models\\Gone', 'record_id' => 5, 'url' => $page])->getEditUrlAttribute());
        $this->assertNull($this->issue(['model' => null, 'record_id' => null, 'url' => null])->getEditUrlAttribute());
    }

    public function test_the_list_is_fifty_rows_a_page_and_cannot_create_or_edit_rows(): void
    {
        config(['atrium-seo.check.inline' => false]);
        $rows = [];

        for ($i = 1; $i <= 51; $i++) {
            $rows[] = ['site_id' => 1, 'check' => 'title_long', 'label' => 'Page '.$i, 'created_at' => now(), 'updated_at' => now()];
        }

        Issue::query()->withoutGlobalScopes()->insert($rows);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin/seo_issues')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('items.total', 51)->where('items.per_page', 50)->has('items.data', 50)->where('can.create', false)->where('can.update', false));

        $this->get('/admin/seo_issues/create')->assertForbidden();
        $this->assertSame(50, Issue::getAdminPerPage());
        $this->assertFalse(Issue::isCreatableInAdmin());
        $this->assertFalse(Issue::isEditableInAdmin());
    }

    public function test_the_switches_the_admin_reads_are_public(): void
    {
        foreach (['isCreatableInAdmin', 'isEditableInAdmin', 'getAdminPerPage', 'getCheckLabelAttribute', 'getCheckUrlAttribute', 'getEditUrlAttribute'] as $method) {
            $this->assertTrue((new \ReflectionMethod(Issue::class, $method))->isPublic(), $method);
        }
    }

    public function test_the_list_shows_the_check_the_page_the_address_and_the_detail(): void
    {
        $columns = array_map(static fn ($column): array => $column->toArray(), Issue::getAdministrationListColumns());

        $this->assertSame(['check_label', 'label', 'url', 'detail'], array_column($columns, 'name'));
        $this->assertSame(['check_url', 'edit_url', 'url', null], array_column($columns, 'urlFrom'));
        $this->assertSame([null, null, 60, 120], array_column($columns, 'limit'));
        $this->assertSame([false, true, false, false], array_column($columns, 'searchable'));
        $this->assertSame([
            __('atrium-seo::seo.fields.check'), __('atrium-seo::seo.fields.page'), __('atrium-seo::seo.fields.url'), __('atrium-seo::seo.fields.detail'),
        ], array_column($columns, 'label'));
    }
}
