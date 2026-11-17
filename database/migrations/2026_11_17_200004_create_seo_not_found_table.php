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
 * Addresses that answered 404, one row per address and site with a hit
 * counter: the log groups itself as it is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_not_found', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('path', 2048);
            $table->string('path_hash', 40);
            $table->unsignedBigInteger('hits')->default(0);
            $table->string('referer', 2048)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['site_id', 'path_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_not_found');
    }
};
