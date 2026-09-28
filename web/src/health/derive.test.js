import { describe, expect, it } from 'vitest';
import { initialHealthState } from './HealthContext.js';
import { deriveHealth } from './derive.js';

const report = {
  status: 'degraded',
  components: [
    { name: 'api', status: 'ok', summary: 'Serving requests.' },
    { name: 'database', status: 'ok', summary: 'Connected.' },
    { name: 'workers', status: 'down', summary: 'No worker has reported a heartbeat yet.' },
  ],
};

const base = { ...initialHealthState(), online: true };

describe('deriveHealth', () => {
  it('shows checking before the first response', () => {
    const d = deriveHealth(base);
    expect(d.overall).toBe('checking');
    expect(d.components.map((c) => c.status)).toEqual(['checking', 'checking', 'checking', 'checking']);
  });

  it('passes a report through', () => {
    const d = deriveHealth({ ...base, phase: 'ready', report });
    expect(d.overall).toBe('degraded');
    expect(d.components.map((c) => c.status)).toEqual(['ok', 'ok', 'down']);
    expect(d.stale).toBe(false);
  });

  it('marks the last report stale when a refresh fails', () => {
    const d = deriveHealth({ ...base, phase: 'ready', report, error: { kind: 'network' } });
    expect(d.stale).toBe(true);
    expect(d.reason).toBe('refresh_failed');
  });

  it('reports the API down and the rest unknown when unreachable with no data', () => {
    const d = deriveHealth({
      ...base,
      phase: 'error',
      error: { kind: 'unreachable', message: 'The Keelwatch API is not reachable.' },
    });
    expect(d.overall).toBe('down');
    expect(d.components.map((c) => c.status)).toEqual(['down', 'unknown', 'unknown', 'unknown']);
  });

  it('reports unknown, not down, when the browser is offline', () => {
    const d = deriveHealth({ ...base, online: false, phase: 'error', error: { kind: 'offline' } });
    expect(d.overall).toBe('unknown');
    expect(d.reason).toBe('offline');
  });
});
