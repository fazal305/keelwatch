<?php

declare(strict_types=1);

namespace Keelwatch\Webhook;

use RuntimeException;

/** A signed payload that doesn't have the shape we rely on. */
final class NormalizationException extends RuntimeException
{
}
