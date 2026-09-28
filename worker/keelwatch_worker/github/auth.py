"""GitHub App authentication.

A short-lived JWT, signed with the App's private key (RS256), is exchanged
for an installation access token. Tokens are cached in memory only, until
five minutes before they expire. The private key is read from a file
path, never from an inline environment value, and never logged.

AnonymousAuth is the fallback when no App is configured: it can read
public repositories only (GitHub allows 60 unauthenticated requests/hour).
"""

from __future__ import annotations

import time
from collections.abc import Callable
from datetime import datetime
from pathlib import Path

import jwt

from ..budget import Budget
from .http import GitHubError, Transport, UrllibTransport, raise_for_status

TOKEN_REFRESH_MARGIN_S = 300


class AnonymousAuth:
    can_read_private = False

    def headers(self, installation_id: int, budget: Budget) -> dict[str, str]:
        return {}

    def __repr__(self) -> str:
        return "AnonymousAuth()"


class AppAuth:
    can_read_private = True

    def __init__(
        self,
        app_id: str,
        private_key_pem: str,
        api_url: str = "https://api.github.com",
        transport: Transport | None = None,
        clock: Callable[[], float] = time.time,
    ) -> None:
        self._app_id = app_id
        self._key = private_key_pem
        self._api_url = api_url.rstrip("/")
        self._transport = transport or UrllibTransport()
        self._clock = clock
        self._tokens: dict[int, tuple[str, float]] = {}

    def __repr__(self) -> str:
        return f"AppAuth(app_id={self._app_id!r})"

    @classmethod
    def from_key_file(cls, app_id: str, key_path: str, **kwargs) -> AppAuth:
        pem = Path(key_path).read_text(encoding="utf-8")
        if "PRIVATE KEY" not in pem:
            raise ValueError("GITHUB_APP_PRIVATE_KEY_PATH does not contain a PEM private key")
        return cls(app_id, pem, **kwargs)

    def app_jwt(self) -> str:
        now = int(self._clock())
        # iat is backdated to tolerate clock drift; GitHub caps exp at 10 minutes.
        claims = {"iat": now - 60, "exp": now + 540, "iss": self._app_id}
        return jwt.encode(claims, self._key, algorithm="RS256")

    def headers(self, installation_id: int, budget: Budget) -> dict[str, str]:
        cached = self._tokens.get(installation_id)
        if cached and cached[1] - TOKEN_REFRESH_MARGIN_S > self._clock():
            return {"Authorization": f"Bearer {cached[0]}"}

        response = self._transport.request(
            "POST",
            f"{self._api_url}/app/installations/{installation_id}/access_tokens",
            {"Authorization": f"Bearer {self.app_jwt()}"},
            None,
            budget.timeout_s(10),
        )
        raise_for_status(response, f"an access token for installation {installation_id}")
        body = response.body if isinstance(response.body, dict) else {}
        token, expires_at = body.get("token"), body.get("expires_at")
        if not isinstance(token, str) or not isinstance(expires_at, str):
            raise GitHubError("GitHub returned an unexpected access-token response")
        expiry = datetime.fromisoformat(expires_at.replace("Z", "+00:00")).timestamp()
        self._tokens[installation_id] = (token, expiry)
        return {"Authorization": f"Bearer {token}"}
