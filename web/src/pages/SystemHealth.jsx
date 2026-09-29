import { useEffect, useState } from 'react';
import { EmptyState, InlineError, Panel } from '../components/Feedback.jsx';
import { Icon } from '../components/Icon.jsx';
import { StatusIndicator } from '../components/StatusIndicator.jsx';
import { useHealth } from '../health/HealthContext.js';
import { deriveHealth } from '../health/derive.js';
import { formatAge, formatDateTime, formatMs, secondsSince } from '../lib/format.js';
import { usePageTitle } from '../lib/pageHooks.js';
import { describeStatus } from '../lib/status.js';
import { useNow } from '../lib/useNow.js';

const OVERALL_SENTENCES = {
  ok: 'All components are operational.',
  degraded: 'The system is running with problems. See the components below.',
  down: 'A core component is down. New work cannot be processed.',
  unknown: 'Status is unknown while you are offline.',
  checking: 'Checking components…',
};

/** Skeleton appears only if loading takes longer than a blink. */
function useDelayedFlag(active, delayMs = 300) {
  const [shown, setShown] = useState(false);
  useEffect(() => {
    const id = setTimeout(() => setShown(active), active ? delayMs : 0);
    return () => clearTimeout(id);
  }, [active, delayMs]);
  return active && shown;
}

function Metric({ label, children }) {
  return (
    <div className="metric">
      <dt className="label">{label}</dt>
      <dd className="metric__value num">{children}</dd>
    </div>
  );
}

function ComponentCard({ component, children }) {
  return (
    <Panel
      className="component-card"
      title={component.title}
      meta={<StatusIndicator status={component.status} />}
      aria-label={`${component.title}: ${describeStatus(component.status).label}`}
    >
      <p className="component-card__summary">{component.summary}</p>
      {children && <dl className="metric-list">{children}</dl>}
    </Panel>
  );
}

