import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { describe, expect, it, vi } from 'vitest';
import { HealthProvider } from '../health/HealthProvider.jsx';
import { SystemHealth } from './SystemHealth.jsx';

function healthReport({ workers = [], workersStatus = 'down', workersSummary } = {}) {
  return {
    status: workersStatus === 'ok' ? 'ok' : 'degraded',
    checked_at: '2026-09-27T12:00:00Z',
    environment: 'development',
    components: [
      { name: 'api', status: 'ok', summary: 'Serving requests.', version: '0.1.0' },
      {
        name: 'database',
        status: 'ok',
        summary: 'Connected. Schema is up to date.',
        latency_ms: 1.4,
        pending_migrations: 0,
      },
      {
        name: 'workers',
        status: workersStatus,
        summary: workersSummary ?? 'No worker has reported a heartbeat yet.',
        stale_after_s: 35,
        workers,
      },
    ],
  };
}

function jsonResponse(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'X-Request-Id': 'req-12345678' } });
}

function renderPage() {
  return render(
    <MemoryRouter>
      <HealthProvider intervalMs={60_000}>
        <SystemHealth />
      </HealthProvider>
    </MemoryRouter>,
  );
}

describe('SystemHealth', () => {
  it('renders component states and the real empty state when no worker has reported', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(healthReport())));
    renderPage();

    expect(await screen.findByText('No worker has reported yet')).toBeInTheDocument();
    const api = screen.getByRole('region', { name: 'API: Operational' });
    expect(within(api).getByText('0.1.0')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Workers: Down' })).toBeInTheDocument();
    expect(screen.getByText(/running with problems/)).toBeInTheDocument();
  });

  it('lists worker heartbeats with status labels', async () => {
    const report = healthReport({
      workersStatus: 'ok',
      workersSummary: '1 worker(s) running.',
      workers: [
        {
          id: 'host-1234',
          status: 'running',
          version: '0.1.0',
          started_at: '2026-09-27T11:00:00Z',
          last_seen_at: '2026-09-27T11:59:58Z',
          last_seen_age_s: 2,
        },
        {
          id: 'host-9999',
          status: 'stale',
          version: '0.1.0',
          started_at: '2026-09-27T10:00:00Z',
          last_seen_at: '2026-09-27T11:50:00Z',
          last_seen_age_s: 600,
        },
      ],
    });
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(report)));
    renderPage();

    const table = await screen.findByRole('table');
    const rows = within(table).getAllByRole('row');
    expect(rows).toHaveLength(3);
    expect(within(rows[1]).getByText('Running')).toBeInTheDocument();
    expect(within(rows[2]).getByText('Stale')).toBeInTheDocument();
    expect(within(rows[2]).getByText('10m ago')).toBeInTheDocument();
  });

  it('shows an actionable error when the API is unreachable, and recovers on retry', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response('', { status: 502 }))
      .mockResolvedValueOnce(jsonResponse(healthReport()));
    vi.stubGlobal('fetch', fetchMock);
    renderPage();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent("Couldn't load system health");
    expect(alert).toHaveTextContent('The API process may not be running.');

    fireEvent.click(within(alert).getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText('No worker has reported yet')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('shows unknown worker counts as a dash, not zero, when the database is down', async () => {
    const report = healthReport({ workersStatus: 'unknown', workersSummary: 'Cannot read worker heartbeats.' });
    report.status = 'down';
    report.components[1] = { name: 'database', status: 'down', summary: 'Database is unreachable.', latency_ms: null, pending_migrations: null };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse(report)));
    renderPage();

    const card = await screen.findByRole('region', { name: 'Workers: Unknown' });
    expect(within(card).queryByText('0')).toBeNull();
    expect(within(card).getAllByText('—')).toHaveLength(2);
  });

  it('renders API-supplied strings as text, never as HTML', async () => {
    const hostile = '<img src=x onerror="window.__pwned=1">';
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(jsonResponse(healthReport({ workersSummary: hostile }))),
    );
    const { container } = renderPage();

    expect(await screen.findByText(hostile)).toBeInTheDocument();
    expect(container.querySelector('img')).toBeNull();
    expect(window.__pwned).toBeUndefined();
  });
});
