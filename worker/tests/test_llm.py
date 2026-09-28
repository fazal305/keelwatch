import json

import pytest

from keelwatch_worker.budget import Budget
from keelwatch_worker.llm.base import (
    AnalysisContext,
    Constraints,
    ProviderError,
    ProviderRateLimited,
    ProviderRejected,
    ProviderResult,
    ProviderTimeout,
    TaskSpec,
)
from keelwatch_worker.llm.config import LLMConfigError, build_providers
from keelwatch_worker.llm.http import HttpResponse, is_allowed_url
from keelwatch_worker.llm.providers import GeminiProvider, OpenAICompatibleProvider, groq_provider
from keelwatch_worker.llm.router import CircuitBreaker, Router, eligible

# Test-only credential placeholder, assembled at runtime.
KEY = "test-" + "k" * 24
TASK = TaskSpec("review_change", "Review it.", '{"summary": "..."}')
PUBLIC = AnalysisContext("example-org/example-repo", False, "def f():\n    return 1\n")
PRIVATE = AnalysisContext("example-org/internal", True, "secret sauce")


class FakeTransport:
    def __init__(self, response: HttpResponse) -> None:
        self.response = response
        self.calls: list[dict] = []

    def post_json(self, url, headers, payload, timeout_s):
        self.calls.append(
            {"url": url, "headers": headers, "payload": payload, "timeout_s": timeout_s}
        )
        return self.response


def ok_openai(content: dict) -> HttpResponse:
    body = {
        "choices": [{"message": {"content": json.dumps(content)}, "finish_reason": "stop"}],
        "usage": {"prompt_tokens": 120, "completion_tokens": 30},
    }
    return HttpResponse(200, {}, body, "")


def ok_gemini(text: str) -> HttpResponse:
    body = {
        "candidates": [{"content": {"parts": [{"text": text}]}, "finishReason": "STOP"}],
        "usageMetadata": {"promptTokenCount": 90, "candidatesTokenCount": 20},
    }
    return HttpResponse(200, {}, body, "")


NOT_JSON = HttpResponse(200, {}, {"choices": [{"message": {"content": "not json"}}]}, "")


# ----- request shapes -----------------------------------------------------------------


def test_groq_request_shape_and_result():
    t = FakeTransport(ok_openai({"summary": "fine"}))
    result = groq_provider(KEY, "llama-3.3-70b-versatile", "may_train", t).analyze(
        PUBLIC, TASK, Constraints(timeout_s=12, max_output_tokens=256)
    )

    call = t.calls[0]
    assert call["url"] == "https://api.groq.com/openai/v1/chat/completions"
    assert call["headers"] == {"Authorization": f"Bearer {KEY}"}
    assert call["timeout_s"] == 12
    payload = call["payload"]
    assert payload["model"] == "llama-3.3-70b-versatile"
    assert payload["response_format"] == {"type": "json_object"}
    assert payload["max_completion_tokens"] == 256
    assert "max_tokens" not in payload, "Groq deprecated max_tokens"
    assert payload["messages"][0]["role"] == "system"
    assert "<context" in payload["messages"][1]["content"]

    assert result.content == {"summary": "fine"}
    assert (result.provider, result.tokens_in, result.tokens_out) == ("groq", 120, 30)


def test_gemini_keeps_the_key_out_of_the_url():
    t = FakeTransport(ok_gemini('{"summary": "ok"}'))
    result = GeminiProvider(KEY, "gemini-2.5-flash", "may_train", t).analyze(
        PUBLIC, TASK, Constraints(timeout_s=5)
    )

    call = t.calls[0]
    assert call["url"].endswith("/models/gemini-2.5-flash:generateContent")
    assert KEY not in call["url"]
    assert call["headers"] == {"x-goog-api-key": KEY}
    assert call["payload"]["generationConfig"]["responseMimeType"] == "application/json"
    assert result.content == {"summary": "ok"}
    assert (result.tokens_in, result.tokens_out) == (90, 20)


def test_fenced_json_answers_are_accepted():
    t = FakeTransport(ok_gemini('```json\n{"summary": "fenced"}\n```'))
    result = GeminiProvider(KEY, "gemini-2.5-flash", "may_train", t).analyze(
        PUBLIC, TASK, Constraints(5)
    )
    assert result.content == {"summary": "fenced"}


def test_provider_repr_never_shows_the_key():
    assert KEY not in repr(groq_provider(KEY, "m", "may_train"))
    assert KEY not in repr(GeminiProvider(KEY, "gemini-2.5-flash", "may_train"))


# ----- error mapping ------------------------------------------------------------------


