import base64
import json
import socket
from pathlib import Path

import pytest

from keelwatch_worker.integrations import IntegrationConfigError, build_notifications
from keelwatch_worker.llm.base import ProviderError, ProviderTimeout
from keelwatch_worker.llm.http import HttpResponse
from keelwatch_worker.notify.crypto import DecryptError, InvalidKey, open_sealed, parse_key, seal
from keelwatch_worker.notify.destinations import (
    InvalidDestination,
    Sender,
    ensure_public,
    payload_for,
    validate_url,
)
from keelwatch_worker.notify.format import meets_threshold, render_daily, render_run

VECTOR = json.loads(
    (
        Path(__file__).resolve().parents[2]
        / "contracts"
        / "test-vectors"
        / "notification-url-encryption.v1.json"
    ).read_text(encoding="utf-8")
)
# Webhook-shaped test URLs are assembled at runtime; none is a real webhook.
SLACK_URL = "https://hooks.slack.com/services/" + "T0TEST/B0TEST/" + "x" * 24
DISCORD_URL = "https://discord.com/api/webhooks/" + "123456789/" + "t" * 40


def public_resolver(*_args, **_kwargs):
    return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("34.200.10.20", 443))]


# ----- encryption ----------------------------------------------------------------------


def test_matches_the_published_test_vector():
    key = base64.b64decode(VECTOR["key_base64"])
    blob = bytes.fromhex(VECTOR["blob_hex"])
    assert open_sealed(blob, key) == VECTOR["plaintext"]
    assert seal(VECTOR["plaintext"], key, bytes.fromhex(VECTOR["nonce_hex"])) == blob


def test_round_trip_uses_a_fresh_nonce_each_time():
    key = bytes(32)
    a, b = seal("url", key), seal("url", key)
    assert a != b
    assert open_sealed(a, key) == open_sealed(b, key) == "url"


def test_tampering_or_a_wrong_key_is_detected():
    key = bytes(32)
    blob = bytearray(seal("url", key))
    blob[-1] ^= 1
    with pytest.raises(DecryptError):
        open_sealed(bytes(blob), key)
    with pytest.raises(DecryptError):
        open_sealed(seal("url", key), bytes([1]) * 32)
    with pytest.raises(DecryptError):
        open_sealed(b"\x02" + bytes(40), key)


@pytest.mark.parametrize("raw", ["not base64!!", base64.b64encode(b"short").decode()])
def test_key_must_be_32_bytes_of_base64(raw):
    with pytest.raises(InvalidKey):
        parse_key(raw)


# ----- destination rules (SSRF) --------------------------------------------------------------


def test_valid_webhooks_are_accepted():
    assert validate_url("slack", SLACK_URL) == "hooks.slack.com"
    assert validate_url("discord", DISCORD_URL) == "discord.com"


@pytest.mark.parametrize(
    ("kind", "url"),
    [
        ("slack", "http://hooks.slack.com/services/T/B/x"),
        ("slack", "https://hooks.slack.com.evil.example/services/T/B/x"),
        ("slack", "https://evil@hooks.slack.com/services/T/B/x"),
        ("slack", "https://hooks.slack.com:8443/services/T/B/x"),
        ("slack", "https://hooks.slack.com/services/T/B/x?redirect=http://10.0.0.1"),
        ("slack", "https://hooks.slack.com/api/other"),
        ("slack", "https://169.254.169.254/latest/meta-data"),
        ("discord", "https://discord.com/api/webhooks/abc/token"),
        ("discord", "https://discord.com.evil.example/api/webhooks/1/t"),
        ("discord", SLACK_URL),
        ("teams", SLACK_URL),
    ],
)
def test_anything_else_is_refused(kind, url):
    with pytest.raises(InvalidDestination):
        validate_url(kind, url)


@pytest.mark.parametrize(
    "address", ["127.0.0.1", "10.1.2.3", "169.254.169.254", "192.168.0.5", "::1", "fd00::1"]
)
def test_hosts_resolving_to_private_addresses_are_refused(address):
    family = socket.AF_INET6 if ":" in address else socket.AF_INET

    def resolver(*_a, **_k):
        return [(family, socket.SOCK_STREAM, 6, "", (address, 443))]

    with pytest.raises(InvalidDestination, match="non-public"):
        ensure_public("hooks.slack.com", resolver)


def test_public_addresses_pass():
    assert ensure_public("hooks.slack.com", public_resolver) == ["34.200.10.20"]


# ----- payload safety -------------------------------------------------------------------


def test_discord_messages_can_never_ping_anyone():
    payload = payload_for("discord", "@everyone look " + "x" * 3000)
    assert payload["allowed_mentions"] == {"parse": []}
    assert len(payload["content"]) == 2000


def test_slack_control_sequences_are_escaped():
    payload = payload_for("slack", "<!channel> & <https://evil.example|click>")
    assert payload["text"] == "&lt;!channel&gt; &amp; &lt;https://evil.example|click&gt;"
    assert payload["unfurl_links"] is False


# ----- sending ----------------------------------------------------------------------------


class FakeTransport:
    def __init__(self, outcome):
        self.outcome = outcome
        self.calls = []

    def post_json(self, url, headers, payload, timeout_s):
        self.calls.append({"url": url, "payload": payload, "timeout_s": timeout_s})
        if isinstance(self.outcome, Exception):
            raise self.outcome
        return self.outcome


