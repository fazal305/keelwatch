/**
 * Turns poller state into what the UI shows. Pure, so every combination of
 * report / error / offline is unit-testable.
 */
const PROBED = ['api', 'database', 'workers'];

const UNREACHABLE_KINDS = new Set(['network', 'unreachable', 'timeout', 'http', 'parse']);

export function deriveHealth(state) {
  const { phase, report, error, online } = state;

  if (report) {
    return {
      overall: report.status,
      components: report.components.map((c) => ({ name: c.name, status: c.status, summary: c.summary })),
      // Showing the last good report after a failed poll or while offline.
      stale: Boolean(error) || !online,
      reason: !online ? 'offline' : error ? 'refresh_failed' : null,
    };
  }

  if (!online || error?.kind === 'offline') {
    return {
      overall: 'unknown',
      components: PROBED.map((name) => ({ name, status: 'unknown', summary: 'Offline.' })),
      stale: false,
      reason: 'offline',
    };
  }

  if (phase === 'error' && UNREACHABLE_KINDS.has(error?.kind)) {
    return {
      overall: 'down',
      components: PROBED.map((name) =>
        name === 'api'
          ? { name, status: 'down', summary: error.message }
          : { name, status: 'unknown', summary: 'Unknown while the API is unreachable.' },
      ),
      stale: false,
      reason: 'api_unreachable',
    };
  }

  return {
    overall: 'checking',
    components: PROBED.map((name) => ({ name, status: 'checking', summary: 'Checking…' })),
    stale: false,
    reason: null,
  };
}
