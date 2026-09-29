import { useRef, useState } from 'react';
import { Link, useParams } from 'react-router';
import { useAuth } from '../auth/AuthContext.js';
import { EmptyState, InlineError, Panel } from '../components/Feedback.jsx';
import { FilterBar, LoadMore, NoResults, PageHeader, RelTime, RepoLabel, SelectFilter, Sha } from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { useFilters } from '../lib/pageHooks.js';
import { StatusIndicator } from '../components/StatusIndicator.jsx';
import { fetchJson } from '../lib/api.js';
import { formatDateTime, formatMs } from '../lib/format.js';
import { PHASE_LABELS } from '../lib/status.js';
import { useApi } from '../lib/useApi.js';
import { usePaged } from '../lib/usePaged.js';
import { RunsTable } from './Overview.jsx';

const RUN_STATUSES = ['queued', 'running', 'checkpointed', 'completed', 'failed', 'cancelled'];

export function Runs() {
  const filters = useFilters(['status', 'repository_id']);
  const paged = usePaged('/api/runs', filters.values);
  return (
    <div className="page">
      <PageHeader title="Analysis runs" subtitle="Each run executes the pipeline phase by phase, with a time budget and saved checkpoints." />
      <FilterBar active={filters.active} onClear={filters.clear}>
        <SelectFilter label="Status" value={filters.values.status} options={RUN_STATUSES} onChange={(v) => filters.set('status', v)} />
      </FilterBar>
      <Resource
        api={paged}
        label="analysis runs"
        isEmpty={(items) => items.length === 0}
        empty={filters.active ? <NoResults onClear={filters.clear} /> : <EmptyState title="No analysis runs yet" />}
      >
        {(items) => (
          <>
            <div className="panel">
              <RunsTable runs={items} />
            </div>
            <LoadMore hasMore={paged.hasMore} loading={paged.loading} onClick={paged.loadMore} />
          </>
        )}
      </Resource>
    </div>
  );
}

function SummaryValues({ summary }) {
  if (!summary) return null;
  const entries = Object.entries(summary).filter(([, v]) => v !== null && v !== undefined && typeof v !== 'object');
  if (entries.length === 0) return null;
  return (
    <dl className="kv kv--inline">
      {entries.map(([k, v]) => (
        <div key={k}>
          <dt>{k.replaceAll('_', ' ')}</dt>
          <dd className="num">{String(v)}</dd>
        </div>
      ))}
    </dl>
  );
}

function Timeline({ checkpoints, budgetMs }) {
  if (checkpoints.length === 0) {
    return <EmptyState title="No phases have run yet" />;
  }
  return (
    <ol className="timeline">
      {checkpoints.map((c) => (
        <li key={`${c.phase}-${c.attempt}`} className="timeline__item">
          <div className="timeline__head">
            <StatusIndicator status={`phase_${c.status}`} />
            <span className="timeline__phase">{PHASE_LABELS[c.phase] ?? c.phase}</span>
            {c.attempt > 1 && <span className="tag">attempt {c.attempt}</span>}
            <span className="timeline__duration num">{formatMs(c.duration_ms)}</span>
          </div>
          {c.duration_ms !== null && budgetMs > 0 && (
            <div className="timeline__bar" aria-hidden="true">
              <span style={{ width: `${Math.min(100, Math.max(0.5, (c.duration_ms / budgetMs) * 100))}%` }} />
            </div>
          )}
          {c.error && <p className="timeline__error">{c.error}</p>}
          {c.summary?.reason && <p className="timeline__reason">Skipped: {c.summary.reason}</p>}
          <SummaryValues summary={c.summary?.reason ? null : c.summary} />
        </li>
      ))}
    </ol>
  );
}

function RunActions({ run, onChanged }) {
  const { isAdmin } = useAuth();
  const dialogRef = useRef(null);
  const [pending, setPending] = useState(null);
  const [result, setResult] = useState(null);

  if (!isAdmin || (!run.can_resume && !run.can_cancel)) return null;

  const confirm = (action) => {
    setResult(null);
    setPending(action);
    dialogRef.current?.showModal();
  };

  const execute = async () => {
    const action = pending;
    dialogRef.current?.close();
    setPending(null);
    try {
      await fetchJson(`/api/runs/${run.id}/${action}`, { method: 'POST' });
      setResult({ ok: true, message: action === 'resume' ? 'Resume queued. The worker will pick it up shortly.' : 'Run cancelled.' });
      onChanged();
    } catch (error) {
      setResult({ ok: false, error });
    }
  };

  return (
    <>
      <div className="page__actions">
        {run.can_resume && (
          <button type="button" className="button" onClick={() => confirm('resume')}>
            Resume run
          </button>
        )}
        {run.can_cancel && (
          <button type="button" className="button button--danger" onClick={() => confirm('cancel')}>
            Cancel run
          </button>
        )}
      </div>
      <dialog ref={dialogRef} className="dialog" onClose={() => setPending(null)} aria-labelledby="run-action-title">
        <h2 id="run-action-title">{pending === 'resume' ? `Resume run #${run.id}?` : `Cancel run #${run.id}?`}</h2>
        <p>
          {pending === 'resume'
            ? 'Completed phases are kept; the run continues from the next phase with a fresh time budget.'
            : 'A running analysis stops at its next phase boundary. This cannot be undone, but you can trigger a new run by pushing again.'}
        </p>
        <div className="dialog__actions">
          <button type="button" className="button" onClick={() => dialogRef.current?.close()}>
            Keep as is
          </button>
          <button type="button" className={`button ${pending === 'cancel' ? 'button--danger' : 'button--primary'}`} onClick={execute}>
            {pending === 'resume' ? 'Resume' : 'Cancel run'}
          </button>
        </div>
      </dialog>
      <div aria-live="polite">
        {result?.ok && <p className="notice notice--success">{result.message}</p>}
        {result && !result.ok && (
          <InlineError
            title={result.error.kind === 'forbidden' ? 'Not allowed' : "Couldn't update the run"}
            message={result.error.message}
            correlationId={result.error.correlationId}
          />
        )}
      </div>
    </>
  );
}

