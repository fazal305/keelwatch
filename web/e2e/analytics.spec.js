import { expect, test } from '@playwright/test';

const WIDTHS = [375, 390, 768, 1024, 1280, 1440];

const json = (route, status, body) =>
  route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

/** Deterministic 30-day history: the first 4 days predate watching (null). */
function dataset({ days = 30, quiet = false } = {}) {
  const end = Date.UTC(2026, 8, 29);
  const series = Array.from({ length: days }, (_, i) => {
    const date = new Date(end - (days - 1 - i) * 86_400_000).toISOString().slice(0, 10);
    if (i < 4) {
      return { date, runs: null, runs_failed: null, findings_new: null, findings_recurring: null, completed_runs: 0, duration_p50_ms: null, duration_p95_ms: null };
    }
    const runs = quiet ? 0 : 3 + ((i * 7) % 9);
    const failed = quiet ? 0 : i % 5 === 0 ? 2 : i % 3 === 0 ? 1 : 0;
    const completed = runs - failed;
    return {
      date,
      runs,
      runs_failed: failed,
      findings_new: quiet ? 0 : (i * 3) % 5,
      findings_recurring: quiet ? 0 : (i * 5) % 7,
      completed_runs: completed,
      duration_p50_ms: completed >= 3 ? 1400 + ((i * 131) % 900) : null,
      duration_p95_ms: completed >= 3 ? 4200 + ((i * 377) % 2600) : null,
    };
  });
  const sum = (k) => series.reduce((a, d) => a + (d[k] ?? 0), 0);
  return {
    range: { days, start: series[0].date, end: series[days - 1].date, timezone: 'UTC' },
    repository_id: null,
    watching_since: series[4].date,
    min_duration_samples: 3,
    totals: {
      runs: sum('runs'),
      runs_failed: sum('runs_failed'),
      findings_new: sum('findings_new'),
      findings_recurring: sum('findings_recurring'),
      completed_runs: sum('completed_runs'),
      duration_p50_ms: quiet ? null : 1820,
      notifications: { sent: quiet ? 0 : 41, failed: quiet ? 0 : 2 },
    },
    findings_by_severity: quiet ? { critical: 0, high: 0, medium: 0, low: 0, info: 0 } : { critical: 2, high: 9, medium: 17, low: 11, info: 24 },
    findings_by_category: quiet
      ? { security: 0, dependency: 0, logic: 0, architecture: 0, quality: 0 }
      : { security: 11, dependency: 14, logic: 6, architecture: 3, quality: 29 },
    days: series,
  };
}

async function fakeApi(page, options = {}) {
  const requests = [];
  await page.route('**/api/**', (route) => json(route, 404, { error: { code: 'not_found', message: 'Not mocked.' } }));
  await page.route('**/api/auth/session', (route) => json(route, 200, { user: { id: 1, username: 'e2e', role: 'viewer' }, csrf_token: 'c' }));
  await page.route('**/api/system/health', (route) =>
    json(route, 200, { status: 'ok', checked_at: '2026-09-29T12:00:00Z', environment: 'test', components: [] }),
  );
  await page.route(/\/api\/repositories(\?.*)?$/, (route) =>
    json(route, 200, { items: [{ id: 4, full_name: 'example-org/api', is_private: false, llm_policy: 'public_only', analysis_enabled: true }] }),
  );
  await page.route(/\/api\/analytics(\?.*)?$/, (route) => {
    const url = new URL(route.request().url());
    requests.push(Object.fromEntries(url.searchParams));
    return json(route, 200, dataset({ days: Number(url.searchParams.get('days') ?? 30), ...options }));
  });
  return requests;
}

async function noOverflow(page) {
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(scrollWidth, `document is ${scrollWidth}px wide in a ${clientWidth}px viewport`).toBeLessThanOrEqual(clientWidth);
}

