<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Contract;

use Keelwatch\Bootstrap;
use Keelwatch\Tests\Support\ContractValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every valid fixture must pass its contract and every invalid fixture
 * (a one-change patch on a valid one) must fail. worker/tests/test_contracts.py
 * runs the same fixtures, so PHP and Python cannot drift apart.
 */
final class ContractTest extends TestCase
{
    private const CONTRACTS = ['github-event', 'event-job', 'analysis-job', 'finding'];

    private static function dir(): string
    {
        return Bootstrap::ROOT . '/contracts';
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validFixtures(): iterable
    {
        foreach (self::CONTRACTS as $contract) {
            foreach (glob(self::dir() . "/fixtures/{$contract}/valid/*.json") ?: [] as $path) {
                yield "{$contract}/" . basename($path) => [$contract, $path];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFixtures(): iterable
    {
        foreach (self::CONTRACTS as $contract) {
            foreach (glob(self::dir() . "/fixtures/{$contract}/invalid/*.json") ?: [] as $path) {
                yield "{$contract}/" . basename($path) => [$contract, $path];
            }
        }
    }

    public function testEveryContractHasValidAndInvalidFixtures(): void
    {
        foreach (self::CONTRACTS as $contract) {
            self::assertFileExists(self::dir() . "/{$contract}.v1.json");
            self::assertNotEmpty(glob(self::dir() . "/fixtures/{$contract}/valid/*.json"), "{$contract} has no valid fixtures");
            self::assertNotEmpty(glob(self::dir() . "/fixtures/{$contract}/invalid/*.json"), "{$contract} has no invalid fixtures");
        }
    }

    #[DataProvider('validFixtures')]
    public function testValidFixturePasses(string $contract, string $path): void
    {
        $errors = self::validate($contract, self::readJson($path));

        self::assertNull($errors, 'Expected valid, got: ' . json_encode($errors));
    }

    #[DataProvider('invalidFixtures')]
    public function testInvalidFixtureFails(string $contract, string $path): void
    {
        $patch = self::readJson($path);
        $basePath = self::dir() . "/fixtures/{$contract}/valid/" . $patch['base'];
        self::assertFileExists($basePath, 'patch base must be a valid fixture');

        $document = self::applyPatch(self::readJson($basePath), $patch);

        self::assertNotNull(self::validate($contract, $document), basename($path) . ' should have been rejected');
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public static function applyPatch(array $document, array $patch): array
    {
        $changes = 0;

        foreach ($patch['set'] ?? [] as $dotted => $value) {
            $segments = explode('.', (string) $dotted);
            $target = &$document;
            foreach (array_slice($segments, 0, -1) as $segment) {
                self::assertIsArray($target[$segment] ?? null, "patch path {$dotted} does not exist in base");
                $target = &$target[$segment];
            }
            $leaf = end($segments);
            self::assertTrue(
                !array_key_exists($leaf, $target) || $target[$leaf] !== $value,
                "patch set on {$dotted} does not change the base (a no-op patch proves nothing)",
            );
            $target[$leaf] = $value;
            unset($target);
            $changes++;
        }

        foreach ($patch['unset'] ?? [] as $dotted) {
            $segments = explode('.', (string) $dotted);
            $target = &$document;
            foreach (array_slice($segments, 0, -1) as $segment) {
                $target = &$target[$segment];
            }
            $leaf = end($segments);
            self::assertArrayHasKey($leaf, $target, "patch unset path {$dotted} does not exist in base");
            unset($target[$leaf]);
            unset($target);
            $changes++;
        }

        self::assertSame(1, $changes, 'each invalid fixture must make exactly one change');

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private static function validate(string $contract, array $document): ?array
    {
        return ContractValidator::errors($contract, $document);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
