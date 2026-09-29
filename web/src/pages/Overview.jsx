import { Link } from 'react-router';
import { EmptyState, Panel } from '../components/Feedback.jsx';
import { PageHeader, RelTime, RepoLabel } from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { SeverityCounts } from '../components/Severity.jsx';
import { StatusIndicator } from '../components/StatusIndicator.jsx';
import { useApi } from '../lib/useApi.js';

function Stat({ label, value, to, tone }) {
  const body = (
    <>
      <span className="label">{label}</span>
      <span className={`stat__value num${tone && value > 0 ? ` stat__value--${tone}` : ''}`}>{value}</span>
    </>
  );
  return to && value > 0 ? (
    <Link className="stat" to={to}>
      {body}
    </Link>
  ) : (
    <div className="stat">{body}</div>
  );
}

export function RunsTable({ runs, showRepo = true }) {
  return (
    <div className="table-scroll" role="region" aria-label="Analysis runs" tabIndex={0}>
      <table className="table">
        <thead>
          <tr>
            <th scope="col">Run</th>
            {showRepo && <th scope="col">Repository</th>}
            <th scope="col">Status</th>
            <th scope="col" className="col--secondary">Trigger</th>
            <th scope="col" className="num">Findings</th>
            <th scope="col">Started</th>
          </tr>
        </thead>
        <tbody>
          {runs.map((r) => (
            <tr key={r.id}>
              <td>
                <Link to={`/runs/${r.id}`} className="num">
                  #{r.id}
                </Link>
              </td>
              {showRepo && (
                <td>
                  <RepoLabel repo={r.repository} />
                </td>
              )}
              <td>
                <StatusIndicator status={`run_${r.status}`} />
              </td>
              <td className="col--secondary">
                {r.pull_request ? `PR #${r.pull_request}` : r.event_type ?? r.trigger}
              </td>
              <td className="num">{r.findings}</td>
              <td>
                <RelTime iso={r.started_at ?? r.created_at} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function Overview() {
  const api = useApi('/api/overview');
  return (
    <div className="page">
      <PageHeader title="Overview" subtitle="What needs attention across your repositories." />
      <Resource
        api={api}
        label="the overview"
        isEmpty={(d) => d.repositories === 0 && d.recent_events.length === 0}
        empty={
          <Panel title="Getting started">
            <EmptyState title="No repositories connected yet">
              <p>
                Install the Keelwatch GitHub App on an account, or send a signed test delivery with{' '}
                <code>php scripts/send-webhook.php installation.created</code>. Repositories appear here once
                GitHub delivers their first event.
              </p>
            </EmptyState>
          </Panel>
        }
      >
        {(d) => (
          <>
            <section className="stats" aria-label="Needs attention">
              <Stat label="Failed runs (24h)" value={d.attention.failed_runs_24h} to="/runs?status=failed" tone="danger" />
              <Stat label="Waiting to resume" value={d.attention.checkpointed_runs} to="/runs?status=checkpointed" tone="warning" />
              <Stat label="Dead-lettered jobs" value={d.attention.dead_jobs} to="/system" tone="danger" />
              <Stat label="Repositories" value={d.repositories} to="/repositories" />
              <Stat label="Runs (7 days)" value={d.runs_7d} to="/runs" />
              <Stat label="Events (24h)" value={d.events_24h} to="/events" />
            </section>

            <Panel title="Latest findings" meta={<span className="muted">from each repository's most recent completed run</span>}>
              <p className="overview__severity">
                <SeverityCounts counts={d.attention.latest_findings_by_severity} emptyLabel="No findings in the latest runs." />
              </p>
              <p>
                <Link to="/findings">Review findings</Link>
              </p>
            </Panel>

            <Panel title="Recent analysis runs" className="workers-panel">
              {d.recent_runs.length ? (
                <RunsTable runs={d.recent_runs} />
              ) : (
                <EmptyState title="No analysis runs yet">
                  <p>Runs start when a pull request opens or updates, or when commits are pushed to a default branch.</p>
                </EmptyState>
              )}
            </Panel>
          </>
        )}
      </Resource>
    </div>
  );
}
