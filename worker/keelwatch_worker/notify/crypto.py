"""Encryption at rest for notification destination URLs.

A webhook URL is itself a credential (anyone holding it can post to the
channel), so it is stored as AES-256-GCM ciphertext:

    blob = 0x01 (format version) || 12-byte random nonce || ciphertext || 16-byte tag

with associated data "keelwatch:notification-url:v1". The PHP API uses the
same layout; contracts/test-vectors/notification-url-encryption.v1.json lets
both implementations prove they agree.
"""

from __future__ import annotations

import base64
import binascii
import os

from cryptography.exceptions import InvalidTag
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

VERSION = b"\x01"
NONCE_BYTES = 12
TAG_BYTES = 16
AAD = b"keelwatch:notification-url:v1"


class InvalidKey(ValueError):
    pass


class DecryptError(ValueError):
    pass


def parse_key(raw: str) -> bytes:
    """NOTIFICATION_KEY is 32 random bytes, base64-encoded."""
    try:
        key = base64.b64decode(raw.strip(), validate=True)
    except (binascii.Error, ValueError) as exc:
        raise InvalidKey("NOTIFICATION_KEY must be base64") from exc
    if len(key) != 32:
        raise InvalidKey("NOTIFICATION_KEY must decode to exactly 32 bytes")
    return key


def seal(plaintext: str, key: bytes, nonce: bytes | None = None) -> bytes:
    nonce = nonce if nonce is not None else os.urandom(NONCE_BYTES)
    if len(nonce) != NONCE_BYTES:
        raise ValueError("nonce must be 12 bytes")
    return VERSION + nonce + AESGCM(key).encrypt(nonce, plaintext.encode("utf-8"), AAD)


def open_sealed(blob: bytes, key: bytes) -> str:
    if len(blob) < 1 + NONCE_BYTES + TAG_BYTES or blob[:1] != VERSION:
        raise DecryptError("unsupported or truncated ciphertext")
    nonce, body = blob[1 : 1 + NONCE_BYTES], blob[1 + NONCE_BYTES :]
    try:
        return AESGCM(key).decrypt(nonce, body, AAD).decode("utf-8")
    except InvalidTag as exc:
        raise DecryptError("ciphertext was tampered with or the key is wrong") from exc
