<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Tests\Feature;

use Atrium\Seo\Models\Issue;
use Atrium\Seo\Models\NotFound;
use Atrium\Seo\Models\PageMeta;
use Atrium\Seo\Models\Redirect;
use Atrium\Seo\Models\RedirectImport;
use Atrium\Seo\Models\Template;
use Atrium\Seo\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * The records of the SEO module are records the panel keeps for itself: they say so, in a method
 * the panel asks from outside the model, so it has to be public.
 */
final class ServiceModulesTest extends TestCase
{
    /**
     * @return array<string,array{class-string}>
     */
    public static function models(): array
    {
        return [
            'the SEO check' => [Issue::class],
            'the 404 log' => [NotFound::class],
            'redirect imports' => [RedirectImport::class],
            'redirects' => [Redirect::class],
            'page meta' => [PageMeta::class],
            'meta templates' => [Template::class],
        ];
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('models')]
    public function test_it_is_not_a_content_module(string $model): void
    {
        $method = new ReflectionMethod($model, 'isContentModule');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
        $this->assertFalse($method->invoke(null));
        $this->assertFalse($model::isContentModule());
    }
}
