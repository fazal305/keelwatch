export function formatAge(seconds) {
  if (seconds == null || Number.isNaN(seconds)) return '—';
  const s = Math.max(0, seconds);
  if (s < 2) return 'just now';
  if (s < 60) return `${Math.floor(s)}s ago`;
  if (s < 3600) return `${Math.floor(s / 60)}m ago`;
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`;
  return `${Math.floor(s / 86400)}d ago`;
}

export function secondsSince(isoOrMs, now = Date.now()) {
  if (isoOrMs == null) return null;
  const then = typeof isoOrMs === 'number' ? isoOrMs : Date.parse(isoOrMs);
  if (Number.isNaN(then)) return null;
  return (now - then) / 1000;
}

const timeFormatter = new Intl.DateTimeFormat(undefined, {
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
});

const dateTimeFormatter = new Intl.DateTimeFormat(undefined, {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
});

export function formatClock(isoOrMs) {
  const date = new Date(isoOrMs);
  return Number.isNaN(date.getTime()) ? '—' : timeFormatter.format(date);
}

export function formatDateTime(iso) {
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? '—' : dateTimeFormatter.format(date);
}

/** 4.2 ms · 821 ms · 1.5 s · 2 min 5 s */
export function formatMs(ms) {
  if (ms == null) return '—';
  if (ms < 10) return `${ms.toFixed(1)} ms`;
  if (ms < 1000) return `${Math.round(ms)} ms`;
  if (ms < 60_000) return `${(ms / 1000).toFixed(1)} s`;
  const minutes = Math.floor(ms / 60_000);
  const seconds = Math.round((ms % 60_000) / 1000);
  return seconds ? `${minutes} min ${seconds} s` : `${minutes} min`;
}
