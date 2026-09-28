<?php

declare(strict_types=1);

namespace Keelwatch\Dashboard;

use RuntimeException;

final class RunActionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
