<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Meta;

use Atrium\Admin\Facades\Setting;
use Atrium\Admin\Models\Site;
use Atrium\Admin\Support\PublicUrl;
use Atrium\Seo\Support\Locale;
use Atrium\Seo\Support\Text;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use NumberFormatter;

/**
 * Fills a template in with a record:
 *
 *     "{name}[ for {price}] | {site}"  →  "Desk lamp for €49.00 | Lumen"
 *
 * {name}, {site}, {price}, {section}, {excerpt} and {date} are worked out;
 * any other {word} is the record's attribute of that name in the page's
 * language, without HTML. A part in square brackets is dropped when a
 * variable inside it is empty, so a record without a price reads
 * "Desk lamp | Lumen", not "Desk lamp for  | Lumen". A variable outside
 * the brackets is required: without it the whole template is empty and
 * the page falls back to the record's name or text.
 */
final class Variables
{
    /**
     * @param  array<string,string>  $extra  values of variables given by the page, such as {name} of a list
     */
    public function render(string $template, ?Model $record, string $language, Site $site, array $extra = []): string
    {
        $text = Str::of($template)->replaceMatches('/\[([^\[\]]*)\]/u', function (array $group) use ($record, $language, $site, $extra): string {
            $empty = false;
            $filled = $this->replace($group[1], $record, $language, $site, $empty, $extra);

            return $empty ? '' : $filled;
        })->toString();

        // Outside the brackets every variable is required: "{name} | {site}"
        // of a record without a name is no title at all.
        $empty = false;
        $text = Text::tidy($this->replace($text, $record, $language, $site, $empty, $extra));

        return $empty ? '' : $text;
    }

    /**
     * The value of one variable for a record, '' when it has none.
     */
    public function value(string $name, ?Model $record, string $language, Site $site): string
    {
        if ($name === 'site') {
            return trim((string) ($site->name ?: config('app.name')));
        }

        if ($record === null) {
            return '';
        }

        return match ($name) {
            'name' => Text::of($record, 'name', $language) ?: Text::of($record, 'title', $language),
            'price' => $this->price($record, $language, $site),
            'section' => $this->section($record, $language),
            'excerpt' => Text::excerpt(Text::of($record, 'preview_text', $language)
                ?: Text::of($record, 'detail_text', $language)
                ?: Text::of($record, 'description', $language), 200),
            'date' => $this->date($record, $language),
            default => $this->attribute($record, $name, $language),
        };
    }

    /**
     * @param  array<string,string>  $extra
     */
    private function replace(string $template, ?Model $record, string $language, Site $site, bool &$empty, array $extra = []): string
    {
        return Str::of($template)->replaceMatches('/\{(\w+)\}/u', function (array $match) use ($record, $language, $site, &$empty, $extra): string {
            $value = array_key_exists($match[1], $extra) ? trim($extra[$match[1]]) : $this->value($match[1], $record, $language, $site);

            if ($value === '') {
                $empty = true;
            }

            return $value;
        })->toString();
    }

    private function attribute(Model $record, string $name, string $language): string
    {
        if (Text::isTranslatable($record, $name)) {
            return Text::excerpt(Text::of($record, $name, $language), 300);
        }

        $value = $record->getAttribute($name);

        if (is_array($value)) {
            $value = array_values(array_filter($value, 'is_scalar'))[0] ?? '';
        }

        if ($value instanceof DateTimeInterface) {
            return $this->formatDate($value, $language);
        }

        return is_scalar($value) && ! is_bool($value) ? Text::excerpt((string) $value, 300) : '';
    }

    private function price(Model $record, string $language, Site $site): string
    {
        $price = $record->getAttribute('price');
        $amount = is_numeric($price) ? (float) $price : 0.0;

        if ($amount <= 0) {
            return '';
        }

        $formatter = new NumberFormatter(Locale::forTranslator($language), NumberFormatter::CURRENCY);

        // formatCurrency() says false when it cannot: a string either way.
        return Str::of($formatter->formatCurrency($amount, self::currency($site)))->toString();
    }

    /**
     * The site's currency: the SEO settings', else the shop's, else EUR.
     */
    public static function currency(Site $site): string
    {
        foreach (['seo.currency', 'shop.currency'] as $key) {
            $value = Setting::get($key, null, $site);

            if (is_string($value) && preg_match('/^[A-Z]{3}$/', $value)) {
                return $value;
            }
        }

        return 'EUR';
    }

    private function section(Model $record, string $language): string
    {
        $rule = PublicUrl::ruleFor($record::class);

        // A rule has a parent only with a tree to look it up in.
        if ($rule === null || $rule['parent'] === null) {
            return '';
        }

        $section = $rule['tree']::query()->find($record->getAttribute($rule['parent']));

        return $section instanceof Model ? (Text::of($section, 'name', $language) ?: Text::of($section, 'title', $language)) : '';
    }

    private function date(Model $record, string $language): string
    {
        foreach (['active_from', 'published_at', 'created_at'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if ($value instanceof DateTimeInterface) {
                return $this->formatDate($value, $language);
            }
        }

        return '';
    }

    private function formatDate(DateTimeInterface $date, string $language): string
    {
        return Carbon::instance($date)->locale(Locale::forTranslator($language))->isoFormat('LL');
    }
}
