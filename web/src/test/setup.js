import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// Page tests chain several mocked requests (session, health, page data). With
// every file running in parallel, the 1 s default for findBy*/waitFor flaked;
// 5 s only matters when something is genuinely slow.
configure({ asyncUtilTimeout: 5000 });

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

// jsdom lacks matchMedia; default to a desktop, dark-scheme viewport.
if (!window.matchMedia) {
  window.matchMedia = (query) => ({
    matches: false,
    media: query,
    addEventListener() {},
    removeEventListener() {},
  });
}
