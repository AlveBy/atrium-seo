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
 * Meta of a page by its address: the home page, a module's list, any page an
 * application draws itself. The path is the site's own, without the folder
 * and without the language prefix; the texts are per language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('path', 2048);
            $table->string('path_hash', 40);
            $table->json('title')->nullable();
            $table->json('description')->nullable();
            $table->json('keywords')->nullable();
            $table->string('og_image')->nullable();
            $table->string('robots', 40)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'path_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
