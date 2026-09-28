import base64
from datetime import UTC, datetime

import jwt
import pytest
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa

from keelwatch_worker.budget import Budget
from keelwatch_worker.github.auth import AnonymousAuth, AppAuth
from keelwatch_worker.github.client import GitHubClient
from keelwatch_worker.github.http import (
    GitHubError,
    GitHubNotFound,
    GitHubPermissionDenied,
    GitHubRateLimited,
    GitHubUnavailable,
    Response,
)
from keelwatch_worker.integrations import IntegrationConfigError, build_github, build_osv
from keelwatch_worker.intel.osv import OsvClient, OsvUnavailable
from keelwatch_worker.llm.http import HttpResponse

API = "https://api.github.com"
REPO = "example-org/example-repo"
SHA_A, SHA_B = "a" * 40, "b" * 40


@pytest.fixture(scope="module")
def rsa_key():
    """A throwaway key generated per test session; no key is committed."""
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    pem = key.private_bytes(
        serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()
    ).decode()
    return pem, key.public_key()


class FakeGitHub:
    """Routes requests by method and URL to scripted responses."""

    def __init__(self, routes):
        self.routes = routes
        self.calls = []

    def request(self, method, url, headers, payload, timeout_s):
        self.calls.append(
            {"method": method, "url": url, "headers": headers, "timeout_s": timeout_s}
        )
        for (m, prefix), response in self.routes.items():
            if m == method and url.startswith(prefix):
                return response(url) if callable(response) else response
        return Response(404, {}, {"message": "Not Found"})


def budget(ms=60_000):
    return Budget(ms, reserve_ms=0)


# ----- App authentication ----------------------------------------------------------------


def test_app_jwt_is_rs256_with_bounded_lifetime(rsa_key):
    pem, public = rsa_key
    auth = AppAuth("123456", pem, clock=lambda: 1_800_000_000)
    # The fixed test clock differs from real time, so skip only the time-window checks;
    # the RS256 signature is still verified against the public key.
    claims = jwt.decode(
        auth.app_jwt(),
        public,
        algorithms=["RS256"],
        options={"verify_exp": False, "verify_iat": False, "verify_nbf": False},
    )
    assert claims == {"iat": 1_800_000_000 - 60, "exp": 1_800_000_000 + 540, "iss": "123456"}


def test_installation_token_is_fetched_once_and_refreshed_near_expiry(rsa_key):
    pem, _ = rsa_key
    now = [1_800_000_000.0]
    tokens = iter(["tok-1", "tok-2"])

    def issue(_url):
        return Response(201, {}, {"token": next(tokens), "expires_at": "2027-01-15T09:00:00Z"})

    transport = FakeGitHub({("POST", f"{API}/app/installations/42/access_tokens"): issue})
    auth = AppAuth("123456", pem, transport=transport, clock=lambda: now[0])

    assert auth.headers(42, budget()) == {"Authorization": "Bearer tok-1"}
    assert auth.headers(42, budget()) == {"Authorization": "Bearer tok-1"}
    assert len(transport.calls) == 1, "cached"
    assert transport.calls[0]["headers"]["Authorization"].startswith("Bearer ey")

    expiry = datetime(2027, 1, 15, 9, 0, tzinfo=UTC).timestamp()
    now[0] = expiry - 301  # just outside the 5-minute refresh margin: still cached
    assert auth.headers(42, budget()) == {"Authorization": "Bearer tok-1"}
    now[0] = expiry - 299  # inside the margin: refreshed
    assert auth.headers(42, budget()) == {"Authorization": "Bearer tok-2"}
    assert len(transport.calls) == 2


def test_repr_hides_the_key(rsa_key):
    pem, _ = rsa_key
    assert "PRIVATE" not in repr(AppAuth("1", pem))


# ----- client ---------------------------------------------------------------------------


