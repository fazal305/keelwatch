import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { App } from './App.jsx';

/**
 * Phase 10 hardening: the states that are easy to break silently.
 */

const SESSION = { user: { id: 1, username: 'viewer', role: 'viewer' }, csrf_token: 'csrf-token' };
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'X-Request-Id': 'req-ux-0001' } });
const health = () => json({ status: 'ok', checked_at: '2026-09-29T12:00:00Z', environment: 'test', components: [] });
const finding = (id) => ({
  id,
  severity: 'low',
  title: `Finding ${id}`,
  source: 'rule',
  category: 'quality',
  recurring: false,
  repository: { id: 1, full_name: 'acme/api' },
  file_path: null,
  line: null,
  confidence: 'low',
});

function setOnline(value) {
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => value });
}

/** Routes by path; `findings` decides each /api/findings answer. */
function stubApi(findings) {
  const calls = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input) => {
      const url = new URL(input, 'http://localhost');
      if (url.pathname === '/api/auth/session') return json(SESSION);
      if (url.pathname === '/api/system/health') return health();
      if (url.pathname === '/api/findings') {
        calls.push(url.search);
        return findings(url, calls.length);
      }
      throw new Error(`unexpected request: ${url.pathname}`);
    }),
  );
  return calls;
}

function renderAt(path) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  );
}

afterEach(() => {
  setOnline(true);
  vi.unstubAllGlobals();
});

describe('offline', () => {
  it('explains the page will recover, then reloads by itself when the connection returns', async () => {
    const calls = stubApi((_url, n) => {
      if (n === 1) {
        // The connection drops as the page loads its data.
        setOnline(false);
        throw new TypeError('Failed to fetch');
      }
      return json({ items: [finding(1)], next_before: null });
    });
    renderAt('/findings');

    expect(await screen.findByText(/This reloads by itself when you're back online/)).toBeInTheDocument();
    expect(calls).toHaveLength(1);

    setOnline(true);
    act(() => {
      window.dispatchEvent(new Event('online'));
    });
    expect(await screen.findByRole('link', { name: 'Finding 1' })).toBeInTheDocument();
    expect(calls).toHaveLength(2);
    expect(screen.queryByText(/reloads by itself/)).not.toBeInTheDocument();
  });

  it('a server error is not retried just because the browser fired "online"', async () => {
    const calls = stubApi(() => json({ error: { code: 'internal', message: 'Something went wrong.' } }, 500));
    renderAt('/findings');
    expect(await screen.findByText("Couldn't load findings")).toBeInTheDocument();
    act(() => {
      window.dispatchEvent(new Event('online'));
    });
    await new Promise((r) => setTimeout(r, 50));
    expect(calls).toHaveLength(1);
  });

  it('says so when the browser is offline before the session can even be checked', async () => {
    setOnline(false);
    stubApi(() => json({ items: [finding(1)], next_before: null }));
    // Offline before the first render: the session check itself can't run.
    renderAt('/findings');
    expect(await screen.findByText('Keelwatch is not reachable')).toBeInTheDocument();
    expect(screen.getByText('You appear to be offline.')).toBeInTheDocument();
  });
});

describe('invalid filters in a link', () => {
  it('names the bad filter and offers to clear it instead of a retry that cannot work', async () => {
    const calls = stubApi((url) =>
      url.searchParams.get('severity') === 'urgent'
        ? json({ error: { code: 'invalid_query', message: 'Some filters are invalid.', fields: { severity: 'Must be one of: critical, high, medium, low, info.' } } }, 422)
        : json({ items: [], next_before: null }),
    );
    renderAt('/findings?severity=urgent');

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('This link has filters that aren’t valid');
    expect(alert).toHaveTextContent('severity: Must be one of');
    expect(screen.queryByRole('button', { name: /Try again|Retry/ })).not.toBeInTheDocument();

    fireEvent.click(screen.getAllByRole('button', { name: 'Clear filters' }).at(-1));
    expect(await screen.findByText('No findings yet')).toBeInTheDocument();
    expect(calls.at(-1)).toBe('');
  });
});

describe('load more', () => {
  it('retrying a failed "load more" keeps the pages already shown', async () => {
    stubApi((url, n) => {
      if (!url.searchParams.get('before')) return json({ items: [finding(3), finding(2)], next_before: 2 });
      // First attempt at page 2 fails, the retry succeeds.
      return n === 2
        ? json({ error: { code: 'internal', message: 'Something went wrong.', correlation_id: 'corr-ux-9' } }, 500)
        : json({ items: [finding(1)], next_before: null });
    });
    renderAt('/findings');

    fireEvent.click(await screen.findByRole('button', { name: /Load more/ }));
    expect(await screen.findByText("Couldn't refresh findings")).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Finding 3' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Try again|Retry/ }));
    expect(await screen.findByRole('link', { name: 'Finding 1' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Finding 3' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Finding 2' })).toBeInTheDocument();
  });
});
