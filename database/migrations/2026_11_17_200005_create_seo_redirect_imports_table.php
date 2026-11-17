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
 * A CSV file of redirects uploaded in the panel, and what came of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_redirect_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('file')->nullable();
            $table->unsignedSmallInteger('status')->default(301);
            $table->boolean('overwrite')->default(false);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_redirect_imports');
    }
};
