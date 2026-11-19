<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Segment;

/**
 * A translated segment does not carry the placeholder tokens of its source.
 */
final class PlaceholderException extends \RuntimeException
{
}