def test_pr_files_follow_same_host_pagination_and_report_truncation():
    page = lambda n: [{"filename": f"f{n}-{i}.py", "status": "added"} for i in range(2)]  # noqa: E731
    routes = {
        ("GET", f"{API}/repos/{REPO}/pulls/7/files?per_page=2&page=2"): Response(
            200,
            {"link": f'<{API}/repos/{REPO}/pulls/7/files?per_page=2&page=3>; rel="next"'},
            page(2),
        ),
        ("GET", f"{API}/repos/{REPO}/pulls/7/files?per_page=2"): Response(
            200,
            {"link": f'<{API}/repos/{REPO}/pulls/7/files?per_page=2&page=2>; rel="next"'},
            page(1),
        ),
    }
    transport = FakeGitHub(routes)
    client = GitHubClient(AnonymousAuth(), transport, per_page=2, max_pages=2)

    files, truncated = client.pr_files(REPO, 7, 1, budget())

    assert [f["filename"] for f in files] == ["f1-0.py", "f1-1.py", "f2-0.py", "f2-1.py"]
    assert truncated is True
    assert len(transport.calls) == 2


def test_pagination_never_follows_a_link_to_another_host():
    routes = {
        ("GET", f"{API}/repos/{REPO}/pulls/7/files"): Response(
            200,
            {"link": '<https://evil.example/steal?page=2>; rel="next"'},
            [{"filename": "a.py", "status": "added"}],
        )
    }
    transport = FakeGitHub(routes)
    files, truncated = GitHubClient(AnonymousAuth(), transport).pr_files(REPO, 7, 1, budget())
    assert len(files) == 1 and truncated is False
    assert all(c["url"].startswith(API) for c in transport.calls)


@pytest.mark.parametrize(
    ("response", "error"),
    [
        (
            Response(403, {"x-ratelimit-remaining": "0", "x-ratelimit-reset": "9999999999"}, {}),
            GitHubRateLimited,
        ),
        (Response(429, {"retry-after": "30"}, {}), GitHubRateLimited),
        (
            Response(403, {}, {"message": "Resource not accessible by integration"}),
            GitHubPermissionDenied,
        ),
        (Response(401, {}, {}), GitHubPermissionDenied),
        (Response(404, {}, {}), GitHubNotFound),
        (Response(422, {}, {"message": "No commit found for SHA"}), GitHubNotFound),
        (Response(502, {}, {}), GitHubUnavailable),
        (Response(301, {"location": "https://evil.example"}, {}), GitHubError),
    ],
    ids=[
        "primary-rate-limit",
        "secondary-rate-limit",
        "403",
        "401",
        "404",
        "422",
        "502",
        "cross-host-redirect",
    ],
)
def test_http_statuses_become_typed_errors(response, error):
    client = GitHubClient(AnonymousAuth(), FakeGitHub({("GET", API): response}))
    with pytest.raises(error):
        client.compare(REPO, SHA_A, SHA_B, 1, budget())


def test_rate_limit_carries_retry_after():
    client = GitHubClient(
        AnonymousAuth(), FakeGitHub({("GET", API): Response(429, {"retry-after": "30"}, {})})
    )
    with pytest.raises(GitHubRateLimited) as caught:
        client.commit_files(REPO, SHA_B, 1, budget())
    assert caught.value.retry_after_s == 30


def test_file_content_decodes_and_handles_absent_or_large_files():
    body = {
        "type": "file",
        "encoding": "base64",
        "size": 20,
        "content": base64.b64encode(b'{"name": "app"}').decode(),
    }
    routes = {
        ("GET", f"{API}/repos/{REPO}/contents/package.json?ref={SHA_A}"): Response(200, {}, body),
        ("GET", f"{API}/repos/{REPO}/contents/big.json"): Response(
            200, {}, {**body, "size": 10**7}
        ),
    }
    client = GitHubClient(AnonymousAuth(), FakeGitHub(routes))
    assert client.file_content(REPO, "package.json", SHA_A, 1, budget()) == '{"name": "app"}'
    assert client.file_content(REPO, "missing.json", SHA_A, 1, budget()) is None
    assert client.file_content(REPO, "big.json", SHA_A, 1, budget()) is None