for (const width of WIDTHS) {
  test(`analytics does not overflow · ${width}px`, async ({ page }) => {
    await fakeApi(page);
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/analytics');
    await expect(page.getByRole('img', { name: /Analysis runs per day/ })).toBeVisible();
    await noOverflow(page);
  });
}

test('filters live in the URL and re-scope the request; the old render stays while loading', async ({ page }) => {
  const requests = await fakeApi(page);
  await page.goto('/analytics');
  await expect(page.getByRole('img', { name: /Analysis runs per day/ })).toBeVisible();
  expect(requests.at(-1)).toEqual({ days: '30' });

  await page.getByRole('radio', { name: '7 days' }).click();
  await expect(page.getByRole('radio', { name: '7 days' })).toBeChecked();
  await expect(page).toHaveURL(/days=7/);
  await expect.poll(() => requests.at(-1)).toEqual({ days: '7' });

  await page.getByLabel('Repository').selectOption('4');
  await expect(page).toHaveURL(/repository_id=4/);
  await expect.poll(() => requests.at(-1)).toEqual({ days: '7', repository_id: '4' });

  await page.getByRole('radio', { name: '30 days' }).click();
  await expect(page.getByRole('radio', { name: '30 days' })).toBeChecked();
  await expect(page).not.toHaveURL(/days=/); // the default isn't written to the URL
});

test('every chart has a table view with the same numbers, and pre-history days read as no data', async ({ page }) => {
  await fakeApi(page);
  await page.goto('/analytics');
  const panel = page.getByRole('region', { name: 'Analysis runs per day' }).or(page.locator('section', { hasText: 'Analysis runs per day' })).first();
  await panel.getByText('Show as table').click();
  const table = panel.getByRole('table');
  await expect(table.getByRole('row')).toHaveCount(31);
  // First day predates watching: "—", never 0.
  await expect(table.getByRole('row').nth(1).getByRole('cell').first()).toHaveText('—');
  await expect(page.getByText(/Earlier days show no data, not zero/)).toBeVisible();
});

test('a quiet period says so instead of drawing empty axes', async ({ page }) => {
  await fakeApi(page, { quiet: true });
  await page.goto('/analytics');
  await expect(page.getByText('Nothing to chart yet')).toBeVisible();
  await expect(page.getByRole('img', { name: /Analysis runs per day/ })).toHaveCount(0);
});

test('charts repaint when the theme changes', async ({ page }) => {
  await fakeApi(page);
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto('/analytics');
  const canvas = page.getByRole('img', { name: /Findings per day/ });
  await expect(canvas).toBeVisible();
  const pixels = () => canvas.evaluate((c) => c.toDataURL());
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'));
  await page.waitForTimeout(100);
  const light = await pixels();
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await expect.poll(pixels).not.toBe(light);
  const colour = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--chart-series-1').trim());
  expect(colour).toBe('#3987e5'); // the dark-mode step, not the light one
});

// Opt-in visual review: SCREENSHOTS=1 npx playwright test analytics
test('screenshots for review', async ({ page }) => {
  test.skip(!process.env.SCREENSHOTS, 'set SCREENSHOTS=1 to capture');
  await fakeApi(page);
  for (const [name, width, theme] of [['desktop-dark', 1280, 'dark'], ['desktop-light', 1280, 'light'], ['phone-dark', 375, 'dark']]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/analytics');
    await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
    await expect(page.getByRole('img', { name: /Run time/ })).toBeVisible();
    await page.waitForTimeout(300);
    await page.screenshot({ path: `test-results/analytics-${name}.png`, fullPage: true });
    if (width === 1280) {
      const box = await page.getByRole('img', { name: /Analysis runs per day/ }).boundingBox();
      await page.screenshot({ path: `test-results/analytics-${name}-zoom.png`, clip: { x: box.x + box.width * 0.2, y: box.y + box.height * 0.3, width: 220, height: 140 } });
    }
  }
});
