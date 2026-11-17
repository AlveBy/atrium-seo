<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the last SEO check found on a site: one row per problem and page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('check', 40)->index();
            $table->string('language', 20)->nullable();
            $table->string('label')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('detail', 2048)->nullable();
            $table->string('model')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_issues');
    }
};
