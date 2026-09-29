import { expect, test } from '@playwright/test';

const WIDTHS = [375, 390, 768, 1024, 1280, 1440];

const json = (route, status, body) =>
  route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

const CRITERIA = [
  ['recent_activity', 'Changed recently'],
  ['steady_activity', 'Steady activity'],
  ['tests_with_changes', 'Tests change with code'],
  ['reviewable_size', 'Reviewable change size'],
  ['no_open_serious', 'No open serious findings'],
  ['no_secrets', 'No exposed secrets'],
  ['deps_clean', 'No known-vulnerable dependencies added'],
].map(([key, label]) => ({ key, label, definition: `Rule for ${label}.`, limitation: `Limits of ${label}.`, threshold: 0 }));

const sig = (status, evidence) => ({ status, value: null, evidence, sample: 1 });

const REPO = {
  id: 7,
  full_name: 'alice-dev/a-repository-with-quite-a-long-name',
  is_private: false,
  analysis_enabled: true,
  watching_since: '2026-08-01T00:00:00.000Z',
  signals: {
    recent_activity: sig('met', 'Last change 2 days ago.'),
    steady_activity: sig('not_met', 'Active in 3 of 8 watched weeks.'),
    tests_with_changes: sig('insufficient', '2 analysed changes add 50+ source lines; needs 5.'),
    reviewable_size: sig('met', 'Median change: 120 lines across 6 analysed changes.'),
    no_open_serious: sig('not_met', '1 critical or high finding in the latest completed analysis.'),
    no_secrets: sig('met', 'None across 6 completed analyses.'),
    deps_clean: sig('insufficient', 'None so far, across 1 analysis that checked dependencies; needs 3 for a clean result.'),
  },
};

async function fakeApi(page, { accounts } = {}) {
  await page.route('**/api/**', (route) => json(route, 404, { error: { code: 'not_found', message: 'Not mocked.' } }));
  await page.route('**/api/auth/session', (route) => json(route, 200, { user: { id: 1, username: 'e2e', role: 'viewer' }, csrf_token: 'c' }));
  await page.route('**/api/system/health', (route) =>
    json(route, 200, { status: 'ok', checked_at: '2026-09-29T12:00:00Z', environment: 'test', components: [] }),
  );
  const list = accounts ?? [{ id: 1, account_login: 'alice-dev', account_type: 'User', status: 'active', repositories: [REPO] }];
  await page.route(/\/api\/readiness(\?.*)?$/, (route) =>
    json(route, 200, {
      window_days: 90,
      generated_at: '2026-09-29T12:00:00Z',
      criteria: CRITERIA,
      accounts: list,
      account_options: list.map((a) => ({ id: a.id, account_login: a.account_login })),
    }),
  );
  await page.route('**/api/readiness/repositories/7', (route) =>
    json(route, 200, {
      window_days: 90,
      generated_at: '2026-09-29T12:00:00Z',
      criteria: CRITERIA,
      account: { id: 1, account_login: 'alice-dev' },
      repository: { id: 7, full_name: REPO.full_name, is_private: false, analysis_enabled: true, watching_since: REPO.watching_since },
      signals: REPO.signals,
    }),
  );
}

for (const width of WIDTHS) {
  test(`readiness does not overflow · ${width}px`, async ({ page }) => {
    await fakeApi(page);
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/readiness');
    await expect(page.getByRole('link', { name: REPO.full_name })).toBeVisible();
    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth, `document is ${scrollWidth}px wide in a ${clientWidth}px viewport`).toBeLessThanOrEqual(clientWidth);
  });
}

test('the checklist shows shape-and-word statuses, the limits, and never a score', async ({ page }) => {
  await fakeApi(page);
  await page.goto('/readiness');
  const table = page.getByRole('region', { name: 'Readiness signals for alice-dev' });
  await expect(table.getByRole('columnheader')).toHaveCount(8);
  const row = table.getByRole('row').filter({ hasText: REPO.full_name });
  await expect(row.getByText('Meets')).toHaveCount(3);
  await expect(row.getByText('Below')).toHaveCount(2);
  await expect(row.getByText('Not enough data')).toHaveCount(2);
  await expect(page.getByText('They are not a measure of any person’s skill')).toBeVisible();
  await expect(page.getByText('There is no combined score, by design.')).toBeVisible();
  await expect(page.locator('main')).not.toContainText(/\bscore:|\d+ ?\/ ?7|\d+%/i);
});

test('the detail page gives every criterion its evidence, rule and limits', async ({ page }) => {
  await fakeApi(page);
  await page.goto('/readiness');
  await page.getByRole('link', { name: REPO.full_name }).click();
  await expect(page).toHaveURL(/\/readiness\/7$/);
  await expect(page.getByRole('heading', { level: 1, name: REPO.full_name })).toBeVisible();
  await expect(page.getByRole('heading', { level: 2 }).filter({ hasText: /./ })).toHaveCount(CRITERIA.length + 1);
  for (const c of CRITERIA) {
    await expect(page.getByText(c.definition)).toBeVisible();
    await expect(page.getByText(c.limitation)).toBeVisible();
  }
  await expect(page.getByText('needs 3 for a clean result')).toBeVisible();
  await expect(page.locator('main')).not.toContainText(/criteria met/);
});

test('on a phone every criterion is visible without sideways scrolling', async ({ page }) => {
  await fakeApi(page);
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/readiness');
  await expect(page.getByRole('region', { name: 'Readiness signals for alice-dev' })).toBeHidden();
  const card = page.locator('.readiness-card');
  await expect(card.getByRole('term')).toHaveCount(7);
  for (const c of CRITERIA) {
    await expect(card.getByText(c.label, { exact: true })).toBeVisible();
  }
  await expect(card.getByText('Not enough data')).toHaveCount(2);
});
test('with no connected accounts the page explains what is missing', async ({ page }) => {
  await fakeApi(page, { accounts: [] });
  await page.goto('/readiness');
  await expect(page.getByText('No repositories to assess yet')).toBeVisible();
});

// Opt-in visual review: SCREENSHOTS=1 npx playwright test readiness
test('screenshots for review', async ({ page }) => {
  test.skip(!process.env.SCREENSHOTS, 'set SCREENSHOTS=1 to capture');
  await fakeApi(page);
  for (const [name, width, path] of [['list', 1280, '/readiness'], ['detail', 1280, '/readiness/7'], ['list-phone', 375, '/readiness']]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(path);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await page.waitForTimeout(300);
    await page.screenshot({ path: `test-results/readiness-${name}.png`, fullPage: true });
  }
});
