<?php

declare(strict_types=1);

namespace Keelwatch;

/**
 * Validated, typed application configuration.
 *
 * Construction fails fast with every problem listed at once. Error messages
 * name the offending variable but never echo its value, so a misconfigured
 * secret can't leak into logs.
 */
final class Config
{
    public const ENVIRONMENTS = ['development', 'test', 'production'];

    /**
     * @param list<string> $corsOrigins
     */
    private function __construct(
        public readonly string $appEnv,
        public readonly string $appVersion,
        public readonly array $corsOrigins,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly int $dbConnectTimeoutS,
        public readonly int $workerStaleAfterS,
    ) {
    }

    /**
     * @param array<string, string> $env
     * @throws ConfigException
     */
    public static function fromEnv(array $env): self
    {
        $errors = [];

        $required = static function (string $key) use ($env, &$errors): string {
            $value = trim($env[$key] ?? '');
            if ($value === '') {
                $errors[] = "{$key} is required";
            }
            return $value;
        };

        $positiveInt = static function (string $key, int $default, int $max) use ($env, &$errors): int {
            $raw = trim($env[$key] ?? '');
            if ($raw === '') {
                return $default;
            }
            if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > $max) {
                $errors[] = "{$key} must be an integer between 1 and {$max}";
                return $default;
            }
            return (int) $raw;
        };

        $appEnv = $required('APP_ENV');
        if ($appEnv !== '' && !in_array($appEnv, self::ENVIRONMENTS, true)) {
            $errors[] = 'APP_ENV must be one of: ' . implode(', ', self::ENVIRONMENTS);
        }

        $corsOrigins = [];
        foreach (explode(',', $env['API_CORS_ORIGINS'] ?? '') as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '') {
                continue;
            }
            if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?$#', $origin)) {
                $errors[] = 'API_CORS_ORIGINS entries must be bare origins like https://app.example.com';
                continue;
            }
            $corsOrigins[] = $origin;
        }

        $config = new self(
            appEnv: $appEnv,
            appVersion: trim($env['APP_VERSION'] ?? '') ?: '0.0.0',
            corsOrigins: $corsOrigins,
            dbHost: $required('DB_HOST'),
            dbPort: $positiveInt('DB_PORT', 3306, 65535),
            dbName: $required('DB_NAME'),
            dbUser: $required('DB_USER'),
            dbPassword: $required('DB_PASSWORD'),
            dbConnectTimeoutS: $positiveInt('DB_CONNECT_TIMEOUT_S', 2, 30),
            workerStaleAfterS: $positiveInt('WORKER_STALE_AFTER_S', 35, 3600),
        );

        if ($errors !== []) {
            throw new ConfigException($errors);
        }

        return $config;
    }

    public function withDatabase(string $name): self
    {
        return new self(
            $this->appEnv,
            $this->appVersion,
            $this->corsOrigins,
            $this->dbHost,
            $this->dbPort,
            $name,
            $this->dbUser,
            $this->dbPassword,
            $this->dbConnectTimeoutS,
            $this->workerStaleAfterS,
        );
    }
}