export function RunDetail() {
  const { id } = useParams();
  const api = useApi(`/api/runs/${encodeURIComponent(id)}`);
  const run = api.data;

  return (
    <div className="page">
      <PageHeader
        title={`Run #${id}`}
        subtitle={
          run ? (
            <>
              <StatusIndicator status={`run_${run.status}`} />
              <span>{run.failure_reason ? `Reason: ${run.failure_reason}` : run.current_phase ? `Current phase: ${PHASE_LABELS[run.current_phase] ?? run.current_phase}` : ''}</span>
            </>
          ) : null
        }
        actions={
          <button type="button" className="button" onClick={api.reload} disabled={api.loading}>
            {api.loading ? 'Refreshing…' : 'Refresh'}
          </button>
        }
      />
      {api.error?.status === 404 ? (
        <EmptyState title="Run not found">
          <p>
            <Link to="/runs">Back to runs</Link>
          </p>
        </EmptyState>
      ) : (
        <Resource api={api} label="this run">
          {(r) => (
            <>
              <RunActions run={r} onChanged={api.reload} />
              <Panel title="Details">
                <dl className="kv">
                  <dt>Repository</dt>
                  <dd>
                    <RepoLabel repo={r.repository} />
                  </dd>
                  <dt>Trigger</dt>
                  <dd>
                    {r.trigger}
                    {r.pull_request ? ` · PR #${r.pull_request}` : r.event_type ? ` · ${r.event_type}` : ''}
                  </dd>
                  <dt>Commit</dt>
                  <dd>
                    <Sha sha={r.head_sha} />
                  </dd>
                  <dt>Started</dt>
                  <dd title={formatDateTime(r.started_at)}>
                    <RelTime iso={r.started_at} />
                  </dd>
                  <dt>Duration</dt>
                  <dd className="num">
                    {r.duration_ms !== null ? formatMs(r.duration_ms) : '—'} of a {formatMs(r.budget_ms)} budget
                  </dd>
                  <dt>Attempt</dt>
                  <dd className="num">{r.attempt}</dd>
                  <dt>Findings</dt>
                  <dd>
                    {r.findings > 0 ? <Link to={`/findings?run_id=${r.id}`}>{r.findings} finding(s)</Link> : 'None'}
                    {r.digest_id && (
                      <>
                        {' '}
                        · <Link to={`/digests/${r.digest_id}`}>Digest</Link>
                      </>
                    )}
                  </dd>
                  <dt>Reference</dt>
                  <dd>
                    <code>{r.correlation_id}</code>
                  </dd>
                </dl>
              </Panel>

              <Panel title="Phase timeline" meta={<span className="muted">bars show time used against the run's budget</span>}>
                <Timeline checkpoints={r.checkpoints} budgetMs={r.budget_ms} />
              </Panel>

              <Panel title="AI provider calls" className="workers-panel">
                {r.provider_calls.length ? (
                  <div className="table-scroll" role="region" aria-label="Provider calls" tabIndex={0}>
                    <table className="table">
                      <thead>
                        <tr>
                          <th scope="col">Provider</th>
                          <th scope="col">Outcome</th>
                          <th scope="col" className="num">Latency</th>
                          <th scope="col" className="num col--secondary">Tokens in / out</th>
                        </tr>
                      </thead>
                      <tbody>
                        {r.provider_calls.map((c, i) => (
                          <tr key={i}>
                            <td>
                              {c.provider} <code>{c.model}</code>
                            </td>
                            <td>{c.outcome}</td>
                            <td className="num">{formatMs(c.latency_ms)}</td>
                            <td className="num col--secondary">
                              {c.tokens_in ?? '—'} / {c.tokens_out ?? '—'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <EmptyState title="No AI provider was called">
                    <p>Either no provider is configured, or this repository's privacy policy kept its code local.</p>
                  </EmptyState>
                )}
              </Panel>
            </>
          )}
        </Resource>
      )}
    </div>
  );
}
