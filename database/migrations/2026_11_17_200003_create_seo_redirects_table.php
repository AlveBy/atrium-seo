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
 * Redirects from an old address of a site to a new one. The source is the
 * site's own path (optionally with a query, or ending in * for everything
 * below it); the target is a path or a full URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('source', 2048);
            $table->string('source_hash', 40);
            $table->string('target', 2048)->nullable();
            $table->unsignedSmallInteger('status')->default(301);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirects');
    }
};
