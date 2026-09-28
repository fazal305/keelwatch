"""Builds the provider route from environment variables.

LLM_ROUTE is an ordered, comma-separated list of provider:model pairs, e.g.
    LLM_ROUTE=groq:llama-3.3-70b-versatile,gemini:gemini-2.5-flash
An empty LLM_ROUTE means no LLM: analysis stays fully deterministic.

Each provider's data policy defaults to "may_train". Set
<PROVIDER>_DATA_POLICY=no_training only after confirming the provider's
terms for your account tier; only then may private code reach it.
"""

from __future__ import annotations

import re
from collections.abc import Mapping

from .base import DataPolicy, LLMProvider
from .http import Transport, is_allowed_url
from .providers import GeminiProvider, OpenAICompatibleProvider, groq_provider

_MODEL = re.compile(r"[A-Za-z0-9._:/-]{1,128}")
KNOWN = ("groq", "gemini", "openai_compatible")


class LLMConfigError(Exception):
    def __init__(self, errors: list[str]) -> None:
        self.errors = errors
        super().__init__("Invalid LLM configuration: " + "; ".join(errors))


def _policy(env: Mapping[str, str], name: str, errors: list[str]) -> DataPolicy:
    raw = (env.get(f"{name.upper()}_DATA_POLICY") or "may_train").strip()
    if raw not in ("no_training", "may_train"):
        errors.append(f"{name.upper()}_DATA_POLICY must be no_training or may_train")
        return "may_train"
    return raw  # type: ignore[return-value]


def build_providers(
    env: Mapping[str, str], transport: Transport | None = None
) -> list[LLMProvider]:
    route = (env.get("LLM_ROUTE") or "").strip()
    if not route:
        return []

    errors: list[str] = []
    providers: list[LLMProvider] = []
    seen: set[str] = set()

    for entry in (e.strip() for e in route.split(",") if e.strip()):
        name, _, model = entry.partition(":")
        name = name.strip()
        model = model.strip()
        if name not in KNOWN:
            errors.append(f"LLM_ROUTE: unknown provider {name!r} (known: {', '.join(KNOWN)})")
            continue
        if not _MODEL.fullmatch(model):
            errors.append(f"LLM_ROUTE: {name} needs a model, like {name}:<model>")
            continue
        if name in seen:
            errors.append(f"LLM_ROUTE: {name} is listed twice")
            continue
        seen.add(name)

        key_var = f"{name.upper()}_API_KEY"
        api_key = (env.get(key_var) or "").strip()
        if not api_key:
            errors.append(f"{key_var} is required because LLM_ROUTE includes {name}")
            continue
        policy = _policy(env, name, errors)

        if name == "groq":
            providers.append(groq_provider(api_key, model, policy, transport))
        elif name == "gemini":
            providers.append(GeminiProvider(api_key, model, policy, transport))
        else:
            base_url = (env.get("OPENAI_COMPATIBLE_BASE_URL") or "").strip()
            if not is_allowed_url(base_url):
                errors.append(
                    "OPENAI_COMPATIBLE_BASE_URL must be https://, or http:// on localhost/127.0.0.1"
                )
                continue
            # A gateway's upstream is unknown, so it is never trusted with private code.
            if policy == "no_training":
                errors.append("OPENAI_COMPATIBLE_DATA_POLICY cannot be no_training for a gateway")
                continue
            providers.append(
                OpenAICompatibleProvider(
                    "openai_compatible", base_url, api_key, model, "may_train", transport
                )
            )

    if errors:
        raise LLMConfigError(errors)
    return providers
