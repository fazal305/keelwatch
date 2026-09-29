# UX state matrix

Which user states apply to which screen, and how each is handled. Per the
brief, states are implemented only where they genuinely apply; "—" means the
state cannot occur on that screen, with the reason in the notes.

Legend: ✓ handled · — not applicable

| Screen | Empty | Loading | Error | Offline | Slow network | No results | Permission | Session expired | Validation | Success |
|---|---|---|---|---|---|---|---|---|---|---|
| Sign in | — | ✓ | ✓ | ✓ | ✓ | — | — | ✓ | ✓ | ✓ |
| Overview | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | ✓ | — | — |
| Repositories (list) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ ¹ | — |
| Repository detail | — | ✓ | ✓ ² | ✓ | ✓ | — | — | ✓ | — | — |
| Analysis runs (list) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ ¹ | — |
| Run detail | — | ✓ | ✓ ² | ✓ | ✓ | — | ✓ | ✓ | — | ✓ |
| Findings (list) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ ¹ | — |
| Finding detail | — | ✓ | ✓ ² | ✓ | ✓ | — | — | ✓ | — | — |
| Events | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ ¹ | — |
| Digests (list) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ ¹ | — |
| Digest detail | ✓ ³ | ✓ | ✓ ² | ✓ | ✓ | — | — | ✓ | — | — |
| Analytics | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ ¹ | — |
| Readiness (list) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ ¹ | — |
| Readiness detail | ✓ ⁴ | ✓ | ✓ ² | ✓ | ✓ | — | — | ✓ | — | — |
| Integrations | ✓ | ✓ | ✓ | ✓ | ✓ | — | ✓ | ✓ | ✓ | ✓ |
| Account | — | — | ✓ | ✓ | ✓ | — | — | ✓ | ✓ | ✓ |
| System health | ✓ | ✓ | ✓ | ✓ | ✓ | — | — | ✓ | — | — |
| Not found (404) | — | — | — | — | — | — | — | ✓ | — | — |

¹ Invalid filters in a link (old bookmark, hand-edited URL): the API answers
422 naming the parameter; the page says the link's filters aren't valid and
offers *Clear filters* instead of a retry that cannot succeed.
² A stale or wrong ID shows "not found" with a way back, not a generic error.
³ "Not sent anywhere" (no destination matched) and "Not checked in this run"
(analysis gaps) are shown explicitly.
⁴ Per criterion: *Not enough data* is its own state, never a pass or fail.

## How each state works

**Empty.** A real empty state renders from real data; nothing is ever seeded
to make a screen look populated. Empty text says what would fill the screen
(install the GitHub App, push a commit, add a destination). "Empty" and "no
results" are different components: *No findings yet* vs *Nothing matches
these filters*.

**Loading.** Lists and details show a skeleton only after 300 ms, so fast
loads don't flash. A refresh keeps the previous data on screen (Analytics dims
it) instead of blanking it. Analytics loads its chart library lazily, with a
text fallback while it arrives.

**Error.** Every failed request becomes state (`useApi`, `usePaged`, and
every form handler). Errors say what failed, carry the API's reference ID for
support, and offer *Try again* where retrying can help. A failed refresh shows
the error above the last good data.

**Offline.** The status bar shows an offline indicator on every page. Requests
fail fast with "You appear to be offline." When the browser comes back
online, any view whose last load failed for connection reasons reloads by
itself; the error text says so. A server error is not retried by that event.
If the browser is offline before sign-in can be checked, the app says the API
isn't reachable rather than showing a sign-in form it can't submit.

**Slow network.** Reads show "taking longer than usual, still waiting" after
5 s and time out at 15 s. Writes show a busy label (*Signing in…*, *Adding…*,
*Resuming…*) and ignore repeat clicks. A write that times out (8 s) says the
change **may still have been saved** and to reload before retrying, so nobody
blindly creates a duplicate.

**No results.** Every filterable list distinguishes no matches from no data,
with a one-click *Clear filters*. Analytics' "no results" is its quiet-period
state (suggesting a longer period); Readiness filtered to an account without
repositories shows *Nothing matches these filters*.

**Permission denied.** There is no admin-only page. Admin-only actions (run
resume/cancel, every Integrations change) are not rendered for viewers, who
see settings read-only with a note saying so. If a role changes mid-session,
the API's 403 is shown as *Not allowed* next to the action.

**Session expired.** Any 401 moves the whole app to an *expired* state: the
sign-in page explains the session expired and, after signing in, returns to
the exact URL (filters included). Typed secrets (passwords, webhook URLs) are
deliberately not preserved across that redirect.

**Validation.** Forms use `noValidate` and validate on submit with messages
next to the field (`aria-invalid` + `aria-describedby`), focus the first
invalid field, and map server field errors onto the same fields. A 422 without
field details is still shown as a form-level error.

**Success.** Every mutation confirms in an `aria-live` region: run resumed or
cancelled, password changed (other devices signed out), destination added,
paused, re-pointed or removed, repository setting changed. Focus stays on the
control (controls are never `disabled` while saving, which would drop focus);
after a removal, focus moves to the panel.

## Tests that pin these states

- `web/src/UxStates.test.jsx`: offline recovery, no retry on server error,
  offline before sign-in, invalid-filter links, retry of a failed "load more"
  keeps earlier pages.
- `web/src/App.test.jsx`: unreachable API, rate limiting, server field
  errors, session expiry mid-page, empty vs no-results vs error on Findings.
- `web/src/lib/api.test.js`: every error kind, including the write-timeout
  warning.
- `web/src/pages/*.test.jsx`: System health states, Integrations failures
  (including a 422 without fields).
- `web/e2e/*.spec.js`: sign-in and expiry flows, overflow at six widths on
  every data-heavy page, analytics quiet state, readiness phone layout,
  focus management.
