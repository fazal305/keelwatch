import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AuthContext } from '../auth/AuthContext.js';
import { Integrations } from './Integrations.jsx';

const REPO = { id: 3, full_name: 'acme/api', is_private: true, llm_policy: 'none', analysis_enabled: true };

function jsonResponse(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'X-Request-Id': 'req-12345678' } });
}

function renderAs(role, fetchImpl) {
  vi.stubGlobal('fetch', vi.fn(fetchImpl));
  return render(
    <MemoryRouter>
      <AuthContext.Provider value={{ status: 'signed-in', user: { id: 1, username: 'u', role }, isAdmin: role === 'admin' }}>
        <Integrations />
      </AuthContext.Provider>
    </MemoryRouter>,
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('Integrations', () => {
  it('keeps the toggle unchanged and shows a reference when saving fails', async () => {
    const calls = [];
    renderAs('admin', async (input, init) => {
      const path = new URL(input, 'http://localhost').pathname;
      calls.push(`${init?.method ?? 'GET'} ${path}`);
      if (path === '/api/destinations') return jsonResponse({ items: [], installations: [], encryption_configured: true });
      if (path === '/api/repositories') return jsonResponse({ items: [REPO] });
      return jsonResponse({ error: { code: 'internal', message: 'Something went wrong.', correlation_id: 'corr-9876abcd' } }, 500);
    });

    const toggle = await screen.findByRole('checkbox', { name: 'Analyse acme/api' });
    expect(toggle).toBeChecked();
    fireEvent.click(toggle);

    expect(await screen.findByText('Couldn’t save the setting')).toBeInTheDocument();
    expect(screen.getByText(/corr-9876abcd/)).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: 'Analyse acme/api' })).toBeChecked();
    expect(calls).toContain('PATCH /api/repositories/3/settings');
  });

  it('explains that a private repository on "Only while public" sends nothing', async () => {
    renderAs('viewer', async (input) => {
      const path = new URL(input, 'http://localhost').pathname;
      if (path === '/api/destinations') return jsonResponse({ items: [], installations: [], encryption_configured: true });
      return jsonResponse({ items: [{ ...REPO, llm_policy: 'public_only' }] });
    });

    expect(await screen.findByText('Off while this repository is private.')).toBeInTheDocument();
    expect(screen.getByText('Only while public')).toBeInTheDocument();
  });

  it('shows a rejected save even when the 422 carries no per-field errors', async () => {
    renderAs('admin', async (input, init) => {
      const path = new URL(input, 'http://localhost').pathname;
      if (path === '/api/destinations' && init?.method === 'POST') {
        return jsonResponse({ error: { code: 'validation_failed', message: 'This destination was rejected.' } }, 422);
      }
      if (path === '/api/destinations') {
        return jsonResponse({ items: [], installations: [{ id: 5, account_login: 'acme', status: 'active' }], encryption_configured: true });
      }
      return jsonResponse({ items: [] });
    });

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: '#alerts' } });
    fireEvent.change(screen.getByLabelText('Webhook URL'), { target: { value: 'https://hooks.slack.com/services/T0/B0/x' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add destination' }));

    expect(await screen.findByText('Couldn’t add the destination')).toBeInTheDocument();
    expect(screen.getByText('This destination was rejected.')).toBeInTheDocument();
  });

  it('tells admins there is no GitHub account to attach a destination to', async () => {
    renderAs('admin', async (input) => {
      const path = new URL(input, 'http://localhost').pathname;
      if (path === '/api/destinations') return jsonResponse({ items: [], installations: [], encryption_configured: true });
      return jsonResponse({ items: [] });
    });

    expect(await screen.findByText('No GitHub account to attach a destination to')).toBeInTheDocument();
    expect(screen.getByText('No repositories yet')).toBeInTheDocument();
  });
});
