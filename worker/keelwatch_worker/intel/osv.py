"""Known-vulnerability lookup through OSV.dev (https://osv.dev), a free,
open vulnerability database. Only exact versions are queried."""

from __future__ import annotations

from ..budget import Budget
from ..llm.http import Transport, UrllibTransport

BATCH_SIZE = 100
MIN_CALL_MS = 1500


class OsvUnavailable(Exception):
    pass


class OsvClient:
    def __init__(
        self, api_url: str = "https://api.osv.dev", transport: Transport | None = None
    ) -> None:
        self._url = api_url.rstrip("/") + "/v1/querybatch"
        self._transport = transport or UrllibTransport()
        self.requests_made = 0

    def vulnerabilities(
        self, packages: list[tuple[str, str, str]], budget: Budget
    ) -> dict[tuple[str, str, str], list[str]]:
        """Maps (ecosystem, name, version) -> advisory ids (empty if none known)."""
        results: dict[tuple[str, str, str], list[str]] = {}
        unique = list(dict.fromkeys(packages))
        for start in range(0, len(unique), BATCH_SIZE):
            batch = unique[start : start + BATCH_SIZE]
            if budget.remaining_ms() < MIN_CALL_MS:
                raise OsvUnavailable("no time left for a vulnerability lookup")
            payload = {
                "queries": [
                    {"package": {"ecosystem": eco, "name": name}, "version": version}
                    for eco, name, version in batch
                ]
            }
            try:
                self.requests_made += 1
                response = self._transport.post_json(self._url, {}, payload, budget.timeout_s(10))
            except Exception as exc:  # transport errors of any kind: treat as unavailable
                raise OsvUnavailable(f"{type(exc).__name__}: {exc}") from exc
            if response.status != 200 or not isinstance(response.body, dict):
                raise OsvUnavailable(f"OSV returned HTTP {response.status}")
            answers = response.body.get("results")
            if not isinstance(answers, list) or len(answers) != len(batch):
                raise OsvUnavailable("OSV returned an unexpected number of results")
            for key, answer in zip(batch, answers, strict=True):
                vulns = answer.get("vulns") if isinstance(answer, dict) else None
                ids = [
                    v["id"]
                    for v in vulns or []
                    if isinstance(v, dict) and isinstance(v.get("id"), str)
                ]
                results[key] = ids[:50]
        return results
