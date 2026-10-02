# Security audit (Phase 12)

Scope: the API (PHP), the worker (Python), the web app, the database schema
and the git history, as of 2026-09-29. It covers the seven areas the brief
names. Each finding was reproduced or measured before it was fixed, and each
fix has a test. This is a self-review by the same effort that built the
system; it is not a substitute for an independent audit.

## Findings

| ID | Severity | Area | Finding | Status |
|---|---|---|---|---|
| S1 | **High** | Authorization | Login time revealed whether a username exists: unknown usernames took **1.92×** as long (728 vs 380 ms p50) | Fixed, verified |
| S2 | Medium | Authorization | Routes defaulted to **public** when a route forgot its access level (fail-open) | Fixed |
| S3 | Low | Webhook | The webhook's `Server-Timing` header told unauthenticated callers how far their request got | Fixed |
| S4 | Low | SSRF | Repository names `owner/.` and `owner/..` passed the GitHub client's name check | Fixed |
| S5 | Low | Dependencies | The worker venv's **pip 25.0.1** has 12 known advisories (installer tooling, not a runtime dependency) | **Fixed for new installs**; existing venvs need a one-time upgrade (see below) |
| S6 | Info | Webhook | GitHub signs the body but not the delivery ID, so a captured, signed body could be resent under a new ID | Accepted, impact bounded |
| S7 | Info | Notifications | Private-repository messages include the PR number and short commit SHA | Accepted |
| S8 | Info | Database | The app's MySQL user can't read `performance_schema` or `mysql.user` | Confirmed as intended (least privilege) |

### S1: username enumeration by timing (High, fixed)

- **Cause:**
  - Unknown usernames were verified against a dummy hash, generated on first
    use with `password_hash()`.
  - PHP starts fresh on every request, so every unknown-username attempt paid
    for a whole Argon2id hash *plus* the verify.
- **Measured** with `php bench/login_timing.php` (30 failed sign-ins each, a
  fresh kernel per attempt): existing user 380 ms, unknown username 728 ms.
- **Fix:**
  - The dummy hash is now a precomputed constant (`AuthService::DUMMY_HASH`),
    so both paths do exactly one `password_verify` with the same parameters.
  - Its password was random and discarded.
- **Verified:** 373 ms vs 371 ms (ratio 0.99).
- **Guard:** `AuthTimingTest` fails if PHP's Argon2id defaults change without
  the constant being regenerated.

### S2: routes were public by default (Medium, fixed)

- **Cause:**
  - `Router::get/post/patch/delete` defaulted to `PUBLIC`.
  - Every current route happened to be intended, but a future route that
    forgot the argument would have been exposed without sign-in.
- **Fix:**
  - The default is now `VIEWER`, and the four genuinely public routes
    (`/healthz`, `/readyz`, `/webhooks/github`, `/api/auth/login`) say so
    explicitly.
  - Switching the default showed that the login route had relied on it; it's
    now marked public.
- **Guard (`RouteTableTest`):**
  - pins every route's access level, so any new route or access change is a
    reviewed diff;
  - checks that only those four routes are public;
  - checks that every state change outside your own session is admin-only;
  - checks that a route without an argument requires sign-in.

### S3: webhook timing header (Low, fixed)

- **Before:** `Server-Timing` (`verify;dur=…, db;dur=…`) went to any caller,
  including ones failing the signature check.
- **Now:** it's sent only outside production; the timings are always logged.
- **Test:** `WebhookTest::testProductionDoesNotExposeStepTimings`.

### S4: dot repository names (Low, fixed)

- **Before:** the name pattern allowed `owner/..`, which would climb out of
  `/repos/{owner}/` in GitHub API paths. The host was fixed and the token
  read-only, and such names only arrive in signed webhooks; GitHub never
  issues them.
- **Now:** rejected before any request, with two new cases in
  `test_unsafe_inputs_are_refused_before_any_request`.

### S5: pip in the worker venv (Low, fixed for new installs)

- **Result:** OSV.dev lists 12 advisories for pip 25.0.1, mostly about
  handling malicious package archives during installs.
- **Scope:** it isn't imported at runtime, but it ships in any venv-based
  deployment.
- **Fix:** the README's setup now upgrades pip right after creating the venv.
  pip 26.2.1 (current on 2026-10-02) has no known advisories (`pip-audit`).
- **Still to do:** a venv created before this change keeps its old pip until
  it is upgraded once:

  ```bash
  worker/.venv/Scripts/python.exe -m pip install --upgrade pip   # Windows
  worker/.venv/bin/python -m pip install --upgrade pip           # Linux/macOS
  ```

