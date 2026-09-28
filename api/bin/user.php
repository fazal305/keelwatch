<?php

declare(strict_types=1);

/**
 * Dashboard account management.
 *
 *   php bin/user.php create <username> [--role=admin|viewer]
 *   php bin/user.php reset-password <username>
 *   php bin/user.php disable <username>
 *   php bin/user.php list
 *
 * Passwords are generated here (24 random characters) and printed exactly
 * once; they are never accepted on the command line, where they would end up
 * in shell history. Users should change theirs after first sign-in.
 */

use Keelwatch\Auth\AuthService;
use Keelwatch\Auth\PasswordPolicyError;
use Keelwatch\Bootstrap;
use Keelwatch\ConfigException;
use Keelwatch\Database\Connection;

require __DIR__ . '/../vendor/autoload.php';

Bootstrap::convertErrorsToExceptions();

function generatedPassword(): string
{
    // Unambiguous characters; 24 of them from 56 symbols is ~139 bits.
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 24; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

$command = $argv[1] ?? '';
$username = strtolower($argv[2] ?? '');
$role = 'viewer';
foreach (array_slice($argv, 3) as $arg) {
    if (preg_match('/^--role=(admin|viewer)$/', $arg, $m)) {
        $role = $m[1];
    }
}

try {
    $pdo = Connection::open(Bootstrap::config());
} catch (ConfigException $e) {
    fwrite(STDERR, "Configuration invalid:\n  - " . implode("\n  - ", $e->errors) . "\n");
    exit(1);
}

try {
    switch ($command) {
        case 'create':
            $password = generatedPassword();
            AuthService::createUser($pdo, $username, $password, $role);
            echo "Created {$role} '{$username}'.\nPassword (shown once): {$password}\nChange it after signing in.\n";
            break;

        case 'reset-password':
            $password = generatedPassword();
            AuthService::checkPolicy($username, $password);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3) WHERE username = ?');
            $stmt->execute([password_hash($password, PASSWORD_ARGON2ID), $username]);
            if ($stmt->rowCount() !== 1) {
                throw new PasswordPolicyError("No user '{$username}'.");
            }
            $pdo->prepare('DELETE s FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.username = ?')->execute([$username]);
            echo "New password for '{$username}' (shown once): {$password}\nAll their sessions were signed out.\n";
            break;

        case 'disable':
            $stmt = $pdo->prepare('UPDATE users SET disabled_at = UTC_TIMESTAMP(3) WHERE username = ? AND disabled_at IS NULL');
            $stmt->execute([$username]);
            if ($stmt->rowCount() !== 1) {
                throw new PasswordPolicyError("No active user '{$username}'.");
            }
            $pdo->prepare('DELETE s FROM sessions s JOIN users u ON u.id = s.user_id WHERE u.username = ?')->execute([$username]);
            echo "Disabled '{$username}' and signed out their sessions.\n";
            break;

        case 'list':
            foreach ($pdo->query('SELECT username, role, last_login_at, disabled_at FROM users ORDER BY username') as $u) {
                printf(
                    "%-24s %-7s last sign-in: %-23s %s\n",
                    $u['username'],
                    $u['role'],
                    $u['last_login_at'] ?? 'never',
                    $u['disabled_at'] ? 'DISABLED' : '',
                );
            }
            break;

        default:
            fwrite(STDERR, "Usage: php bin/user.php create <username> [--role=admin|viewer] | reset-password <username> | disable <username> | list\n");
            exit(2);
    }
} catch (PasswordPolicyError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} catch (PDOException $e) {
    fwrite(STDERR, str_contains($e->getMessage(), 'uq_users_username') ? "User '{$username}' already exists.\n" : "Database error.\n");
    exit(1);
}
