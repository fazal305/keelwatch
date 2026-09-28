"""Minimal JSON-over-HTTPS transport on the standard library.

Injected into providers so tests use a fake and never touch the network.
Response bodies from providers can echo prompts, so errors carry the
status code and a short, redacted excerpt only.
"""

from __future__ import annotations

import json
import socket
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Protocol

from ..redaction import redact
from .base import ProviderError, ProviderTimeout

MAX_RESPONSE_BYTES = 2 * 1024 * 1024


def is_allowed_url(url: str) -> bool:
    """HTTPS anywhere; plain HTTP only to this machine (e.g. a local gateway).

    Parsed, not prefix-matched: "http://localhost:1@evil.com" has host evil.com.
    Userinfo is refused outright so credentials never ride in a URL.
    """
    try:
        parts = urllib.parse.urlsplit(url)
        _ = parts.port  # raises on a malformed port
    except ValueError:
        return False
    if not parts.hostname or "@" in parts.netloc:
        return False
    if parts.scheme == "https":
        return True
    return parts.scheme == "http" and parts.hostname in ("localhost", "127.0.0.1")


@dataclass(frozen=True)
class HttpResponse:
    status: int
    headers: dict[str, str]
    body: dict[str, Any] | None
    text_excerpt: str


class Transport(Protocol):
    def post_json(
        self, url: str, headers: dict[str, str], payload: dict[str, Any], timeout_s: float
    ) -> HttpResponse: ...


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """urllib would follow redirects and resend the Authorization header to
    the new host. Provider APIs never need a redirect, so refuse them."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


_OPENER = urllib.request.build_opener(_NoRedirect)


class UrllibTransport:
    def post_json(
        self, url: str, headers: dict[str, str], payload: dict[str, Any], timeout_s: float
    ) -> HttpResponse:
        if not is_allowed_url(url):
            raise ProviderError(
                "provider URLs must use HTTPS (plain HTTP only on loopback)", retryable=False
            )
        if timeout_s <= 0:
            raise ProviderTimeout("no time left for the request")

        data = json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(  # noqa: S310 - scheme checked above
            url,
            data=data,
            method="POST",
            headers={"Content-Type": "application/json", "Accept": "application/json", **headers},
        )
        try:
            with _OPENER.open(request, timeout=timeout_s) as resp:
                raw = resp.read(MAX_RESPONSE_BYTES + 1)
                status = resp.status
                resp_headers = {k.lower(): v for k, v in resp.headers.items()}
        except urllib.error.HTTPError as exc:
            raw = exc.read(64 * 1024)
            status = exc.code
            resp_headers = {k.lower(): v for k, v in (exc.headers or {}).items()}
        except TimeoutError as exc:
            raise ProviderTimeout(f"request timed out after {timeout_s:.1f}s") from exc
        except urllib.error.URLError as exc:
            if isinstance(exc.reason, TimeoutError | socket.timeout):
                raise ProviderTimeout(f"request timed out after {timeout_s:.1f}s") from exc
            raise ProviderError(f"connection failed: {type(exc.reason).__name__}") from exc

        if len(raw) > MAX_RESPONSE_BYTES:
            raise ProviderError("provider response too large", retryable=False)

        text = raw.decode("utf-8", errors="replace")
        try:
            body = json.loads(text) if text else None
        except json.JSONDecodeError:
            body = None
        return HttpResponse(
            status, resp_headers, body if isinstance(body, dict) else None, redact(text[:300]).text
        )
