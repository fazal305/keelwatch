import { SEVERITIES } from '../lib/status.js';

const SHAPES = {
  critical: <path d="M5 0.5 9.5 5 5 9.5 0.5 5z" />, // diamond, filled
  high: <path d="M5 1 9 9H1z" />, // triangle
  medium: <rect x="1.5" y="1.5" width="7" height="7" />,
  low: <circle cx="5" cy="5" r="3.5" />,
  info: <circle cx="5" cy="5" r="3" fill="none" strokeWidth="1.5" stroke="currentColor" />,
};

/** Severity is always shape + word, never colour alone. */
export function SeverityTag({ severity }) {
  return (
    <span className={`sev sev--${severity}`}>
      <svg className="sev__shape" viewBox="0 0 10 10" aria-hidden="true" focusable="false" fill="currentColor">
        {SHAPES[severity] ?? SHAPES.info}
      </svg>
      <span className="sev__label">{severity}</span>
    </span>
  );
}

/** "1 critical · 2 low", or an explicit "none" when the map is empty. */
export function SeverityCounts({ counts, emptyLabel = 'none' }) {
  const present = SEVERITIES.filter((s) => counts?.[s] > 0);
  if (present.length === 0) {
    return <span className="muted">{emptyLabel}</span>;
  }
  return (
    <span className="sev-counts">
      {present.map((s) => (
        <span key={s} className={`sev-count sev--${s}`}>
          <span className="num">{counts[s]}</span> {s}
        </span>
      ))}
    </span>
  );
}