@pytest.mark.parametrize(
    "call",
    [
        lambda c: c.file_content(REPO, "../etc/passwd", SHA_A, 1, budget()),
        lambda c: c.file_content(REPO, "/abs", SHA_A, 1, budget()),
        lambda c: c.compare(REPO, "HEAD", SHA_B, 1, budget()),
        lambda c: c.compare("../../x", SHA_A, SHA_B, 1, budget()),
    ],
    ids=["traversal", "absolute", "non-sha-ref", "bad-repo-name"],
)
def test_unsafe_inputs_are_refused_before_any_request(call):
    transport = FakeGitHub({})
    with pytest.raises(GitHubError):
        call(GitHubClient(AnonymousAuth(), transport))
    assert transport.calls == []


def test_requests_respect_the_budget():
    transport = FakeGitHub({("GET", API): Response(200, {}, {"files": []})})
    client = GitHubClient(AnonymousAuth(), transport)
    client.compare(REPO, SHA_A, SHA_B, 1, Budget(4000, reserve_ms=0))
    assert transport.calls[0]["timeout_s"] <= 4


# ----- OSV -----------------------------------------------------------------------------


class FakeOsv:
    def __init__(self, response):
        self.response = response
        self.payloads = []

    def post_json(self, url, headers, payload, timeout_s):
        self.payloads.append(payload)
        if isinstance(self.response, Exception):
            raise self.response
        return self.response


def test_osv_maps_answers_back_to_packages():
    transport = FakeOsv(
        HttpResponse(200, {}, {"results": [{"vulns": [{"id": "GHSA-1"}, {"id": "CVE-2"}]}, {}]}, "")
    )
    result = OsvClient(transport=transport).vulnerabilities(
        [("npm", "lodash", "4.17.15"), ("PyPI", "django", "5.1.2"), ("npm", "lodash", "4.17.15")],
        budget(),
    )
    assert result == {
        ("npm", "lodash", "4.17.15"): ["GHSA-1", "CVE-2"],
        ("PyPI", "django", "5.1.2"): [],
    }
    assert len(transport.payloads[0]["queries"]) == 2, "duplicates are queried once"


@pytest.mark.parametrize(
    "response",
    [
        HttpResponse(503, {}, None, ""),
        HttpResponse(200, {}, {"results": []}, ""),
        ConnectionError("down"),
    ],
    ids=["503", "wrong-count", "network"],
)
def test_osv_failures_are_reported_as_unavailable(response):
    with pytest.raises(OsvUnavailable):
        OsvClient(transport=FakeOsv(response)).vulnerabilities([("npm", "x", "1.0.0")], budget())


# ----- configuration ------------------------------------------------------------------


def test_github_defaults_to_anonymous_public_access():
    client = build_github({})
    assert isinstance(client.auth, AnonymousAuth)
    assert client.auth.can_read_private is False


def test_github_app_from_key_file(tmp_path, rsa_key):
    pem, _ = rsa_key
    key = tmp_path / "app.pem"
    key.write_text(pem, encoding="utf-8")
    client = build_github({"GITHUB_APP_ID": "123456", "GITHUB_APP_PRIVATE_KEY_PATH": str(key)})
    assert isinstance(client.auth, AppAuth)


@pytest.mark.parametrize(
    ("env", "message"),
    [
        ({"GITHUB_APP_ID": "123"}, "must be set together"),
        (
            {"GITHUB_APP_ID": "123", "GITHUB_APP_PRIVATE_KEY_PATH": "C:/nope/missing.pem"},
            "does not point to a file",
        ),
        ({"GITHUB_API_URL": "http://api.github.com"}, "https://"),
    ],
)
def test_github_config_errors(env, message):
    with pytest.raises(IntegrationConfigError, match=message):
        build_github(env)


def test_a_non_key_file_is_refused(tmp_path):
    bogus = tmp_path / "not-a-key.pem"
    bogus.write_text("hello", encoding="utf-8")
    with pytest.raises(IntegrationConfigError, match="could not load"):
        build_github({"GITHUB_APP_ID": "1", "GITHUB_APP_PRIVATE_KEY_PATH": str(bogus)})


def test_osv_can_be_disabled():
    assert build_osv({"OSV_ENABLED": "false"}) is None
    assert isinstance(build_osv({}), OsvClient)
    with pytest.raises(IntegrationConfigError):
        build_osv({"OSV_API_URL": "http://api.osv.dev"})
