/**
 * One vocabulary for every status the UI shows. Each state has a tone
 * (colour token), a shape, and a text label, so state is never conveyed by
 * colour alone.
 */
const STATES = {
  // system components and workers
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

  // analysis runs
  run_queued: { tone: 'neutral', shape: 'ring', label: 'Queued' },
  run_running: { tone: 'info', shape: 'circle', label: 'Running' },
  run_checkpointed: { tone: 'warning', shape: 'triangle', label: 'Checkpointed' },
  run_completed: { tone: 'success', shape: 'circle', label: 'Completed' },
  run_failed: { tone: 'danger', shape: 'square', label: 'Failed' },
  run_cancelled: { tone: 'neutral', shape: 'diamond', label: 'Cancelled' },

  // pipeline phases
  phase_running: { tone: 'info', shape: 'ring', label: 'Running' },
  phase_completed: { tone: 'success', shape: 'circle', label: 'Completed' },
  phase_skipped: { tone: 'neutral', shape: 'diamond', label: 'Skipped' },
  phase_failed: { tone: 'danger', shape: 'square', label: 'Failed' },

  // webhook deliveries and notifications
  delivery_accepted: { tone: 'success', shape: 'circle', label: 'Accepted' },
  delivery_ignored: { tone: 'neutral', shape: 'diamond', label: 'Ignored' },
  notify_sent: { tone: 'success', shape: 'circle', label: 'Sent' },
  notify_failed: { tone: 'danger', shape: 'square', label: 'Failed' },
  notify_skipped: { tone: 'neutral', shape: 'diamond', label: 'Skipped' },
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

export const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

export const PHASE_LABELS = {
  load_event: 'Load event',
  extract_changes: 'Extract changes',
  secrets: 'Secrets',
  dependencies: 'Dependencies',
  structure: 'Structure',
  context: 'Review context',
  llm_review: 'AI review',
  normalize_findings: 'Store findings',
  digest: 'Digest',
};
