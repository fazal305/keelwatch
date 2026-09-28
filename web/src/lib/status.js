/**
 * One vocabulary for every status the UI shows. Each state has a tone
 * (colour token), a shape, and a text label, so state is never conveyed by
 * colour alone.
 */
const STATES = {
  ok: { tone: 'success', shape: 'circle', label: 'Operational' },
  running: { tone: 'success', shape: 'circle', label: 'Running' },
  ready: { tone: 'success', shape: 'circle', label: 'Ready' },
  degraded: { tone: 'warning', shape: 'triangle', label: 'Degraded' },
  stale: { tone: 'warning', shape: 'triangle', label: 'Stale' },
  stopping: { tone: 'warning', shape: 'triangle', label: 'Stopping' },
  down: { tone: 'danger', shape: 'square', label: 'Down' },
  unknown: { tone: 'neutral', shape: 'diamond', label: 'Unknown' },
  stopped: { tone: 'neutral', shape: 'diamond', label: 'Stopped' },
  checking: { tone: 'neutral', shape: 'ring', label: 'Checking' },
};

export function describeStatus(status) {
  return STATES[status] ?? STATES.unknown;
}

export const COMPONENT_LABELS = {
  api: 'API',
  database: 'Database',
  workers: 'Workers',
  queue: 'Queue',
};
