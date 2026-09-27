<?php

declare(strict_types=1);

namespace Keelwatch\Support;

/**
 * Minimal .env reader. Real process environment variables always win over
 * values in the file, so production hosts can inject secrets without a file.
 */
final class Env
{
    /**
     * @return array<string, string>
     */
    public static function load(?string $path): array
    {
        $values = [];

        if ($path !== null && is_file($path)) {
            $values = self::parse((string) file_get_contents($path));
        }

        foreach (array_keys($values) as $key) {
            $real = getenv($key);
            if ($real !== false) {
                $values[$key] = $real;
            }
        }

        foreach (getenv() as $key => $value) {
            $values[$key] ??= $value;
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $values[$key] = self::parseValue(trim(substr($line, $eq + 1)));
        }

        return $values;
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        if (($quote === '"' || $quote === "'") && strlen($raw) >= 2 && str_ends_with($raw, $quote)) {
            $inner = substr($raw, 1, -1);
            return $quote === '"' ? stripcslashes($inner) : $inner;
        }

        // Unquoted values may carry a trailing " # comment".
        $hash = strpos($raw, ' #');
        return $hash === false ? $raw : rtrim(substr($raw, 0, $hash));
    }
}
