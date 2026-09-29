import { test } from '@playwright/test';

/**
 * Page load timings of the production build with the API mocked to answer
 * instantly, so the numbers are frontend cost only (download, parse, render).
 * Two profiles: this machine unthrottled, and a mid-range phone approximation
 * (4x CPU slowdown, ~1.6 Mbps / 150 ms RTT). Prints a table; asserts nothing,
 * because a timing is a measurement, not a pass/fail.
 */

const RUNS = 5;
const PROFILES = [
  { name: 'desktop', cpu: 1, network: null },
  { name: 'phone (4x CPU, slow 4G)', cpu: 4, network: { latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 } },
];
const PAGES = [
  ['/', 'Overview'],
  ['/findings', 'Findings'],
  ['/analytics', 'Analytics'],
  ['/readiness', 'Readiness'],
];

const json = (route, body) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });

async function mockApi(page) {
  const finding = (i) => ({ id: i, severity: ['critical', 'high', 'medium', 'low', 'info'][i % 5], title: `Finding ${i}`, source: 'rule', category: 'quality', recurring: i % 2 === 0, repository: { id: 1, full_name: 'acme/api' }, file_path: `src/f${i}.js`, line: i, confidence: 'medium' });
  const days = Array.from({ length: 30 }, (_, i) => ({ date: new Date(Date.UTC(2026, 8, i + 1)).toISOString().slice(0, 10), runs: 8, runs_failed: i % 4 === 0 ? 1 : 0, findings_new: i % 3, findings_recurring: 2, completed_runs: 7, duration_p50_ms: 1800, duration_p95_ms: 5200 }));
  await page.route('**/api/**', (r) => json(r, {}));
  await page.route('**/api/auth/session', (r) => json(r, { user: { id: 1, username: 'perf', role: 'viewer' }, csrf_token: 'c' }));
  await page.route('**/api/system/health', (r) => json(r, { status: 'ok', checked_at: '2026-09-29T12:00:00Z', environment: 'test', components: [] }));
  await page.route('**/api/overview', (r) => json(r, { repositories: 25, recent_events: [], recent_runs: [], runs_7d: 1500, events_24h: 400, attention: { failed_runs_24h: 3, checkpointed_runs: 1, dead_jobs: 0, latest_findings_by_severity: { high: 4, low: 9 } } }));
  await page.route(/\/api\/findings(\?.*)?$/, (r) => json(r, { items: Array.from({ length: 50 }, (_, i) => finding(i + 1)), next_before: 1 }));
  await page.route(/\/api\/repositories(\?.*)?$/, (r) => json(r, { items: [] }));
  await page.route(/\/api\/analytics(\?.*)?$/, (r) => json(r, { range: { days: 30, start: days[0].date, end: days[29].date, timezone: 'UTC' }, repository_id: null, watching_since: days[0].date, min_duration_samples: 3, totals: { runs: 240, runs_failed: 8, findings_new: 30, findings_recurring: 60, completed_runs: 210, duration_p50_ms: 1800, notifications: { sent: 20, failed: 0 } }, findings_by_severity: { critical: 1, high: 5, medium: 20, low: 30, info: 34 }, findings_by_category: { security: 5, dependency: 10, logic: 5, architecture: 2, quality: 68 }, days }));
  await page.route(/\/api\/readiness(\?.*)?$/, (r) => json(r, { window_days: 90, generated_at: '2026-09-29T12:00:00Z', criteria: [], accounts: [], account_options: [] }));
}

test('page load timings', async ({ browser }) => {
  test.setTimeout(300_000);
  const rows = [];
  for (const profile of PROFILES) {
    for (const [path, heading] of PAGES) {
      const samples = [];
      for (let i = 0; i < RUNS; i++) {
        // A fresh context per run: cold cache, like a first visit.
        const context = await browser.newContext();
        const page = await context.newPage();
        const cdp = await context.newCDPSession(page);
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: profile.cpu });
        if (profile.network) await cdp.send('Network.emulateNetworkConditions', { offline: false, ...profile.network });
        await mockApi(page);
        const start = Date.now();
        await page.goto(path);
        await page.getByRole('heading', { level: 1, name: heading }).waitFor();
        const ready = Date.now() - start;
        const nav = await page.evaluate(() => {
          const n = performance.getEntriesByType('navigation')[0];
          const fcp = performance.getEntriesByName('first-contentful-paint')[0];
          const bytes = performance.getEntriesByType('resource').filter((r) => /\.(js|css)$/.test(r.name)).reduce((a, r) => a + r.transferSize, 0) + n.transferSize;
          return { fcp: fcp?.startTime ?? null, dcl: n.domContentLoadedEventEnd, bytes };
        });
        samples.push({ ...nav, ready });
        await context.close();
      }
      const med = (k) => [...samples.map((s) => s[k])].sort((a, b) => a - b)[Math.floor(RUNS / 2)];
      rows.push({ profile: profile.name, page: path, fcp_ms: Math.round(med('fcp')), dcl_ms: Math.round(med('dcl')), heading_visible_ms: med('ready'), js_css_html_kb: Math.round(med('bytes') / 1024) });
    }
  }
  console.log('\nMedian of', RUNS, 'cold loads each:');
  console.table(rows);
});
