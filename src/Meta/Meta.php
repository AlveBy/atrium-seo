<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Seo\Meta;

/**
 * What goes into the head of one page in one language. Empty strings and
 * nulls mean "nothing to say": the page keeps what it had.
 */
final class Meta
{
    public function __construct(
        public string $title = '',
        public string $description = '',
        public string $keywords = '',
        public ?string $image = null,
        public ?string $robots = null,
        public ?string $canonical = null,
    ) {}

    /**
     * This meta with the gaps filled from another one.
     */
    public function over(self $other): self
    {
        return new self(
            $this->title !== '' ? $this->title : $other->title,
            $this->description !== '' ? $this->description : $other->description,
            $this->keywords !== '' ? $this->keywords : $other->keywords,
            $this->image ?? $other->image,
            $this->robots ?? $other->robots,
            $this->canonical ?? $other->canonical,
        );
    }
}