@pytest.mark.parametrize(
    ("response", "error", "retryable"),
    [
        (HttpResponse(429, {"retry-after": "5"}, None, ""), ProviderRateLimited, True),
        (HttpResponse(401, {}, None, ""), ProviderRejected, False),
        (HttpResponse(400, {}, None, "bad"), ProviderRejected, False),
        (
            HttpResponse(302, {"location": "https://evil.example"}, None, ""),
            ProviderRejected,
            False,
        ),
        (HttpResponse(503, {}, None, ""), ProviderError, True),
        (HttpResponse(200, {}, {"choices": []}, ""), ProviderRejected, False),
        (NOT_JSON, ProviderRejected, False),
    ],
    ids=["429", "401", "400", "302", "503", "empty-choices", "not-json"],
)
def test_http_outcomes_map_to_typed_errors(response, error, retryable):
    provider = groq_provider(KEY, "m", "may_train", FakeTransport(response))
    with pytest.raises(error) as caught:
        provider.analyze(PUBLIC, TASK, Constraints(5))
    assert caught.value.retryable is retryable


def test_rate_limit_carries_retry_after():
    limited = HttpResponse(429, {"retry-after": "7"}, None, "")
    provider = groq_provider(KEY, "m", "may_train", FakeTransport(limited))
    with pytest.raises(ProviderRateLimited) as caught:
        provider.analyze(PUBLIC, TASK, Constraints(5))
    assert caught.value.retry_after_s == 7.0


def test_gemini_blocked_prompt_is_rejected():
    blocked = HttpResponse(
        200, {}, {"promptFeedback": {"blockReason": "SAFETY"}, "candidates": []}, ""
    )
    provider = GeminiProvider(KEY, "gemini-2.5-flash", "may_train", FakeTransport(blocked))
    with pytest.raises(ProviderRejected, match="SAFETY"):
        provider.analyze(PUBLIC, TASK, Constraints(5))


# ----- URL rules --------------------------------------------------------------------


@pytest.mark.parametrize(
    ("url", "allowed"),
    [
        ("https://api.groq.com/openai/v1", True),
        ("http://localhost:20128/v1", True),
        ("http://127.0.0.1:20128/v1", True),
        ("http://example.com/v1", False),
        ("http://localhost:20128@evil.example/v1", False),
        ("https://user:pass@api.example.com/v1", False),
        ("http://localhost.evil.example/v1", False),
        ("ftp://localhost/v1", False),
        ("https://api.example.com:notaport/v1", False),
    ],
)
def test_only_https_or_loopback_http_urls_are_allowed(url, allowed):
    assert is_allowed_url(url) is allowed


# ----- configuration -------------------------------------------------------------------


def test_empty_route_means_no_llm():
    assert build_providers({}) == []
    assert build_providers({"LLM_ROUTE": "  "}) == []


def test_route_builds_providers_in_order_with_conservative_defaults():
    providers = build_providers(
        {
            "LLM_ROUTE": "gemini:gemini-2.5-flash, groq:llama-3.3-70b-versatile",
            "GEMINI_API_KEY": KEY,
            "GROQ_API_KEY": KEY,
            "GROQ_DATA_POLICY": "no_training",
        }
    )
    assert [(p.name, p.model, p.data_policy) for p in providers] == [
        ("gemini", "gemini-2.5-flash", "may_train"),
        ("groq", "llama-3.3-70b-versatile", "no_training"),
    ]


GATEWAY = {"LLM_ROUTE": "openai_compatible:auto", "OPENAI_COMPATIBLE_API_KEY": KEY}


@pytest.mark.parametrize(
    ("env", "message"),
    [
        ({"LLM_ROUTE": "openrouter:x"}, "unknown provider"),
        ({"LLM_ROUTE": "groq"}, "needs a model"),
        ({"LLM_ROUTE": "groq:m"}, "GROQ_API_KEY is required"),
        ({"LLM_ROUTE": "groq:m,groq:n", "GROQ_API_KEY": KEY}, "listed twice"),
        (
            {"LLM_ROUTE": "groq:m", "GROQ_API_KEY": KEY, "GROQ_DATA_POLICY": "trust-me"},
            "must be no_training or may_train",
        ),
        (
            {**GATEWAY, "OPENAI_COMPATIBLE_BASE_URL": "http://gateway.example.com/v1"},
            "OPENAI_COMPATIBLE_BASE_URL",
        ),
        (
            {
                **GATEWAY,
                "OPENAI_COMPATIBLE_BASE_URL": "http://localhost:20128/v1",
                "OPENAI_COMPATIBLE_DATA_POLICY": "no_training",
            },
            "cannot be no_training for a gateway",
        ),
    ],
)
def test_bad_configuration_is_reported(env, message):
    with pytest.raises(LLMConfigError, match=message):
        build_providers(env)


def test_local_gateway_is_allowed_but_never_trusted():
    env = {**GATEWAY, "OPENAI_COMPATIBLE_BASE_URL": "http://localhost:20128/v1"}
    [gateway] = build_providers(env)
    assert isinstance(gateway, OpenAICompatibleProvider)
    assert gateway.data_policy == "may_train"


# ----- privacy policy (ADR 0003) ----------------------------------------------------------


