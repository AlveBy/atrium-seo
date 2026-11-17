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
 * Meta templates per module and site, one text per language:
 * "{name}[ for {price}] | {site}". A row with model "*" serves every module
 * that has no row of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('model');
            $table->json('title')->nullable();
            $table->json('description')->nullable();
            $table->json('keywords')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_templates');
    }
};
