# ADR 0004 — Readiness signals, not scores

Status: accepted (implementation in Phase 9) · 2026-09-29

## Context

The brief asks for "candidate/repository readiness insights" for technical
recruiters and engineering managers, and in the same breath warns against
presenting them as objective truth or as a measure of developer ability.

## Decision

- **No combined score, grade or rank.** Each repository gets a checklist of
  criteria. Each criterion is *meets*, *below* or *not enough data*, and the
  API returns its definition, threshold, sample size, evidence sentence and
  known limitation with every response, so the UI always shows the rule that
  was applied.
- **Not enough data is a first-class answer.** A criterion without its
  minimum sample (for example fewer than 5 analysed changes) never falls back
  to a verdict in either direction.
- **Candidates are accounts that opted in, not commit authors.** A
  "candidate" is the GitHub account that installed Keelwatch on its own
  repositories. Events record an `actor_login`, but individual authors are
  never profiled or compared: they did not opt in, and repository activity
  says little about any one person.
- **Signals describe repositories as observed since watching began.** Work
  in other repositories, private work elsewhere, pairing, and history before
  installation are invisible, and the UI says so.
- **Deterministic inputs only.** Every criterion is computed from stored
  events, change-shape metrics and findings. No criterion is AI-generated;
  where a finding came from optional AI review it keeps its own confidence.

## Criteria (defaults)

| Key | Meets when | Minimum sample |
|---|---|---|
| `recent_activity` | a push or PR event in the last 30 days | — (says "not enough data" while watched < 30 days) |
| `steady_activity` | active in ≥ 50% of watched weeks (90-day window) | 4 watched weeks |
| `tests_with_changes` | ≥ 50% of analysed changes adding 50+ source lines touch a test file | 5 such changes |
| `reviewable_size` | median analysed change ≤ 400 changed lines | 5 analysed changes |
| `no_open_serious` | latest completed analysis has no critical/high findings | 1 completed analysis |
| `no_secrets` | no secret-scanner findings in the window | 3 completed analyses for a clean result; any finding is "below" at once |
| `deps_clean` | no OSV-matched dependency added in the window | 3 analyses whose vulnerability lookup ran, for a clean result; any match is "below" at once |

Thresholds live in `ReadinessRepository::CRITERIA`. Changing them changes
every repository's result, which is why the applied values are returned with
the data rather than hard-coded in the UI.

## Consequences

- There is no leaderboard view; sorting repositories "best to worst" would
  reintroduce a score by the back door.
- Recruiter-facing copy must repeat the limits next to the signals, not only
  in documentation.