class FakeProvider:
    def __init__(self, name, outcomes, data_policy="may_train", model="m"):
        self.name = name
        self.model = model
        self.data_policy = data_policy
        self.outcomes = list(outcomes)
        self.calls = 0

    def analyze(self, context, task, constraints):
        self.calls += 1
        outcome = self.outcomes.pop(0) if self.outcomes else {"summary": "default"}
        if isinstance(outcome, Exception):
            raise outcome
        return ProviderResult(
            self.name, self.model, outcome, latency_ms=5, tokens_in=10, tokens_out=2
        )


@pytest.mark.parametrize(
    ("private", "policy", "data_policy", "allowed"),
    [
        (False, "public_only", "may_train", True),
        (False, "allowed", "may_train", True),
        (False, "none", "no_training", False),
        (True, "public_only", "no_training", False),
        (True, "allowed", "may_train", False),
        (True, "allowed", "no_training", True),
        (True, "none", "no_training", False),
    ],
)
def test_privacy_eligibility_matrix(private, policy, data_policy, allowed):
    assert eligible(FakeProvider("p", [], data_policy), private, policy) is allowed


# ----- router ------------------------------------------------------------------------------


def run(router, context=PUBLIC, policy="public_only", budget_ms=60_000):
    records = []
    outcome = router.analyze(
        context, TASK, Budget(budget_ms, reserve_ms=0), llm_policy=policy, record=records.append
    )
    return outcome, records


def test_private_code_never_reaches_a_provider_that_may_train():
    trainer = FakeProvider("trainer", [])
    outcome, records = run(Router([trainer]), PRIVATE, "allowed")
    assert outcome.reason == "blocked_by_privacy_policy"
    assert trainer.calls == 0
    assert records == []


def test_success_is_recorded():
    outcome, records = run(Router([FakeProvider("a", [{"summary": "x"}])]))
    assert outcome.ok
    assert [(r.provider, r.outcome, r.tokens_in) for r in records] == [("a", "success", 10)]


def test_non_retryable_failure_falls_back_to_the_next_provider():
    a = FakeProvider("a", [ProviderRejected("bad key")])
    b = FakeProvider("b", [{"summary": "from b"}])
    outcome, records = run(Router([a, b], sleep=lambda s: None))
    assert outcome.result.provider == "b"
    assert [(r.provider, r.outcome) for r in records] == [("a", "rejected"), ("b", "success")]
    assert a.calls == 1, "rejected errors are not retried on the same provider"


def test_transient_failure_is_retried_with_backoff():
    sleeps = []
    a = FakeProvider("a", [ProviderTimeout("slow"), {"summary": "second try"}])
    outcome, records = run(Router([a], retry_base_s=0.5, sleep=sleeps.append))
    assert outcome.ok
    assert sleeps == [0.5]
    assert [(r.outcome, r.retry_no) for r in records] == [("timeout", 0), ("success", 1)]


def test_long_rate_limit_falls_back_instead_of_waiting():
    sleeps = []
    a = FakeProvider("a", [ProviderRateLimited("slow down", retry_after_s=60)])
    b = FakeProvider("b", [{"summary": "b"}])
    outcome, _ = run(Router([a, b], max_rate_limit_wait_s=10, sleep=sleeps.append))
    assert outcome.result.provider == "b"
    assert sleeps == []


def test_retries_never_wait_past_the_budget():
    sleeps = []
    a = FakeProvider("a", [ProviderTimeout("slow"), {"summary": "never reached"}])
    outcome, _ = run(Router([a], retry_base_s=5, sleep=sleeps.append), budget_ms=4000)
    assert not outcome.ok
    assert sleeps == [], "a 5 s backoff cannot fit in a 4 s budget"


def test_no_call_starts_without_time_to_finish():
    a = FakeProvider("a", [])
    outcome, records = run(Router([a]), budget_ms=500)
    assert not outcome.ok
    assert a.calls == 0
    assert records == []


def test_circuit_breaker_opens_then_half_opens_after_cooldown():
    now = [0.0]
    breaker = CircuitBreaker(threshold=2, cooldown_s=30, clock=lambda: now[0])
    breaker.failure()
    assert breaker.allow()
    breaker.failure()
    assert not breaker.allow()
    now[0] = 31
    assert breaker.allow(), "half-open after cooldown"
    breaker.failure()
    assert not breaker.allow(), "one more failure re-opens it"
    now[0] = 62
    assert breaker.allow()
    breaker.success()
    breaker.failure()
    assert breaker.allow(), "success resets the count"


def test_open_breaker_skips_the_provider():
    a = FakeProvider("a", [ProviderError("down")] * 10)
    b = FakeProvider("b", [{"summary": "b"}] * 10)
    router = Router([a, b], max_attempts_per_provider=1, breaker_threshold=2, sleep=lambda s: None)
    run(router)
    run(router)
    assert router.breaker("a").is_open
    calls_before = a.calls
    outcome, _ = run(router)
    assert a.calls == calls_before
    assert outcome.result.provider == "b"


def test_no_providers_is_reported_not_raised():
    outcome, _ = run(Router([]))
    assert outcome.reason == "no_providers_configured"
