# ADR 0002 — Escape by default, no raw HTML sinks

Status: accepted · 2026-09-27

## Context

Repository data (commit messages, PR titles, file paths, branch names) is
attacker-controllable. Earlier projects shipped XSS bugs where API data was
interpolated into `innerHTML`, each fixed individually after the fact.

## Decision

- All data reaches the DOM through React text nodes, which escape by
  default.
- ESLint rejects `dangerouslySetInnerHTML`, assignments to
  `innerHTML`/`outerHTML`, `insertAdjacentHTML`, and `document.write`.
  There is no opt-in; a future need (for example rendered Markdown) must go
  through a reviewed sanitiser and an ADR amendment.
- The API returns JSON only, with `X-Content-Type-Options: nosniff` and a
  `default-src 'none'` Content-Security-Policy.
- A unit test renders a hostile string from the API and asserts it appears
  as text with no element created.

## Consequences

Rich rendering (diffs, Markdown digests) must be built from structured data
into React elements, not from HTML strings.
