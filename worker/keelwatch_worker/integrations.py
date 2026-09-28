"""Builds the GitHub and OSV clients from environment variables.

GitHub: set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY_PATH to use a GitHub
App (reads private repositories it is installed on). With neither set, the
worker reads public repositories anonymously (60 requests/hour) and skips
private ones.
"""

from __future__ import annotations

import os
import urllib.parse
from collections.abc import Mapping

from .github.auth import AnonymousAuth, AppAuth
from .github.client import GitHubClient
from .intel.osv import OsvClient
from .notify.crypto import InvalidKey, parse_key
from .notify.jobs import NotificationConfig


class IntegrationConfigError(Exception):
    def __init__(self, errors: list[str]) -> None:
        self.errors = errors
        super().__init__("; ".join(errors))


def build_github(env: Mapping[str, str]) -> GitHubClient:
    app_id = (env.get("GITHUB_APP_ID") or "").strip()
    key_path = (env.get("GITHUB_APP_PRIVATE_KEY_PATH") or "").strip()
    api_url = (env.get("GITHUB_API_URL") or "https://api.github.com").strip()

    if not api_url.startswith("https://"):
        raise IntegrationConfigError(["GITHUB_API_URL must be an https:// URL"])
    if bool(app_id) != bool(key_path):
        raise IntegrationConfigError(
            ["GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY_PATH must be set together"]
        )
    if not app_id:
        return GitHubClient(AnonymousAuth(), api_url=api_url)
    if not app_id.isdigit() and not app_id.startswith("Iv"):
        raise IntegrationConfigError(["GITHUB_APP_ID must be the numeric App ID or its client ID"])
    if not os.path.isfile(key_path):
        raise IntegrationConfigError(["GITHUB_APP_PRIVATE_KEY_PATH does not point to a file"])
    try:
        auth = AppAuth.from_key_file(app_id, key_path, api_url=api_url)
    except (OSError, ValueError) as exc:
        raise IntegrationConfigError(
            [f"could not load the GitHub App key: {type(exc).__name__}"]
        ) from exc
    return GitHubClient(auth, api_url=api_url)


def build_notifications(env: Mapping[str, str]) -> NotificationConfig:
    """NOTIFICATION_KEY unset means notifications are off; digests are still stored."""
    errors: list[str] = []
    raw_key = (env.get("NOTIFICATION_KEY") or "").strip()
    key = None
    if raw_key:
        try:
            key = parse_key(raw_key)
        except InvalidKey as exc:
            errors.append(str(exc))

    dashboard = (env.get("DASHBOARD_URL") or "").strip().rstrip("/") or None
    if dashboard is not None:
        try:
            parts = urllib.parse.urlsplit(dashboard)
        except ValueError:
            parts = None
        local = (
            parts is not None
            and parts.scheme == "http"
            and parts.hostname in ("localhost", "127.0.0.1")
        )
        if parts is None or not (parts.scheme == "https" or local) or parts.username or parts.query:
            errors.append("DASHBOARD_URL must be an https:// URL (or http:// on localhost)")

    def int_between(name: str, default: int, low: int, high: int) -> int:
        raw = (env.get(name) or "").strip()
        if not raw:
            return default
        if not raw.isdigit() or not low <= int(raw) <= high:
            errors.append(f"{name} must be an integer between {low} and {high}")
            return default
        return int(raw)

    hour = int_between("DIGEST_DAILY_HOUR_UTC", 6, 0, 23)
    timeout = int_between("NOTIFY_TIMEOUT_S", 10, 1, 60)
    if errors:
        raise IntegrationConfigError(errors)
    return NotificationConfig(
        key=key, dashboard_url=dashboard, timeout_s=timeout, daily_hour_utc=hour
    )


def build_osv(env: Mapping[str, str]) -> OsvClient | None:
    enabled = (env.get("OSV_ENABLED") or "true").strip().lower()
    if enabled in ("0", "false", "no", "off"):
        return None
    api_url = (env.get("OSV_API_URL") or "https://api.osv.dev").strip()
    if not api_url.startswith("https://"):
        raise IntegrationConfigError(["OSV_API_URL must be an https:// URL"])
    return OsvClient(api_url)
