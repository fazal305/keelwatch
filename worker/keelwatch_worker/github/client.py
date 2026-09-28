"""The few GitHub reads analysis needs: PR files, push comparisons, and
individual file contents. Nothing is cloned; every call is bounded by the
run's budget and by page and size limits."""

from __future__ import annotations

import base64
import re
import urllib.parse
from typing import Any

from ..budget import Budget, BudgetExceeded
from .auth import AnonymousAuth, AppAuth
from .http import (
    GitHubError,
    GitHubNotFound,
    Response,
    Transport,
    UrllibTransport,
    raise_for_status,
)

_FULL_NAME = re.compile(r"[A-Za-z0-9-]+/[A-Za-z0-9._-]+")
_SHA = re.compile(r"[0-9a-f]{40}(?:[0-9a-f]{24})?")
_NEXT_LINK = re.compile(r'<([^>]+)>;\s*rel="next"')
REQUEST_TIMEOUT_S = 10.0
MIN_REQUEST_MS = 1000


class GitHubClient:
    def __init__(
        self,
        auth: AppAuth | AnonymousAuth,
        transport: Transport | None = None,
        api_url: str = "https://api.github.com",
        per_page: int = 100,
        max_pages: int = 3,
    ) -> None:
        self.auth = auth
        self._transport = transport or UrllibTransport()
        self._api_url = api_url.rstrip("/")
        self._per_page = per_page
        self._max_pages = max_pages
        self.requests_made = 0

    # ----- public reads ------------------------------------------------------------

    def pr_files(
        self, full_name: str, number: int, installation_id: int, budget: Budget
    ) -> tuple[list[dict[str, Any]], bool]:
        """Returns (files, truncated). GitHub lists at most 3000 files per PR."""
        self._check_name(full_name)
        return self._paginate(
            f"/repos/{full_name}/pulls/{int(number)}/files",
            installation_id,
            budget,
            "pull request files",
        )

    def compare(
        self, full_name: str, base: str, head: str, installation_id: int, budget: Budget
    ) -> tuple[list[dict[str, Any]], bool]:
        self._check_name(full_name)
        self._check_sha(base)
        self._check_sha(head)
        body, _ = self._get(
            f"/repos/{full_name}/compare/{base}...{head}",
            installation_id,
            budget,
            "a commit comparison",
        )
        files = body.get("files") if isinstance(body, dict) else None
        files = files if isinstance(files, list) else []
        # The compare API returns at most 300 files.
        return files, len(files) >= 300

    def commit_files(
        self, full_name: str, sha: str, installation_id: int, budget: Budget
    ) -> tuple[list[dict[str, Any]], bool]:
        self._check_name(full_name)
        self._check_sha(sha)
        body, _ = self._get(
            f"/repos/{full_name}/commits/{sha}", installation_id, budget, "a commit"
        )
        files = body.get("files") if isinstance(body, dict) else None
        files = files if isinstance(files, list) else []
        return files, len(files) >= 300

    def file_content(
        self,
        full_name: str,
        path: str,
        ref: str,
        installation_id: int,
        budget: Budget,
        max_bytes: int = 512 * 1024,
    ) -> str | None:
        """Text content of one file at a commit, or None if it doesn't exist there."""
        self._check_name(full_name)
        self._check_sha(ref)
        if path.startswith("/") or ".." in path.split("/"):
            raise GitHubError("refusing an unsafe repository path")
        quoted = urllib.parse.quote(path, safe="/")
        try:
            body, _ = self._get(
                f"/repos/{full_name}/contents/{quoted}?ref={ref}",
                installation_id,
                budget,
                f"file {path}",
            )
        except GitHubNotFound:
            return None
        if not isinstance(body, dict) or body.get("type") != "file":
            return None
        if int(body.get("size") or 0) > max_bytes or body.get("encoding") != "base64":
            return None
        try:
            return base64.b64decode(body.get("content") or "").decode("utf-8")
        except (ValueError, UnicodeDecodeError):
            return None

    # ----- plumbing ---------------------------------------------------------------

    def _get(self, path: str, installation_id: int, budget: Budget, what: str) -> tuple[Any, dict]:
        response = self._request(f"{self._api_url}{path}", installation_id, budget)
        raise_for_status(response, what)
        return response.body, response.headers

    def _request(self, url: str, installation_id: int, budget: Budget) -> Response:
        if budget.remaining_ms() < MIN_REQUEST_MS:
            raise BudgetExceeded("no time left for a GitHub request")
        headers = self.auth.headers(installation_id, budget)
        self.requests_made += 1
        return self._transport.request(
            "GET", url, headers, None, budget.timeout_s(REQUEST_TIMEOUT_S)
        )

    def _paginate(self, path: str, installation_id: int, budget: Budget, what: str):
        sep = "&" if "?" in path else "?"
        url: str | None = f"{self._api_url}{path}{sep}per_page={self._per_page}"
        items: list[dict[str, Any]] = []
        pages = 0
        while url is not None:
            if pages >= self._max_pages:
                return items, True
            response = self._request(url, installation_id, budget)
            raise_for_status(response, what)
            pages += 1
            if isinstance(response.body, list):
                items.extend(i for i in response.body if isinstance(i, dict))
            url = self._next_link(response.headers.get("link", ""))
        return items, False

    def _next_link(self, header: str) -> str | None:
        match = _NEXT_LINK.search(header)
        if not match:
            return None
        nxt = match.group(1)
        # Only ever follow pagination links back to the same API host.
        return nxt if nxt.startswith(self._api_url + "/") else None

    @staticmethod
    def _check_name(full_name: str) -> None:
        if not _FULL_NAME.fullmatch(full_name):
            raise GitHubError("invalid repository name")

    @staticmethod
    def _check_sha(sha: str) -> None:
        if not _SHA.fullmatch(sha):
            raise GitHubError("invalid commit SHA")
