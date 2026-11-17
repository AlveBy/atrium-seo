<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo;

use Atrium\Admin\Fields\CheckboxField;
use Atrium\Admin\Fields\ImageField;
use Atrium\Admin\Fields\SelectField;
use Atrium\Admin\Fields\TextareaField;
use Atrium\Admin\Fields\TextField;
use Atrium\Admin\Modules\ModuleSettings;

/**
 * Per site: who owns it (for the Organization markup), the search address
 * for the search box in results, the picture shared when a page has none,
 * the currency of {price}, IndexNow on or off.
 * Read with Setting::get('seo.organization_name').
 */
final class SeoSettings extends ModuleSettings
{
    public bool $perSite = true;

    public const TYPES = ['Organization', 'Corporation', 'LocalBusiness', 'Store', 'Restaurant', 'Hotel', 'LodgingBusiness',
        'MedicalClinic', 'TravelAgency', 'RealEstateAgent', 'EducationalOrganization', 'NGO'];

    public function description(): string
    {
        return __('atrium-seo::seo.settings.description');
    }

    public function fields(): array
    {
        return [
            TextField::make('organization_name')->label(__('atrium-seo::seo.settings.organization_name'))->hint(__('atrium-seo::seo.settings.organization_name_hint')),
            SelectField::make('organization_type')->label(__('atrium-seo::seo.settings.organization_type'))
                ->options(array_combine(self::TYPES, self::TYPES))->default('Organization'),
            ImageField::make('logo')->label(__('atrium-seo::seo.settings.logo')),
            TextareaField::make('phones')->label(__('atrium-seo::seo.settings.phones'))->hint(__('atrium-seo::seo.settings.one_per_line')),
            TextField::make('email')->label(__('atrium-seo::seo.settings.email'))->rules(['email']),
            TextField::make('address')->label(__('atrium-seo::seo.settings.address')),
            TextareaField::make('same_as')->label(__('atrium-seo::seo.settings.same_as'))->hint(__('atrium-seo::seo.settings.one_per_line')),
            TextField::make('search_url')->label(__('atrium-seo::seo.settings.search_url'))->hint(__('atrium-seo::seo.settings.search_url_hint')),
            ImageField::make('og_image')->label(__('atrium-seo::seo.settings.og_image'))->hint(__('atrium-seo::seo.settings.og_image_hint')),
            TextField::make('currency')->label(__('atrium-seo::seo.settings.currency'))->hint(__('atrium-seo::seo.settings.currency_hint'))
                ->rules(['string', 'size:3']),
            CheckboxField::make('indexnow')->label(__('atrium-seo::seo.settings.indexnow'))->hint(__('atrium-seo::seo.settings.indexnow_hint'))->default(true),
        ];
    }
}
