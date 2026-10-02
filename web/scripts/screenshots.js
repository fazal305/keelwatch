// Captures the README screenshots from a running dashboard.
//
// Start the API, worker and `npm run dev` against a database filled by
// scripts/seed_demo.py, then:
//
//   KEELWATCH_USER=demo KEELWATCH_PASSWORD=... npm run screenshots
//
// Optional: KEELWATCH_WEB_URL (default http://localhost:5173) and
// PLAYWRIGHT_CHROMIUM (path to a Chromium binary, if Playwright's own isn't installed).
import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const BASE = process.env.KEELWATCH_WEB_URL || 'http://localhost:5173';
const OUT = fileURLToPath(new URL('../../docs/screenshots/', import.meta.url));
const { KEELWATCH_USER: user, KEELWATCH_PASSWORD: password } = process.env;

// [file name, path, element that must be visible before capturing]
const PAGES = [
  ['overview', '/', '.stats'],
  ['findings', '/findings', 'table'],
  ['run-detail', null, 'h1'],
  ['readiness', '/readiness', 'h1'],
  ['analytics', '/analytics?days=30', '.analytics-grid__wide canvas, .analytics-grid__wide svg'],
  ['digests', '/digests', 'table'],
  ['system-health', '/system', 'h1'],
];

if (!user || !password) {
  console.error('Set KEELWATCH_USER and KEELWATCH_PASSWORD.');
  process.exit(2);
}
await mkdir(OUT, { recursive: true });
const browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM || undefined });

for (const scheme of ['dark', 'light']) {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, colorScheme: scheme });
  await page.goto(`${BASE}/signin`);
  await page.getByLabel('Username').fill(user);
  await page.getByLabel('Password').fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/signin'));

  for (const [name, path, ready] of PAGES) {
    if (path) {
      await page.goto(`${BASE}${path}`);
    } else {
      // Run detail: the newest completed run that has findings.
      await page.goto(`${BASE}/runs?status=completed`);
      const rows = page.locator('tbody tr');
      await rows.first().waitFor();
      const count = await rows.count();
      let href = null;
      for (let i = 0; i < count && !href; i++) {
        const findings = Number((await rows.nth(i).locator('td.num').innerText()).trim());
        if (findings > 0) href = await rows.nth(i).locator('a').first().getAttribute('href');
      }
      await page.goto(`${BASE}${href}`);
    }
    await page.locator(ready).first().waitFor();
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: `${OUT}${name}-${scheme}.png` });
    console.log(`${name}-${scheme}.png`);
  }
  await page.close();
}
await browser.close();
