import { expect, test } from '@playwright/test';

const WIDTHS = [375, 390, 768, 1024, 1280, 1440];

const LONG_ID = 'worker-with-an-unusually-long-hostname-that-could-overflow-a-narrow-column-12345';

const REPORTS = {
  healthy: {
    status: 'ok',
    checked_at: '2026-09-27T12:00:00Z',
    environment: 'development',
    components: [
      { name: 'api', status: 'ok', summary: 'Serving requests.', version: '0.1.0' },
      { name: 'database', status: 'ok', summary: 'Connected. Schema is up to date.', latency_ms: 1.2, pending_migrations: 0 },
      {
        name: 'workers',
        status: 'ok',
        summary: '2 worker(s) running.',
        stale_after_s: 35,
        workers: [
          { id: LONG_ID, status: 'running', version: '0.1.0', started_at: '2026-09-27T11:00:00Z', last_seen_at: '2026-09-27T11:59:58Z', last_seen_age_s: 2 },
          { id: 'host-2', status: 'stale', version: '0.1.0', started_at: '2026-09-27T10:00:00Z', last_seen_at: '2026-09-27T11:50:00Z', last_seen_age_s: 600 },
        ],
      },
    ],
  },
  noWorkers: {
    status: 'degraded',
    checked_at: '2026-09-27T12:00:00Z',
    environment: 'development',
    components: [
      { name: 'api', status: 'ok', summary: 'Serving requests.', version: '0.1.0' },
      { name: 'database', status: 'ok', summary: 'Connected. Schema is up to date.', latency_ms: 1.2, pending_migrations: 0 },
      { name: 'workers', status: 'down', summary: 'No worker has reported a heartbeat yet.', stale_after_s: 35, workers: [] },
    ],
  },
};

async function mockHealth(page, scenario) {
  await page.route('**/api/system/health', (route) => {
    if (scenario === 'unreachable') return route.fulfill({ status: 502, body: '' });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(REPORTS[scenario]) });
  });
}

async function overflow(page) {
  return page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
}

for (const scenario of ['healthy', 'noWorkers', 'unreachable']) {
  for (const width of WIDTHS) {
    test(`no horizontal overflow · ${scenario} · ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await mockHealth(page, scenario);
      await page.goto('/system');

      const settled =
        scenario === 'unreachable'
          ? page.getByRole('alert')
          : page.getByRole('heading', { name: 'Worker heartbeats' });
      await expect(settled).toBeVisible();

      const { scrollWidth, clientWidth } = await overflow(page);
      expect(scrollWidth, `document is ${scrollWidth}px wide in a ${clientWidth}px viewport`).toBeLessThanOrEqual(clientWidth);
    });
  }
}

test('mobile status bar sits directly under the top bar when content is short', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await mockHealth(page, 'unreachable');
  await page.goto('/system');
  await expect(page.getByRole('alert')).toBeVisible();

  const gap = await page.evaluate(() => {
    const top = document.querySelector('.topbar').getBoundingClientRect();
    const bar = document.querySelector('.statusbar').getBoundingClientRect();
    return Math.round(bar.top - top.bottom);
  });
  expect(gap).toBe(0);
});

test('mobile drawer opens, traps nothing behind it, and closes with Escape', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await mockHealth(page, 'healthy');
  await page.goto('/system');

  const nav = page.getByRole('navigation', { name: 'Primary' });
  await expect(nav).toBeHidden();

  const menu = page.getByRole('button', { name: 'Open navigation' });
  await menu.click();
  await expect(nav).toBeVisible();
  await expect(menu).toHaveAttribute('aria-expanded', 'true');
  await expect(page.getByRole('link', { name: 'System health' })).toBeFocused();

  // Wait for the slide-in transition before measuring overflow.
  await page.waitForTimeout(250);
  const { scrollWidth, clientWidth } = await overflow(page);
  expect(scrollWidth).toBeLessThanOrEqual(clientWidth);

  await page.keyboard.press('Escape');
  await expect(nav).toBeHidden();
  await expect(menu).toBeFocused();
});

test('keyboard users can skip to content and see a focus ring', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await mockHealth(page, 'healthy');
  await page.goto('/system');
  await expect(page.getByRole('heading', { name: 'Worker heartbeats' })).toBeVisible();

  await page.keyboard.press('Tab');
  const skip = page.getByRole('link', { name: 'Skip to content' });
  await expect(skip).toBeFocused();
  const outline = await skip.evaluate((el) => getComputedStyle(el).outlineStyle);
  expect(outline).toBe('solid');

  await page.keyboard.press('Enter');
  await expect(page.locator('#main')).toBeFocused();
});

test('unknown routes render the custom 404 inside the shell', async ({ page }) => {
  await mockHealth(page, 'healthy');
  await page.goto('/does/not/exist');
  await expect(page.getByRole('heading', { name: "This page doesn't exist" })).toBeVisible();
  await expect(page).toHaveTitle('Page not found · Keelwatch');
});

test('fonts are actually loaded, not just declared', async ({ page }) => {
  await mockHealth(page, 'healthy');
  await page.goto('/system');
  await expect(page.getByRole('heading', { name: 'Worker heartbeats' })).toBeVisible();

  const loaded = await page.evaluate(async () => {
    await document.fonts.ready;
    return {
      sans: document.fonts.check('400 14px "IBM Plex Sans"'),
      condensed: document.fonts.check('500 12px "IBM Plex Sans Condensed"'),
      mono: document.fonts.check('400 14px "IBM Plex Mono"'),
    };
  });
  expect(loaded).toEqual({ sans: true, condensed: true, mono: true });
});
