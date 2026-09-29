import base from '../playwright.config.js';

// Frontend timing runs (not part of the normal suite):
//   npx playwright test -c perf/playwright.perf.config.js
// Same production build and preview server as the e2e suite, one worker so
// runs don't compete for CPU.
export default {
  ...base,
  testDir: '.',
  testMatch: /.*\.perf\.js$/,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: 'list',
  webServer: { ...base.webServer, url: 'http://127.0.0.1:4173' },
};
