# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately through
[GitHub private vulnerability reporting](https://github.com/fazal305/keelwatch/security/advisories/new),
not in public issues, discussions or pull requests.

Include what you can of:

- the affected component (API, worker, dashboard) and version or commit;
- steps to reproduce, or a proof of concept;
- the impact you expect, and any conditions it depends on.

You can expect an acknowledgement within 5 working days and an assessment
within 14. Fixes are released as soon as they are ready, with credit in the
advisory unless you prefer otherwise. Please give a reasonable time to fix an
issue before disclosing it publicly.

## Supported versions

Keelwatch is pre-1.0. Only the latest commit on `main` receives security
fixes.

## Scope

In scope: anything in this repository, including the webhook endpoint, the
dashboard API and sign-in, the worker's outbound requests, stored-secret
handling, and what is sent to LLM providers or notification channels.

Out of scope: vulnerabilities in GitHub, OSV.dev, LLM providers or chat
platforms themselves; attacks that need an already-compromised server or
`.env`; missing hardening on a deployment that ignores the documented
configuration (for example `APP_ENV` not set to `production`).

## Security model

The design and its known limits are documented in:

- [docs/architecture.md](docs/architecture.md#security-boundaries): trust
  boundaries and the controls on each;
- [docs/security-audit.md](docs/security-audit.md): the latest audit, its
  fixes and accepted risks;
- [ADR 0003](docs/adr/0003-llm-privacy-defaults.md): what may be sent to LLM
  providers.

## Operator checklist

- Serve the API only over HTTPS, and set `APP_ENV=production`.
- Generate `APP_SECRET`, `GITHUB_WEBHOOK_SECRET` and `NOTIFICATION_KEY` with
  a cryptographic random source, and keep `.env` readable only by the
  service account.
- Keep the GitHub App private key on disk (`GITHUB_APP_PRIVATE_KEY_PATH`);
  never paste it into `.env` or the repository.
- Give the database user rights only on the Keelwatch databases.
- Rotate the webhook secret with `GITHUB_WEBHOOK_SECRET_PREVIOUS` so
  in-flight deliveries still verify.