function WorkersTable({ workers, ageOffsetS, staleAfterS }) {
  return (
    <div className="table-scroll" role="region" aria-label="Worker heartbeats" tabIndex={0}>
      <table className="table">
        <thead>
          <tr>
            <th scope="col">Worker</th>
            <th scope="col">Status</th>
            <th scope="col">Last heartbeat</th>
            <th scope="col" className="col--secondary">Version</th>
            <th scope="col" className="col--secondary">Started</th>
          </tr>
        </thead>
        <tbody>
          {workers.map((w) => (
            <tr key={w.id}>
              <td>
                <code className="table__id">{w.id}</code>
              </td>
              <td>
                <StatusIndicator status={w.status} />
              </td>
              <td className="num" title={formatDateTime(w.last_seen_at)}>
                {formatAge(w.last_seen_age_s + ageOffsetS)}
              </td>
              <td className="col--secondary">
                <code>{w.version}</code>
              </td>
              <td className="col--secondary num">{formatDateTime(w.started_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="table__note">A worker is marked stale after {staleAfterS}s without a heartbeat.</p>
    </div>
  );
}

function QueueTable({ queues, lagWarnS }) {
  return (
    <div className="table-scroll" role="region" aria-label="Job queues" tabIndex={0}>
      <table className="table">
        <thead>
          <tr>
            <th scope="col">Queue</th>
            <th scope="col" className="num">Due now</th>
            <th scope="col" className="num">Running</th>
            <th scope="col" className="num">Dead</th>
            <th scope="col">Oldest due job</th>
            <th scope="col" className="col--secondary num">Queued total</th>
          </tr>
        </thead>
        <tbody>
          {queues.map((q) => {
            const lagging = q.oldest_due_age_s != null && q.oldest_due_age_s > lagWarnS;
            return (
              <tr key={q.name}>
                <td>
                  <code>{q.name}</code>
                </td>
                <td className="num">{q.due}</td>
                <td className="num">{q.running}</td>
                <td className="num">
                  {q.dead > 0 ? <StatusIndicator status="degraded" label={String(q.dead)} /> : 0}
                </td>
                <td className="num">
                  {q.oldest_due_age_s == null ? (
                    '—'
                  ) : lagging ? (
                    <StatusIndicator status="degraded" label={`waiting ${formatAge(q.oldest_due_age_s).replace(' ago', '')}`} />
                  ) : (
                    `waiting ${formatAge(q.oldest_due_age_s).replace(' ago', '')}`
                  )}
                </td>
                <td className="col--secondary num">{q.queued}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
      <p className="table__note">
        Lag is how long the oldest due job has waited; it is flagged after {lagWarnS}s. Jobs
        delayed for a retry are not counted until they are due.
      </p>
    </div>
  );
}

function LoadingSkeleton() {
  return (
    <div className="health-grid" aria-hidden="true">
      {[0, 1, 2].map((i) => (
        <div key={i} className="panel skeleton">
          <span className="skeleton__line skeleton__line--short" />
          <span className="skeleton__line" />
          <span className="skeleton__line skeleton__line--mid" />
        </div>
      ))}
    </div>
  );
}

export function SystemHealth() {
  usePageTitle('System health');
  const health = useHealth();
  const now = useNow(1000);
  const derived = deriveHealth(health);
  const { report, error, fetching, slow, phase, online, refresh, intervalMs } = health;
  const showSkeleton = useDelayedFlag(phase === 'loading');

  const byName = Object.fromEntries((report?.components ?? []).map((c) => [c.name, c]));
  const ageOffsetS = health.lastUpdated ? Math.max(0, (now - health.lastUpdated) / 1000) : 0;
  const workers = byName.workers;
  const workersKnown = Array.isArray(workers?.workers) && workers.status !== 'unknown';

  return (
    <div className="page">
      <header className="page__header">
        <div className="page__heading">
          <h1>System health</h1>
          <p className="page__subtitle">
            <StatusIndicator status={derived.overall} />
            <span>{OVERALL_SENTENCES[derived.overall] ?? OVERALL_SENTENCES.checking}</span>
          </p>
        </div>
        <div className="page__actions">
          <span className="page__hint">Refreshes every {Math.round(intervalMs / 1000)}s</span>
          <button type="button" className="button" onClick={refresh} disabled={fetching || !online}>
            <Icon name="refresh" size={16} />
            {fetching ? 'Refreshing…' : 'Refresh'}
          </button>
        </div>
      </header>

      <div className="page__notices" aria-live="polite">
        {!online && (
          <p className="notice notice--warning">
            <Icon name="offline" size={16} />
            You are offline. {report ? 'Showing the last data received.' : ''} Updates resume when
            the connection returns.
          </p>
        )}
        {online && slow && (
          <p className="notice">The API is taking longer than usual to respond. Still waiting…</p>
        )}
      </div>

      {online && error && report && (
        <InlineError
          title="Couldn't refresh system health"
          message={`${error.message} Showing data from ${formatAge(secondsSince(health.lastUpdated, now))}.`}
          correlationId={error.correlationId}
          onRetry={refresh}
          retrying={fetching}
        />
      )}

      {phase === 'error' && online && (
        <InlineError
          title="Couldn't load system health"
          message={
            error?.kind === 'network' || error?.kind === 'unreachable'
              ? `${error.message} The API process may not be running.`
              : error?.message
          }
          correlationId={error?.correlationId}
          onRetry={refresh}
          retrying={fetching}
        />
      )}

      {showSkeleton && <LoadingSkeleton />}

      {report && (
        <>
          <div className="health-grid">
            <ComponentCard component={{ ...byName.api, title: 'API' }}>
              <Metric label="Version">{byName.api?.version ?? '—'}</Metric>
              <Metric label="Environment">{report.environment}</Metric>
            </ComponentCard>
            <ComponentCard component={{ ...byName.database, title: 'Database' }}>
              <Metric label="Probe latency">{formatMs(byName.database?.latency_ms)}</Metric>
              <Metric label="Pending migrations">{byName.database?.pending_migrations ?? '—'}</Metric>
            </ComponentCard>
            <ComponentCard component={{ ...workers, title: 'Workers' }}>
              {/* Unknown is not zero: don't show counts we couldn't read. */}
              <Metric label="Running">
                {workersKnown ? workers.workers.filter((w) => w.status === 'running').length : '—'}
              </Metric>
              <Metric label="Reported">{workersKnown ? workers.workers.length : '—'}</Metric>
            </ComponentCard>
          </div>

          <Panel title="Worker heartbeats" className="workers-panel">
            {workers?.status === 'unknown' ? (
              <EmptyState title="Worker status is unavailable">
                <p>{workers.summary}</p>
              </EmptyState>
            ) : workers?.workers?.length ? (
              <WorkersTable
                workers={workers.workers}
                ageOffsetS={ageOffsetS}
                staleAfterS={workers.stale_after_s}
              />
            ) : (
              <EmptyState title="No worker has reported yet">
                <p>
                  Start a worker from the <code>worker/</code> directory with{' '}
                  <code>python -m keelwatch_worker</code>. It appears here after its first
                  heartbeat.
                </p>
              </EmptyState>
            )}
          </Panel>

          {byName.queue && (
            <Panel
              title="Job queue"
              meta={<StatusIndicator status={byName.queue.status} />}
              className="workers-panel"
              aria-label={`Job queue: ${describeStatus(byName.queue.status).label}`}
            >
              {byName.queue.status === 'unknown' ? (
                <EmptyState title="Queue status is unavailable">
                  <p>{byName.queue.summary}</p>
                </EmptyState>
              ) : byName.queue.queues.length === 0 ? (
                <EmptyState title="No jobs yet">
                  <p>Jobs appear here once GitHub deliveries arrive.</p>
                </EmptyState>
              ) : (
                <>
                  {byName.queue.status !== 'ok' && (
                    <p className="panel__alert">{byName.queue.summary}</p>
                  )}
                  <QueueTable queues={byName.queue.queues} lagWarnS={byName.queue.lag_warn_after_s} />
                </>
              )}
            </Panel>
          )}

          <p className="page__footnote num">
            Last checked {formatDateTime(report.checked_at)} (UTC source time, shown in your local
            time zone).
          </p>
        </>
      )}
    </div>
  );
}
