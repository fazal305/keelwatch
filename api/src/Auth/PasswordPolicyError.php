<?php

declare(strict_types=1);

namespace Keelwatch\Auth;

use InvalidArgumentException;

/** A user-facing validation message about a username or password. */
final class PasswordPolicyError extends InvalidArgumentException
{
}
