"""Where notifications may go, and how they are sent.

SSRF defence:
- only exact Slack incoming-webhook and Discord webhook URL shapes on their
  own hosts; no userinfo, no non-443 port, no query or fragment;
- every address the host resolves to must be a public (global) IP, checked
  immediately before each send;
- redirects are refused by the transport.
urllib resolves the host again when connecting, so the IP check is defence
in depth against a poisoned local resolver, not a pinned connection. The
exact-host allowlist is the primary control.

Message safety:
- Discord: allowed_mentions is empty, so text can never ping @everyone,
  roles or users.
- Slack: &, < and > are escaped, so text can't form <!channel> or links.
"""

from __future__ import annotations

import ipaddress
import re
import socket
import urllib.parse
from collections.abc import Callable
from dataclasses import dataclass

from ..llm.base import ProviderError
from ..llm.http import Transport, UrllibTransport

KINDS = ("slack", "discord")
SEVERITIES = ("critical", "high", "medium", "low", "info")
_SLACK_PATH = re.compile(r"/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+")
_DISCORD_PATH = re.compile(r"/api/webhooks/[0-9]{1,25}/[A-Za-z0-9_-]{1,100}")
DISCORD_LIMIT = 2000
SLACK_LIMIT = 3500

Resolver = Callable[..., list]


class InvalidDestination(ValueError):
    pass


def validate_url(kind: str, url: str) -> str:
    """Returns the host if the URL is an allowed webhook for this kind."""
    if kind not in KINDS:
        raise InvalidDestination(f"kind must be one of {', '.join(KINDS)}")
    try:
        parts = urllib.parse.urlsplit(url.strip())
        port = parts.port
    except ValueError as exc:
        raise InvalidDestination("not a valid URL") from exc
    host = (parts.hostname or "").lower()
    if parts.scheme != "https":
        raise InvalidDestination("webhook URLs must use https")
    if parts.username or parts.password or "@" in parts.netloc:
        raise InvalidDestination("webhook URLs must not contain credentials before the host")
    if port not in (None, 443):
        raise InvalidDestination("webhook URLs must use the default https port")
    if parts.query or parts.fragment:
        raise InvalidDestination("webhook URLs must not have a query string or fragment")
    if kind == "slack":
        if host != "hooks.slack.com" or not _SLACK_PATH.fullmatch(parts.path):
            raise InvalidDestination("expected https://hooks.slack.com/services/…")
    elif host not in ("discord.com", "discordapp.com") or not _DISCORD_PATH.fullmatch(parts.path):
        raise InvalidDestination("expected https://discord.com/api/webhooks/<id>/<token>")
    return host


def ensure_public(host: str, resolver: Resolver = socket.getaddrinfo) -> list[str]:
    try:
        infos = resolver(host, 443, proto=socket.IPPROTO_TCP)
    except OSError as exc:
        raise InvalidDestination(f"could not resolve {host}") from exc
    addresses = sorted({info[4][0] for info in infos})
    if not addresses:
        raise InvalidDestination(f"{host} did not resolve to any address")
    for address in addresses:
        if not ipaddress.ip_address(address.split("%", 1)[0]).is_global:
            raise InvalidDestination(f"{host} resolves to a non-public address")
    return addresses


def escape_slack(text: str) -> str:
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def payload_for(kind: str, text: str) -> dict:
    if kind == "slack":
        body = escape_slack(text)
        if len(body) > SLACK_LIMIT:
            body = body[: SLACK_LIMIT - 1] + "…"
        return {"text": body, "unfurl_links": False, "unfurl_media": False}
    body = text if len(text) <= DISCORD_LIMIT else text[: DISCORD_LIMIT - 1] + "…"
    return {"content": body, "allowed_mentions": {"parse": []}}


@dataclass(frozen=True)
class SendResult:
    status: str  # sent | retry | failed
    http_status: int | None = None
    error: str | None = None
    retry_after_s: float | None = None


class Sender:
    def __init__(
        self, transport: Transport | None = None, resolver: Resolver = socket.getaddrinfo
    ) -> None:
        self._transport = transport or UrllibTransport()
        self._resolver = resolver

    def send(self, kind: str, url: str, text: str, timeout_s: float) -> SendResult:
        host = validate_url(kind, url)
        ensure_public(host, self._resolver)
        try:
            response = self._transport.post_json(url, {}, payload_for(kind, text), timeout_s)
        except ProviderError as exc:
            # Transport-level failure (timeout, connection, refused redirect).
            return SendResult("retry" if exc.retryable else "failed", None, _safe(str(exc)))

        status = response.status
        if 200 <= status < 300:
            return SendResult("sent", status)
        if status == 429:
            try:
                wait = float(response.headers.get("retry-after", ""))
            except ValueError:
                wait = None
            return SendResult("retry", status, "rate limited", wait)
        if status >= 500:
            return SendResult("retry", status, f"HTTP {status}")
        # 400/401/403/404: the webhook was revoked or the payload rejected.
        return SendResult("failed", status, f"HTTP {status}")


def _safe(message: str) -> str:
    # Errors must never echo the webhook URL (it is a credential).
    return re.sub(r"https://\S+", "[url]", message)[:500]