@pytest.mark.parametrize(
    ("outcome", "status"),
    [
        (HttpResponse(200, {}, None, "ok"), "sent"),
        (HttpResponse(204, {}, None, ""), "sent"),
        (HttpResponse(429, {"retry-after": "3"}, None, ""), "retry"),
        (HttpResponse(502, {}, None, ""), "retry"),
        (HttpResponse(404, {}, None, "no_service"), "failed"),
        (HttpResponse(403, {}, None, ""), "failed"),
        (ProviderTimeout("timed out"), "retry"),
        (ProviderError(f"refused redirect to {SLACK_URL}", retryable=False), "failed"),
    ],
)
def test_send_outcomes(outcome, status):
    result = Sender(FakeTransport(outcome), public_resolver).send("slack", SLACK_URL, "hi", 5)
    assert result.status == status
    assert "hooks.slack.com/services" not in (result.error or ""), (
        "errors must not echo the webhook URL"
    )


def test_send_refuses_before_any_request_when_the_host_is_private():
    transport = FakeTransport(HttpResponse(200, {}, None, ""))

    def private(*_a, **_k):
        return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("10.0.0.7", 443))]

    with pytest.raises(InvalidDestination):
        Sender(transport, private).send("slack", SLACK_URL, "hi", 5)
    assert transport.calls == []


# ----- message content ------------------------------------------------------------------------


def run_digest(private: bool) -> dict:
    return {
        "run_id": 7,
        "repository": {"id": 1, "full_name": "example-org/secret-project", "private": private},
        "trigger": {"type": "pull_request.opened", "pull_request": 42, "head_sha_short": "bbbbbbb"},
        "findings": {
            "total": 3,
            "by_severity": {"critical": 1, "low": 2},
            "new": 2,
            "recurring": 1,
            "top": [
                {
                    "severity": "critical",
                    "title": "Possible stripe key committed",
                    "file_path": "src/billing/pay.js",
                    "line": 11,
                    "source": "rule",
                },
                {
                    "severity": "low",
                    "title": "Model says: the refund(amount) call leaks",
                    "file_path": None,
                    "line": None,
                    "source": "llm",
                },
            ],
        },
        "gaps": ["known-vulnerability lookup was unavailable"],
    }


def test_public_run_message_lists_rule_findings_but_not_model_text():
    text = render_run(run_digest(private=False), "https://keelwatch.example.com")
    assert "example-org/secret-project · PR #42" in text
    assert "[critical] Possible stripe key committed — src/billing/pay.js:11" in text
    assert "Model says" not in text
    assert "Not checked: known-vulnerability lookup was unavailable" in text
    assert "https://keelwatch.example.com/runs/7" in text


def test_private_run_message_has_counts_only():
    text = render_run(run_digest(private=True), "https://keelwatch.example.com")
    assert "secret-project" not in text
    assert "src/billing" not in text
    assert "stripe" not in text.lower()
    assert "1 critical, 2 low" in text
    assert "a private repository" in text


def test_messages_pass_through_redaction():
    digest = run_digest(private=False)
    digest["findings"]["top"][0]["title"] = "Leaked contact octo.dev@example.com"
    assert "octo.dev@example.com" not in render_run(digest, None)


def test_daily_message_hides_private_repositories():
    digest = {
        "period": {"date": "2026-09-27"},
        "totals": {"runs": 3, "failed_runs": 0, "by_severity": {"high": 1}, "new_findings": 1},
        "repositories": [
            {
                "full_name": "example-org/public-site",
                "private": False,
                "runs": 2,
                "by_severity": {"high": 1},
            },
            {
                "full_name": "example-org/internal-billing",
                "private": True,
                "runs": 1,
                "by_severity": {},
            },
        ],
    }
    text = render_daily(digest, None)
    assert "example-org/public-site" in text
    assert "internal-billing" not in text
    assert "1 private repository" in text


@pytest.mark.parametrize(
    ("counts", "minimum", "expected"),
    [
        ({"critical": 1}, "high", True),
        ({"medium": 2}, "high", False),
        ({"medium": 2}, "medium", True),
        ({}, "info", False),
        ({"info": 1}, "info", True),
    ],
)
def test_severity_threshold(counts, minimum, expected):
    assert meets_threshold(counts, minimum) is expected


# ----- configuration -------------------------------------------------------------------------


def test_notifications_are_off_without_a_key():
    config = build_notifications({})
    assert config.enabled is False
    assert repr(config) == "NotificationConfig(enabled=False, dashboard_url=None)"


def test_notification_config_is_validated():
    good = base64.b64encode(bytes(32)).decode()
    config = build_notifications(
        {"NOTIFICATION_KEY": good, "DASHBOARD_URL": "https://kw.example.com/"}
    )
    assert config.enabled
    assert config.dashboard_url == "https://kw.example.com"
    assert good not in repr(config)
    for env in (
        {"NOTIFICATION_KEY": "short"},
        {"DASHBOARD_URL": "http://kw.example.com"},
        {"DASHBOARD_URL": "https://user:pass@kw.example.com"},
        {"DIGEST_DAILY_HOUR_UTC": "25"},
    ):
        with pytest.raises(IntegrationConfigError):
            build_notifications(env)
