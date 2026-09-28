<?php

declare(strict_types=1);

namespace Keelwatch\Tests\Support;

use Keelwatch\Bootstrap;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/** Validates documents against contracts/*.v1.json (test-only dependency). */
final class ContractValidator
{
    private static ?Validator $validator = null;

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null null when valid, otherwise formatted errors
     */
    public static function errors(string $contract, array $document): ?array
    {
        self::$validator ??= new Validator();

        $schema = json_decode(
            (string) file_get_contents(Bootstrap::ROOT . "/contracts/{$contract}.v1.json"),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        // Round-trip so JSON objects become stdClass, as the validator expects.
        $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = self::$validator->validate($data, $schema);

        return $result->isValid() ? null : (new ErrorFormatter())->format($result->error());
    }
}
