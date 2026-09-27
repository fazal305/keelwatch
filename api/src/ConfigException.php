<?php

declare(strict_types=1);

namespace Keelwatch;

use RuntimeException;

final class ConfigException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid configuration: ' . implode('; ', $errors));
    }
}
