import { expect, test } from '@playwright/test';

const USER = { id: 1, username: 'e2e-viewer', role: 'viewer' };
const SESSION = { user: USER, csrf_token: 'e2e-csrf-token' };
const EMPTY_PAGE = { items: [], next_before: null };

const json = (route, status, body) =>
  route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

const unauthenticated = (route) =>
  json(route, 401, { error: { code: 'unauthenticated', message: 'Sign in to continue.' } });

/**
 * A tiny fake API: the session is signed out until /api/auth/login succeeds.
 * `runs` decides how the runs list answers, so a test can expire the session mid-visit.
 */
async function fakeApi(page, { signedIn = false, runs = () => EMPTY_PAGE } = {}) {
  const state = { signedIn, logins: [] };
  // Registered first, so it only catches what the specific handlers below don't.
  await page.route('**/api/**', (route) => json(route, 200, EMPTY_PAGE));
  await page.route('**/api/overview', (route) =>
    json(route, 200, { repositories: 0, recent_events: [], recent_runs: [], attention: {} }),
  );
  await page.route('**/api/system/health', (route) =>
    state.signedIn
      ? json(route, 200, { status: 'ok', checked_at: '2026-09-29T12:00:00Z', environment: 'test', components: [] })
      : unauthenticated(route),
  );
  await page.route('**/api/auth/session', (route) =>
    state.signedIn ? json(route, 200, SESSION) : unauthenticated(route),
  );
  await page.route('**/api/auth/login', (route) => {
    const body = route.request().postDataJSON();
    state.logins.push({ username: body.username, csrf: route.request().headers()['x-csrf-token'] ?? null });
    if (body.password !== 'correct-horse') return unauthenticated(route);
    state.signedIn = true;
    return json(route, 200, SESSION);
  });
  await page.route(/\/api\/runs(\?.*)?$/, (route) => {
    const result = runs(state);
    return result === 401 ? unauthenticated(route) : json(route, 200, result);
  });
  return state;
}

test('a signed-out visit redirects to sign-in and returns to the page afterwards', async ({ page }) => {
  const api = await fakeApi(page);
  await page.goto('/runs?status=failed');

  await expect(page).toHaveURL(/\/signin$/);
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expect(page).toHaveTitle('Sign in · Keelwatch');

  await page.getByLabel('Username').fill('e2e-viewer');
  await page.getByLabel('Password').fill('wrong');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('alert')).toHaveText('Incorrect username or password.');
  await expect(page.getByLabel('Password')).toHaveValue('');
  await expect(page.getByLabel('Password')).toBeFocused();

  await page.getByLabel('Password').fill('correct-horse');
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page).toHaveURL(/\/runs\?status=failed$/);
  await expect(page.getByRole('heading', { name: 'Analysis runs', level: 1 })).toBeVisible();
  expect(api.logins.map((l) => l.username)).toEqual(['e2e-viewer', 'e2e-viewer']);
});

test('empty fields are reported inline without calling the API', async ({ page }) => {
  const api = await fakeApi(page);
  await page.goto('/signin');

  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText('Enter your username.')).toBeVisible();
  await expect(page.getByLabel('Username')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.getByLabel('Username')).toBeFocused();
  expect(api.logins).toHaveLength(0);
});

test('an expired session sends the user to sign-in with an explanation, then back', async ({ page }) => {
  let expire = false;
  const api = await fakeApi(page, {
    signedIn: true,
    runs: (state) => {
      if (expire) {
        state.signedIn = false;
        expire = false;
        return 401;
      }
      return EMPTY_PAGE;
    },
  });

  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Overview', level: 1 })).toBeVisible();

  expire = true;
  await page.getByRole('link', { name: 'Analysis runs' }).first().click();

  await expect(page).toHaveURL(/\/signin$/);
  await expect(page.getByRole('status').filter({ hasText: 'Your session expired' })).toBeVisible();

  await page.getByLabel('Username').fill('e2e-viewer');
  await page.getByLabel('Password').fill('correct-horse');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/runs$/);
  await expect(page.getByRole('heading', { name: 'Analysis runs', level: 1 })).toBeVisible();
  expect(api.signedIn).toBe(true);
});

test('the sign-in page does not overflow on a phone', async ({ page }) => {
  await fakeApi(page);
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/signin');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  const { scrollWidth, clientWidth } = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
});