### S6: webhook replay under a new delivery ID (Info, accepted)

- **Risk:** GitHub's HMAC covers the body, not the `X-GitHub-Delivery`
  header, so delivery-ID deduplication can't stop a resend under a new ID.
- **Preconditions:** a legitimately signed body, captured from TLS-protected
  traffic.
- **Impact:** at most an extra delivery and event row. Analysis runs are
  idempotent on repository + commit SHA (`pr:{repo}:{number}:{sha}`,
  `push:{repo}:{sha}`), so no second analysis or notification follows.

### S7: private-repository metadata in notifications (Info, accepted)

- **What's sent:** "PR #12" or a short SHA plus severity counts.
- **What isn't:** repository name, paths, titles, evidence, or AI-written
  text.

## Areas reviewed with no further findings

**1. Secrets scan.**
- **History:** all 16 commits (`git log --all -p`) scanned for provider keys
  (GitHub, Slack, Discord, AWS, Google, Groq, OpenAI-style), private keys,
  assignments to the project's secret variables, and the local dev password.
  The single hit, `db_password=required("DB_PASSWORD")`, reads the variable.
- **Never tracked:** `.env`, `.dev-credentials`, key files, or the generated
  DB setup files. The secret-finding fixture holds only `[REDACTED:24 chars]`.
- **Tests:** webhook-shaped URLs are assembled at runtime so scanners can't
  mistake them for real ones.

**2. Dependency scan.**
- `composer audit --locked`: no advisories.
- `npm audit`: 0 vulnerabilities.
- Worker: 20 installed packages checked against OSV.dev; only pip (S5).

**3. Webhook security.**
- **Order:**
  1. content type, event and delivery headers, and a size limit, all checked
     before reading the body;
  2. the HMAC-SHA256 signature, before any parsing;
  3. dedupe on delivery ID and all storage in one transaction.
- **Signature:** SHA-256 only (the SHA-1 header is ignored), with
  `hash_equals` checked against every configured secret without an early
  exit, so key rotation doesn't leak which one matched.
- **Throttling:** bad signatures are throttled per client.
- **Failure handling:** a signed payload with an unexpected shape is recorded
  as ignored, not retried forever.

**4. SSRF.**
- **Base URLs** (GitHub, OSV, AI gateway) come only from operator
  configuration and must be `https://`; localhost is allowed only for a local
  AI gateway.
- **Paths:**
  - repository names and SHAs are pattern-checked;
  - file paths refuse `..` and absolute forms.
- **Redirects:**
  - GitHub pagination links are followed only under the configured API URL.
  - GitHub redirects must stay on the same host and on https.
  - Every other outbound client refuses redirects entirely.
- **Notification destinations:**
  - PHP and Python share one allowlist test vector (29 cases).
  - Every address must resolve to a public IP before each send.

**5. Notification redaction.**
- No code, evidence or AI-written titles are ever included.
- Private repositories get counts only.
- Everything passes a final `redact()`; Slack control sequences are escaped,
  and Discord mentions are disabled.
- Delivery errors never contain the webhook URL (`_safe()`).

**6. Authorization and sessions.**
- **Access:** covered by the pinned route table (S2).
- **CSRF:** every unsafe method requires the per-session `X-CSRF-Token` and an
  allowed `Origin`.
- **Sessions:**
  - 256-bit random tokens, stored only as SHA-256;
  - idle and absolute expiry;
  - all of a user's sessions revoked on password change;
  - `__Host-` cookie with `SameSite=Strict` when secure.
- **Login:**
  - one message for an unknown user or a wrong password;
  - per-IP and per-username throttles keyed with `APP_SECRET`;
  - Argon2id.
- **Response headers:** every API response sends `nosniff`, `no-store`,
  `DENY` framing and `default-src 'none'`.

**7. Sensitive logging.**
- **Key-name redaction:** both loggers mask any context key naming a
  password, secret, token, authorization header, signature, cookie, API key,
  private key or credential, recursively.
- **Free-text values:** no request bodies or headers are logged. AI provider
  keys travel only in headers, and provider errors are capped at 300
  characters and passed through `redact()`. Destination errors carry fixed
  text or the host only.

## Not covered

- **No independent penetration test,** and no fuzzing of the webhook
  normalizer beyond the contract fixtures.
- **Deployment hardening** (TLS, PHP-FPM, file permissions, MySQL account
  grants on the target host) belongs to Phase 14.
- **The GitHub App's own permissions** will be reviewed when the App is
  created. It should need read-only contents, metadata and pull requests.
