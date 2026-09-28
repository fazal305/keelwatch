"""Builds the GitHub and OSV clients from environment variables.

GitHub: set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY_PATH to use a GitHub
App (reads private repositories it is installed on). With neither set, the
worker reads public repositories anonymously (60 requests/hour) and skips
private ones.
"""

from __future__ import annotations

import os
from collections.abc import Mapping

from .github.auth import AnonymousAuth, AppAuth
from .github.client import GitHubClient
from .intel.osv import OsvClient


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


def build_osv(env: Mapping[str, str]) -> OsvClient | None:
    enabled = (env.get("OSV_ENABLED") or "true").strip().lower()
    if enabled in ("0", "false", "no", "off"):
        return None
    api_url = (env.get("OSV_API_URL") or "https://api.osv.dev").strip()
    if not api_url.startswith("https://"):
        raise IntegrationConfigError(["OSV_API_URL must be an https:// URL"])
    return OsvClient(api_url)
