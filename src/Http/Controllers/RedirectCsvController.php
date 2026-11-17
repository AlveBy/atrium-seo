<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Http\Controllers;

use Atrium\Admin\Models\AdminUser;
use Atrium\Seo\Models\RedirectImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Takes the CSV file of an import. The panel's own upload is for the
 * public disk and does not take .csv: a list of redirects is not for the
 * world to download.
 */
final class RedirectCsvController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user(config('atrium.auth.guard', 'admin'));
        abort_unless($user instanceof AdminUser && $user->hasAdminPermission('seo_redirect_imports.create'), 403);

        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:csv,txt']]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->storeAs(RedirectImport::DIRECTORY, Str::uuid().'.csv', RedirectImport::DISK);

        // "url" is what the field shows; "path" is what it saves.
        return response()->json(['url' => $file->getClientOriginalName(), 'path' => $path, 'filename' => $file->getClientOriginalName()]);
    }
}
