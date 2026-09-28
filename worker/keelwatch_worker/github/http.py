"""HTTP for the GitHub API, with typed errors.

Redirects are followed only to the same host (GitHub redirects renamed
repositories within api.github.com); anything else is refused so the
Authorization header can't be forwarded elsewhere.
"""

from __future__ import annotations

import json
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Protocol

MAX_RESPONSE_BYTES = 8 * 1024 * 1024


class GitHubError(Exception):
    retryable = False

    def __init__(self, message: str, status: int | None = None) -> None:
        super().__init__(message)
        self.status = status


class GitHubNotFound(GitHubError):
    """404/410, or 422 for an unknown commit: the thing is gone."""


class GitHubPermissionDenied(GitHubError):
    """The App or anonymous access can't read this."""


class GitHubRateLimited(GitHubError):
    retryable = True

    def __init__(self, message: str, retry_after_s: float | None) -> None:
        super().__init__(message, 429)
        self.retry_after_s = retry_after_s


class GitHubUnavailable(GitHubError):
    """Timeouts, connection failures, 5xx: may succeed later."""

    retryable = True


@dataclass(frozen=True)
class Response:
    status: int
    headers: dict[str, str]
    body: Any


class Transport(Protocol):
    def request(
        self,
        method: str,
        url: str,
        headers: dict[str, str],
        payload: dict[str, Any] | None,
        timeout_s: float,
    ) -> Response: ...


class _SameHostRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        old = urllib.parse.urlsplit(req.full_url)
        new = urllib.parse.urlsplit(urllib.parse.urljoin(req.full_url, newurl))
        if new.scheme != "https" or new.netloc != old.netloc:
            return None  # refused: surfaces as an HTTPError with the 3xx status
        return super().redirect_request(req, fp, code, msg, headers, newurl)


_OPENER = urllib.request.build_opener(_SameHostRedirects)


class UrllibTransport:
    def request(self, method, url, headers, payload, timeout_s) -> Response:
        if not url.startswith("https://"):
            raise GitHubError("GitHub API URLs must use HTTPS")
        if timeout_s <= 0:
            raise GitHubUnavailable("no time left for the request")
        data = json.dumps(payload).encode() if payload is not None else None
        req = urllib.request.Request(  # noqa: S310 - scheme checked above
            url,
            data=data,
            method=method,
            headers={
                "Accept": "application/vnd.github+json",
                "X-GitHub-Api-Version": "2022-11-28",
                "User-Agent": "keelwatch",
                **({"Content-Type": "application/json"} if data is not None else {}),
                **headers,
            },
        )
        try:
            with _OPENER.open(req, timeout=timeout_s) as resp:
                raw = resp.read(MAX_RESPONSE_BYTES + 1)
                status = resp.status
                resp_headers = {k.lower(): v for k, v in resp.headers.items()}
        except urllib.error.HTTPError as exc:
            raw = exc.read(64 * 1024)
            status = exc.code
            resp_headers = {k.lower(): v for k, v in (exc.headers or {}).items()}
        except TimeoutError as exc:
            raise GitHubUnavailable(f"GitHub request timed out after {timeout_s:.1f}s") from exc
        except urllib.error.URLError as exc:
            raise GitHubUnavailable(
                f"GitHub connection failed: {type(exc.reason).__name__}"
            ) from exc

        if len(raw) > MAX_RESPONSE_BYTES:
            raise GitHubError("GitHub response too large")
        try:
            body = json.loads(raw) if raw else None
        except json.JSONDecodeError:
            body = None
        return Response(status, resp_headers, body)


def raise_for_status(response: Response, what: str) -> None:
    status, headers = response.status, response.headers
    if 200 <= status < 300:
        return
    message = ""
    if isinstance(response.body, dict):
        message = str(response.body.get("message", ""))[:200]

    if status in (403, 429):
        retry_after = headers.get("retry-after")
        if headers.get("x-ratelimit-remaining") == "0" or retry_after is not None or status == 429:
            wait = None
            if retry_after is not None:
                try:
                    wait = float(retry_after)
                except ValueError:
                    wait = None
            elif headers.get("x-ratelimit-reset", "").isdigit():
                wait = max(0.0, int(headers["x-ratelimit-reset"]) - time.time())
            raise GitHubRateLimited(f"GitHub rate limit reached while fetching {what}", wait)
        raise GitHubPermissionDenied(f"no permission to read {what}: {message}", status)
    if status == 401:
        raise GitHubPermissionDenied(
            f"GitHub rejected the credentials while fetching {what}", status
        )
    if status in (404, 410):
        raise GitHubNotFound(f"{what} not found", status)
    if status == 422:
        # e.g. a compare against a commit that was force-pushed away
        raise GitHubNotFound(f"{what} is not available: {message}", status)
    if 300 <= status < 400:
        raise GitHubError(f"GitHub redirected {what} to another host; refused", status)
    if status >= 500:
        raise GitHubUnavailable(f"GitHub failed while fetching {what} (HTTP {status})", status)
    raise GitHubError(f"GitHub returned HTTP {status} for {what}: {message}", status)
