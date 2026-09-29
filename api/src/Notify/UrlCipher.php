<?php

declare(strict_types=1);

namespace Keelwatch\Notify;

use InvalidArgumentException;
use RuntimeException;

/**
 * Encryption at rest for notification destination URLs, byte-compatible with
 * the worker's keelwatch_worker.notify.crypto:
 *
 *     blob = 0x01 (format version) || 12-byte random nonce || ciphertext || 16-byte tag
 *
 * with associated data "keelwatch:notification-url:v1". Both sides are tested
 * against contracts/test-vectors/notification-url-encryption.v1.json.
 */
final class UrlCipher
{
    private const VERSION = "\x01";
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const AAD = 'keelwatch:notification-url:v1';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('Notification key must be exactly 32 bytes.');
        }
    }

    /**
     * @param string|null $nonce only for test vectors; production always uses a random nonce
     */
    public function seal(string $plaintext, ?string $nonce = null): string
    {
        $nonce ??= random_bytes(self::NONCE_BYTES);
        if (strlen($nonce) !== self::NONCE_BYTES) {
            throw new InvalidArgumentException('Nonce must be 12 bytes.');
        }
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, self::TAG_BYTES);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return self::VERSION . $nonce . $ciphertext . $tag;
    }

    /**
     * The API never needs to read a stored URL back; this exists so tests can
     * prove round-trips and the shared vector.
     */
    public function open(string $blob): string
    {
        if (strlen($blob) < 1 + self::NONCE_BYTES + self::TAG_BYTES || $blob[0] !== self::VERSION) {
            throw new RuntimeException('Unsupported or truncated ciphertext.');
        }
        $nonce = substr($blob, 1, self::NONCE_BYTES);
        $tag = substr($blob, -self::TAG_BYTES);
        $ciphertext = substr($blob, 1 + self::NONCE_BYTES, -self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD);
        if ($plaintext === false) {
            throw new RuntimeException('Ciphertext was tampered with or the key is wrong.');
        }
        return $plaintext;
    }
}
