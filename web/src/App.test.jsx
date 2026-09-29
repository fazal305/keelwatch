import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { App } from './App.jsx';

const SESSION = { user: { id: 1, username: 'viewer', role: 'viewer' }, csrf_token: 'csrf-token' };

function jsonResponse(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'X-Request-Id': 'req-12345678' } });
}

/** Routes fetch by path; unmatched paths fail loudly so a test never passes by accident. */
function stubApi(routes) {
  const fetchMock = vi.fn(async (input, init) => {
    const url = new URL(typeof input === 'string' ? input : input.url, 'http://localhost');
    const handler = routes[url.pathname];
    if (!handler) throw new Error(`unexpected request: ${url.pathname}`);
    return handler(url, init);
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

function renderAt(path) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  );
}

const signedIn = () => jsonResponse(SESSION);
const health = () => jsonResponse({ status: 'ok', checked_at: '2026-09-29T12:00:00Z', environment: 'test', components: [] });
const page = (items) => () => jsonResponse({ items, next_before: null });

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('sign-in gate', () => {
  it('says the API is unreachable instead of showing a sign-in form it cannot submit', async () => {
    stubApi({ '/api/auth/session': () => new Response('', { status: 502 }) });
    renderAt('/runs');

    expect(await screen.findByText('Keelwatch is not reachable')).toBeInTheDocument();
    expect(screen.queryByLabelText('Password')).not.toBeInTheDocument();
  });

  it('explains rate limiting and clears the password', async () => {
    stubApi({
      '/api/auth/session': () => jsonResponse({ error: { code: 'unauthenticated', message: 'Sign in.' } }, 401),
      '/api/auth/login': () => jsonResponse({ error: { code: 'rate_limited', message: 'Too many attempts.' } }, 429),
    });
    renderAt('/');

    fireEvent.change(await screen.findByLabelText('Username'), { target: { value: 'viewer' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'guess' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Too many sign-in attempts');
    expect(screen.getByLabelText('Password')).toHaveValue('');
  });

  it('shows server field errors next to the right field', async () => {
    stubApi({
      '/api/auth/session': () => jsonResponse({ error: { code: 'unauthenticated', message: 'Sign in.' } }, 401),
      '/api/auth/login': () =>
        jsonResponse(
          { error: { code: 'validation_failed', message: 'Check the form.', fields: { username: 'Username is too long.' } } },
          422,
        ),
    });
    renderAt('/');

    fireEvent.change(await screen.findByLabelText('Username'), { target: { value: 'x'.repeat(300) } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'guess' } });
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByText('Username is too long.')).toBeInTheDocument();
    expect(screen.getByLabelText('Username')).toHaveAttribute('aria-invalid', 'true');
  });

  it('moves to the expired state when a data request returns 401 mid-session', async () => {
    stubApi({
      '/api/auth/session': signedIn,
      '/api/system/health': health,
      '/api/findings': () => jsonResponse({ error: { code: 'unauthenticated', message: 'Session expired.' } }, 401),
    });
    renderAt('/findings');

    expect(await screen.findByText(/Your session expired/)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
  });
});

describe('Findings page states', () => {
  it('distinguishes "no findings yet" from "nothing matches these filters"', async () => {
    stubApi({ '/api/auth/session': signedIn, '/api/system/health': health, '/api/findings': page([]) });
    const { unmount } = renderAt('/findings');
    expect(await screen.findByText('No findings yet')).toBeInTheDocument();
    expect(screen.getByText(/not proof of safety/)).toBeInTheDocument();
    unmount();

    renderAt('/findings?severity=high');
    expect(await screen.findByText('Nothing matches these filters')).toBeInTheDocument();
    // One in the filter bar, one inside the no-results state.
    expect(screen.getAllByRole('button', { name: 'Clear filters' })).toHaveLength(2);
  });

  it('shows an actionable error with a reference id when the list fails', async () => {
    stubApi({
      '/api/auth/session': signedIn,
      '/api/system/health': health,
      '/api/findings': () =>
        jsonResponse({ error: { code: 'internal', message: 'Something went wrong.', correlation_id: 'corr-abcdef12' } }, 500),
    });
    renderAt('/findings');

    expect(await screen.findByText("Couldn't load findings")).toBeInTheDocument();
    expect(screen.getByText(/corr-abcdef12/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Try again|Retry/ })).toBeInTheDocument();
  });

  it('renders a finding row with a severity label, not colour alone', async () => {
    stubApi({
      '/api/auth/session': signedIn,
      '/api/system/health': health,
      '/api/findings': page([
        {
          id: 7,
          severity: 'high',
          title: 'Hardcoded token',
          source: 'rule',
          category: 'security',
          recurring: false,
          repository: { id: 1, full_name: 'acme/api' },
          file_path: 'src/config.js',
          line: 12,
          confidence: 'high',
        },
      ]),
    });
    renderAt('/findings');

    const link = await screen.findByRole('link', { name: 'Hardcoded token' });
    expect(link).toHaveAttribute('href', '/findings/7');
    const row = link.closest('tr');
    expect(row.querySelector('.sev__label')).toHaveTextContent('high');
    expect(row.querySelector('.sev__shape')).not.toBeNull();
  });
});
