# ADR 0003 — LLM privacy defaults

Status: accepted (implementation in Phase 5) · 2026-09-27

## Decision

- Analysis is deterministic first. An LLM is optional; with no provider
  configured the pipeline still produces findings.
- Every repository has an `llm_policy`: `none`, `public_only`, or
  `allowed`. Private repositories default to `none`.
- Free-tier or keyless providers are never used for private code.
- Only a bounded, redacted context pack is sent: selected diff hunks with
  detected secrets masked before sending, repo-relative paths, and no author
  emails.
- Model and provider names come from configuration, never business logic.
- Aggregating gateways (for example OmniRoute) may only be used through the
  generic OpenAI-compatible adapter, are off by default, and are blocked for
  private repositories.
