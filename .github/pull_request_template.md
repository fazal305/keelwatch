## What and why

<!-- What does this change, and what problem does it solve? Link the issue: Fixes #123 -->

## How it was tested

<!-- Commands run, new or changed tests, screenshots for UI changes (both themes). -->

## Checklist

- [ ] CI checks pass locally (see CONTRIBUTING.md)
- [ ] Schema changes are new migrations, and contracts/fixtures are updated if a cross-language payload changed
- [ ] New API routes have an explicit access level and `RouteTableTest` is updated
- [ ] UI changes handle loading, empty and error states and don't overflow at 375 px
- [ ] No secrets, tokens, real webhook URLs or private code in code, tests or fixtures
- [ ] Docs updated (README, `.env.example`, ADR for hard-to-reverse decisions)
