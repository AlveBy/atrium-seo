{{--
    This file is part of Atrium (https://github.com/AlveBy/atrium).
    Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
--}}
{{--
    Printed into <x-atrium::head /> through the "atrium.head" filter: the
    robots meta, hreflang of every site and language, the JSON-LD graph.
--}}
@php($seoHead = app(\Atrium\Seo\Seo::class)->head())
@if ($seoHead['robots'])
<meta name="robots" content="{{ $seoHead['robots'] }}">
@endif
@foreach ($seoHead['hreflang'] as $seoLanguage => $seoUrl)
<link rel="alternate" hreflang="{{ $seoLanguage }}" href="{{ $seoUrl }}">
@endforeach
@if ($seoHead['jsonLd'])
<script type="application/ld+json">{!! $seoHead['jsonLd'] !!}</script>
@endif
