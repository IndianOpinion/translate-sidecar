<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar;

/**
 * Small static helpers about the permalink setup.
 */
final class Routing
{
    public static function prettyPermalinks(): bool
    {
        return (string) get_option('permalink_structure', '') !== '';
    }
}
