import { describeStatus } from '../lib/status.js';

function Shape({ shape }) {
  switch (shape) {
    case 'triangle':
      return <path d="M5 1.5 9 8.5H1z" className="status-shape--fill" />;
    case 'square':
      return <rect x="1.5" y="1.5" width="7" height="7" className="status-shape--fill" />;
    case 'diamond':
      return <path d="M5 1 9 5 5 9 1 5z" className="status-shape--outline" />;
    case 'ring':
      return <circle cx="5" cy="5" r="3.5" className="status-shape--outline" />;
    default:
      return <circle cx="5" cy="5" r="4" className="status-shape--fill" />;
  }
}

/**
 * Status = shape + colour + text. The text label is always rendered
 * (visually or for screen readers), so colour is never the only signal.
 */
export function StatusIndicator({ status, label, hideLabel = false, size = 'md' }) {
  const { tone, shape, label: defaultLabel } = describeStatus(status);
  const text = label ?? defaultLabel;

  return (
    <span className={`status status--${tone} status--${size}`} data-status={status}>
      <svg className="status__shape" viewBox="0 0 10 10" aria-hidden="true" focusable="false">
        <Shape shape={shape} />
      </svg>
      <span className={hideLabel ? 'visually-hidden' : 'status__label'}>{text}</span>
    </span>
  );
}
