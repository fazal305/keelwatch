import { useEffect, useRef, useState } from 'react';
import { useHealth } from '../health/HealthContext.js';
import { deriveHealth } from '../health/derive.js';
import { formatAge, secondsSince } from '../lib/format.js';
import { COMPONENT_LABELS, describeStatus } from '../lib/status.js';
import { useNow } from '../lib/useNow.js';
import { Icon } from './Icon.jsx';
import { StatusIndicator } from './StatusIndicator.jsx';

/**
 * Always-visible pipeline status. The user should never have to open a
 * page to learn whether the system is alive.
 */
export function StatusBar() {
  const health = useHealth();
  const now = useNow(1000);
  const { overall, components, stale, reason } = deriveHealth(health);
  const announcement = useOverallAnnouncement(overall);

  let freshness;
  if (!health.online) {
    freshness = 'Offline — updates paused';
  } else if (health.lastUpdated) {
    const age = formatAge(secondsSince(health.lastUpdated, now));
    freshness = stale ? `Update failed · last data ${age}` : `Checked ${age}`;
  } else {
    freshness = health.fetching ? 'Checking…' : 'Not checked yet';
  }

  return (
    <div className="statusbar" data-stale={stale || undefined}>
      <div className="statusbar__components">
        <span className="label statusbar__title">Pipeline</span>
        <ul className="statusbar__list" aria-label="Component status">
          {components.map((c) => (
            <li key={c.name} className="statusbar__item" title={c.summary}>
              <span className="statusbar__name">{COMPONENT_LABELS[c.name] ?? c.name}</span>
              <StatusIndicator status={c.status} size="sm" />
            </li>
          ))}
        </ul>
      </div>
      <div className={`statusbar__freshness${stale ? ' is-stale' : ''}`}>
        {reason === 'offline' && <Icon name="offline" size={16} />}
        <span className="num">{freshness}</span>
      </div>
      <p className="visually-hidden" aria-live="polite" aria-atomic="true">
        {announcement}
      </p>
    </div>
  );
}

/** Announce the overall status only when it changes, not on every poll. */
function useOverallAnnouncement(overall) {
  const [message, setMessage] = useState('');
  const previous = useRef(overall);

  useEffect(() => {
    if (overall === previous.current || overall === 'checking') return;
    previous.current = overall;
    const id = setTimeout(() => setMessage(`System status: ${describeStatus(overall).label}`), 0);
    return () => clearTimeout(id);
  }, [overall]);

  return message;
}
