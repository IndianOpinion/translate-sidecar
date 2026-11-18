<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Front;

use IndianOpinion\TranslateSidecar\Storage\Row;

/**
 * What a /xx/... request answers.
 */
final class Decision
{
    public function __construct(
        public readonly int $status,
        public readonly string $lang,
        public readonly int $postId = 0,
        public readonly ?string $location = null,
        public readonly ?Row $row = null,
        public readonly string $why = '',
        public readonly ?string $cacheControl = null,
    ) {
    }

    public function isTranslation(): bool
    {
        return $this->status === 200 && $this->row !== null;
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302], true) && $this->location !== null;
    }
}
