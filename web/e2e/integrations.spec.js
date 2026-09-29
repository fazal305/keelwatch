import { expect, test } from '@playwright/test';

const WIDTHS = [375, 390, 768, 1024, 1280, 1440];

const json = (route, status, body) =>
  route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

const INSTALLATIONS = [{ id: 1, github_installation_id: 42, account_login: 'example-org', account_type: 'Organization', status: 'active' }];

const DESTINATION = {
  id: 9,
  installation: { id: 1, account_login: 'example-org' },
  kind: 'discord',
  label: 'Release channel with a deliberately long name to test wrapping in narrow tables',
  url_host: 'discord.com',
  min_severity: 'high',
  enabled: true,
  created_at: '2026-09-29T10:00:00.000Z',
  updated_at: '2026-09-29T10:00:00.000Z',
  sent_count: 3,
  last_delivery: { status: 'failed', http_status: 404, error: 'HTTP 404', attempted_at: '2026-09-29T11:00:00.000Z' },
};

const REPOS = [
  { id: 1, full_name: 'example-org/public-site', is_private: false, llm_policy: 'public_only', analysis_enabled: true, default_branch: 'main' },
  { id: 2, full_name: 'example-org/internal-tool-with-a-long-name', is_private: true, llm_policy: 'none', analysis_enabled: false, default_branch: 'main' },
];

/** A fake API; `posts` records every body sent to POST /api/destinations. */
async function fakeApi(page, { role = 'admin', destinations = [DESTINATION], encryption = true } = {}) {
  const posts = [];
  await page.route('**/api/**', (route) => json(route, 404, { error: { code: 'not_found', message: 'Not mocked.' } }));
  await page.route('**/api/auth/session', (route) =>
    json(route, 200, { user: { id: 1, username: `e2e-${role}`, role }, csrf_token: 'csrf' }),
  );
  await page.route('**/api/system/health', (route) =>
    json(route, 200, { status: 'ok', checked_at: '2026-09-29T12:00:00Z', environment: 'test', components: [] }),
  );
  await page.route(/\/api\/repositories(\?.*)?$/, (route) => json(route, 200, { items: REPOS }));
  await page.route('**/api/destinations', (route) => {
    if (route.request().method() === 'POST') {
      const body = route.request().postDataJSON();
      posts.push(body);
      if (body.url.includes('?')) {
        return json(route, 422, {
          error: { code: 'validation_failed', message: 'Some fields need attention.', fields: { url: 'Webhook URLs must not have a query string or fragment.' } },
        });
      }
      return json(route, 201, { ...DESTINATION, id: 10, kind: body.kind, label: body.label, url_host: 'hooks.slack.com' });
    }
    return json(route, 200, { items: destinations, installations: INSTALLATIONS, encryption_configured: encryption });
  });
  return posts;
}

for (const width of WIDTHS) {
  test(`integrations page does not overflow · ${width}px`, async ({ page }) => {
    await fakeApi(page);
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/integrations');
    await expect(page.getByRole('region', { name: 'Repository analysis settings' })).toBeVisible();
    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth, `document is ${scrollWidth}px wide in a ${clientWidth}px viewport`).toBeLessThanOrEqual(clientWidth);
  });
}

test('admins add a destination; server field errors land on the field; the URL is never shown back', async ({ page }) => {
  const posts = await fakeApi(page, { destinations: [] });
  await page.goto('/integrations');

  const form = page.getByRole('form', { name: 'Add a destination' });
  await form.getByRole('button', { name: 'Add destination' }).click();
  await expect(form.getByText('Give it a name you’ll recognise')).toBeVisible();
  await expect(form.getByLabel('Name')).toBeFocused();
  expect(posts).toHaveLength(0);

  await form.getByLabel('Name').fill('#eng-alerts');
  await form.getByLabel('Webhook URL').fill('http://hooks.slack.com/services/T0/B0/x');
  await form.getByRole('button', { name: 'Add destination' }).click();
  await expect(form.getByText('Webhook URLs must use https.')).toBeVisible();
  expect(posts).toHaveLength(0);

  await form.getByLabel('Webhook URL').fill('https://hooks.slack.com/services/T0/B0/x?next=1');
  await form.getByRole('button', { name: 'Add destination' }).click();
  await expect(form.getByText('must not have a query string')).toBeVisible();
  await expect(form.getByLabel('Webhook URL')).toBeFocused();
  expect(posts).toHaveLength(1);
  expect(posts[0]).toMatchObject({ installation_id: 1, kind: 'slack', label: '#eng-alerts', min_severity: 'high' });

  await form.getByLabel('Webhook URL').fill('https://hooks.slack.com/services/T0/B0/fakefake');
  await form.getByRole('button', { name: 'Add destination' }).click();
  await expect(page.getByText('Added “#eng-alerts”. The URL is stored encrypted')).toBeVisible();
  await expect(form.getByLabel('Webhook URL')).toHaveValue('');
  await expect(form.getByLabel('Webhook URL')).toHaveAttribute('type', 'password');
  expect(await page.content()).not.toContain('fakefake');
});

test('viewers see settings read-only', async ({ page }) => {
  await fakeApi(page, { role: 'viewer' });
  await page.goto('/integrations');

  await expect(page.getByText('Release channel with a deliberately long name')).toBeVisible();
  await expect(page.getByText('Only administrators can change destinations.')).toBeVisible();
  await expect(page.getByRole('form', { name: 'Add a destination' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Remove' })).toHaveCount(0);
  await expect(page.getByRole('checkbox')).toHaveCount(0);
  await expect(page.getByRole('combobox')).toHaveCount(0);
  // Private repo on "Off": the note explains nothing is sent.
  await expect(page.getByText('No code is sent to an AI provider.')).toBeVisible();
});

test('without an encryption key the page says why destinations cannot be added', async ({ page }) => {
  await fakeApi(page, { destinations: [], encryption: false });
  await page.goto('/integrations');
  await expect(page.getByText(/Notifications are off: the server has no/)).toBeVisible();
  await expect(page.getByRole('form', { name: 'Add a destination' })).toHaveCount(0);
});

test('a focused field is never hidden under the sticky bars on a phone', async ({ page }) => {
  await fakeApi(page, { destinations: [] });
  await page.setViewportSize({ width: 375, height: 700 });
  await page.goto('/integrations');
  await expect(page.getByRole('form', { name: 'Add a destination' })).toBeVisible();

  for (const label of ['GitHub account', 'Name', 'Webhook URL']) {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.getByLabel(label, { exact: true }).focus();
    const { fieldTop, stickyBottom } = await page.evaluate((l) => {
      const field = [...document.querySelectorAll('label')].find((x) => x.textContent === l).control;
      const bars = [...document.querySelectorAll('.topbar, .statusbar')].map((e) => e.getBoundingClientRect().bottom);
      return { fieldTop: field.getBoundingClientRect().top, stickyBottom: Math.max(...bars) };
    }, label);
    expect(fieldTop, `${label} is at ${fieldTop}px, under bars ending at ${stickyBottom}px`).toBeGreaterThanOrEqual(stickyBottom);
  }
});
