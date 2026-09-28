# Contracts

Language-neutral JSON Schemas (draft 2020-12) for the data that crosses the
PHP API ↔ Python worker boundary. They are the source of truth; runtime
code in each language is tested against them, and neither runtime depends
on a schema library.

| Contract | Producer | Consumer | Where it lives at runtime |
| --- | --- | --- | --- |
| `github-event.v1.json` | API webhook normalizer | Worker | `repository_events.envelope`, `jobs.payload` (queue `events`) |
| `analysis-job.v1.json` | API / scheduler / worker | Worker | `jobs.payload` (queue `analysis`) |
| `finding.v1.json` | Worker analysis phases | API / dashboard | `analysis_findings` rows |

## Fixtures

`fixtures/<contract>/valid/*.json` are complete documents that must pass.

`fixtures/<contract>/invalid/*.json` are **patches** on a valid fixture:

```json
{ "base": "push.json", "set": { "push.after": "NOT-A-SHA" }, "unset": ["actor"] }
```

Paths are dot-separated; numeric segments index arrays. Each patch makes
exactly one change, so an invalid fixture can only fail for the reason in
its file name. The contract tests in `api/tests/Contract` and
`worker/tests/test_contracts.py` apply the same patches and must agree.

## Regex semantics

JSON Schema patterns use ECMA-262 semantics, where `$` matches only at the
true end of the string. Python's `re.search` also lets `$` match before a
final newline, so `"<40 hex chars>\n"` would pass a SHA pattern there.
The Python contract tests use a validator with ECMA end-anchoring, and
fixtures `*-with-trailing-newline.json` pin the behaviour. Any Python
runtime check derived from these patterns must use `re.fullmatch`, never
`re.search`/`re.match` with `$`.

## Changing a contract

Contracts are versioned by file name. A breaking change is a new file
(`*.v2.json`) plus a `schema_version` bump; consumers accept both versions
until every producer has moved.
