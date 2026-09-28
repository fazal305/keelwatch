import pytest

from keelwatch_worker.budget import Budget, BudgetExceeded
from keelwatch_worker.redaction import redact


class FakeClock:
    def __init__(self) -> None:
        self.now = 0.0

    def __call__(self) -> float:
        return self.now

    def advance_ms(self, ms: float) -> None:
        self.now += ms / 1000


# ----- budget --------------------------------------------------------------------


def test_budget_reserves_time_for_the_checkpoint():
    clock = FakeClock()
    b = Budget(10_000, reserve_ms=2000, clock=clock)
    assert b.remaining_ms() == 8000
    clock.advance_ms(3000)
    assert b.remaining_ms() == 5000
    clock.advance_ms(6000)
    assert b.remaining_ms() == 0
    assert b.expired()


def test_reserve_never_exceeds_half_the_budget():
    assert Budget(1000, reserve_ms=5000, clock=FakeClock()).reserve_ms == 500


def test_child_budget_is_capped_by_phase_and_by_what_is_left():
    clock = FakeClock()
    b = Budget(10_000, reserve_ms=1000, clock=clock)
    assert b.child(4000).total_ms == 4000
    clock.advance_ms(7000)
    assert b.child(4000).total_ms == 2000
    clock.advance_ms(2000)
    with pytest.raises(BudgetExceeded):
        b.child(4000)


def test_network_timeouts_never_outlive_the_budget():
    clock = FakeClock()
    b = Budget(10_000, reserve_ms=0, clock=clock)
    assert b.timeout_s(30) == 10
    clock.advance_ms(9500)
    assert b.timeout_s(30) == pytest.approx(0.5)


def test_require_raises_when_work_cannot_fit():
    clock = FakeClock()
    b = Budget(5000, reserve_ms=0, clock=clock)
    b.require(4000, "call")
    clock.advance_ms(2000)
    with pytest.raises(BudgetExceeded, match="call needs"):
        b.require(4000, "call")


def test_budget_must_be_positive():
    with pytest.raises(ValueError):
        Budget(0)


# ----- redaction -------------------------------------------------------------------
# Credential-shaped values are assembled at runtime so none sits in the repo.


def token(prefix: str, body_char: str, length: int) -> str:
    return prefix + body_char * length


@pytest.mark.parametrize(
    ("secret", "kind"),
    [
        (token("gh" + "p_", "A", 36), "github_token"),
        (token("github" + "_pat_", "B", 60), "github_token"),
        (token("AK" + "IA", "Q", 16), "aws_access_key"),
        (token("AI" + "za", "x", 35), "google_api_key"),
        (token("xo" + "xb-", "1", 20), "slack_token"),
        (token("gs" + "k_", "z", 40), "groq_key"),
        (token("sk" + "-proj-", "k", 30), "openai_style_key"),
        (token("sk" + "_live_", "9", 24), "stripe_key"),
        ("ey" + "J" + "a" * 12 + ".ey" + "J" + "b" * 12 + "." + "c" * 12, "jwt"),
        ("octo.dev@example.com", "email"),
    ],
)
def test_known_credential_formats_are_masked(secret, kind):
    result = redact(f"const value = call({secret!r});")
    assert secret not in result.text
    assert result.findings.get(kind) == 1


def test_private_key_block_is_masked_whole():
    block = "-----BEGIN " + "RSA PRIVATE KEY-----\nMIIabc\ndef\n-----END " + "RSA PRIVATE KEY-----"
    result = redact(f"key = '''{block}'''")
    assert "MIIabc" not in result.text
    assert result.findings["private_key"] == 1


def test_credentials_in_urls_are_masked_but_the_host_is_kept():
    result = redact("DATABASE_URL=postgres://admin:hunter2secret@db.internal:5432/app")
    assert "hunter2secret" not in result.text
    assert "db.internal:5432/app" in result.text


def test_assignment_style_secrets_are_masked():
    result = redact(
        "db_password = \"s3cr3t-Value!\"\nAPI_KEY=abc123def456\nclient_secret: 'qwerty12345'"
    )
    for value in ("s3cr3t-Value!", "abc123def456", "qwerty12345"):
        assert value not in result.text
    assert result.findings["assigned_secret"] == 3
    assert 'db_password = "[REDACTED:assigned_secret]"' in result.text


def test_placeholders_and_env_lookups_are_not_masked():
    text = 'password = "changeme"\napi_key = process.env.API_KEY\ntoken = "<your-token>"'
    result = redact(text)
    assert result.count == 0
    assert result.text == text


def test_ordinary_code_is_left_alone():
    code = "function add(a, b) {\n  return a + b; // tokenizer handles this\n}\n"
    assert redact(code).text == code
