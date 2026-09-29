import { Link, useParams } from 'react-router';
import { EmptyState, Panel } from '../components/Feedback.jsx';
import { FilterBar, LoadMore, NoResults, PageHeader, RelTime, SelectFilter } from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { useFilters } from '../lib/pageHooks.js';
import { SeverityCounts, SeverityTag } from '../components/Severity.jsx';
import { StatusIndicator } from '../components/StatusIndicator.jsx';
import { formatDateTime } from '../lib/format.js';
import { useApi } from '../lib/useApi.js';
import { usePaged } from '../lib/usePaged.js';

export function Digests() {
  const filters = useFilters(['kind']);
  const paged = usePaged('/api/digests', filters.values);
  return (
    <div className="page">
      <PageHeader title="Digests" subtitle="A summary per analysis run, plus one per day." />
      <FilterBar active={filters.active} onClear={filters.clear}>
        <SelectFilter
          label="Kind"
          value={filters.values.kind}
          options={[
            { value: 'run', label: 'Run digests' },
            { value: 'daily', label: 'Daily digests' },
          ]}
          onChange={(v) => filters.set('kind', v)}
        />
      </FilterBar>
      <Resource
        api={paged}
        label="digests"
        onResetFilters={filters.clear}
        isEmpty={(items) => items.length === 0}
        empty={filters.active ? <NoResults onClear={filters.clear} /> : <EmptyState title="No digests yet"><p>A digest is written at the end of every analysis run.</p></EmptyState>}
      >
        {(items) => (
          <>
            <div className="panel table-scroll" role="region" aria-label="Digests" tabIndex={0}>
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">Digest</th>
                    <th scope="col">Findings</th>
                    <th scope="col" className="col--secondary">Notifications</th>
                    <th scope="col">Created</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((d) => (
                    <tr key={d.id}>
                      <td className="table__wrap">
                        <Link to={`/digests/${d.id}`}>
                          {d.kind === 'daily' ? `Daily · ${d.period_start?.slice(0, 10)}` : `Run #${d.run_id}`}
                        </Link>
                        <span className="table__sub">{d.repository?.full_name ?? (d.runs !== null ? `${d.runs} run(s)` : '')}</span>
                      </td>
                      <td>
                        <SeverityCounts counts={d.by_severity} />
                      </td>
                      <td className="col--secondary num">{d.notifications_sent} sent</td>
                      <td>
                        <RelTime iso={d.created_at} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <LoadMore hasMore={paged.hasMore} loading={paged.loading} onClick={paged.loadMore} />
          </>
        )}
      </Resource>
    </div>
  );
}

function RunDigest({ c }) {
  return (
    <>
      <Panel title="Findings">
        <p>
          <SeverityCounts counts={c.findings.by_severity} emptyLabel="No findings." />
          {c.findings.total > 0 && (
            <span className="muted">
              {' '}
              · {c.findings.new} new, {c.findings.recurring} recurring
            </span>
          )}
        </p>
        {c.findings.top.length > 0 && (
          <ul className="plain-list">
            {c.findings.top.map((f, i) => (
              <li key={i}>
                <SeverityTag severity={f.severity} /> {f.title}
                {f.file_path && (
                  <code className="path">
                    {' '}
                    {f.file_path}
                    {f.line ? `:${f.line}` : ''}
                  </code>
                )}
              </li>
            ))}
          </ul>
        )}
      </Panel>
      {c.gaps?.length > 0 && (
        <Panel title="Not checked in this run">
          <ul className="plain-list">
            {c.gaps.map((g) => (
              <li key={g}>{g}</li>
            ))}
          </ul>
        </Panel>
      )}
      {c.review && (
        <Panel title="AI review summary" meta={<span className="muted">{c.review.provider} / {c.review.model}</span>}>
          <p className="prose">{c.review.summary}</p>
        </Panel>
      )}
    </>
  );
}

function DailyDigest({ c }) {
  return (
    <>
      <Panel title="Totals">
        <p className="num">
          {c.totals.runs} run(s), {c.totals.failed_runs} failed · <SeverityCounts counts={c.totals.by_severity} emptyLabel="no findings" /> ({c.totals.new_findings} new)
        </p>
      </Panel>
      <Panel title="Repositories" className="workers-panel">
        <div className="table-scroll" role="region" aria-label="Repositories in this digest" tabIndex={0}>
          <table className="table">
            <thead>
              <tr>
                <th scope="col">Repository</th>
                <th scope="col" className="num">Runs</th>
                <th scope="col">Findings</th>
              </tr>
            </thead>
            <tbody>
              {c.repositories.map((r) => (
                <tr key={r.full_name}>
                  <td>
                    {r.full_name}
                    {r.private && <span className="tag">private</span>}
                  </td>
                  <td className="num">{r.runs}</td>
                  <td>
                    <SeverityCounts counts={r.by_severity} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
      <Panel title="How the code changed">
        <dl className="kv">
          <dt>New files</dt>
          <dd className="num">{c.evolution.new_files}</dd>
          <dt>Dependency changes</dt>
          <dd className="num">{c.evolution.dependency_changes}</dd>
          <dt>Most-changed areas</dt>
          <dd>{c.evolution.areas_touched.length ? c.evolution.areas_touched.map(([area, n]) => `${area} (${n})`).join(', ') : '—'}</dd>
        </dl>
      </Panel>
    </>
  );
}

export function DigestDetail() {
  const { id } = useParams();
  const api = useApi(`/api/digests/${encodeURIComponent(id)}`);
  const d = api.data;
  return (
    <div className="page">
      <PageHeader
        title={d ? (d.kind === 'daily' ? `Daily digest · ${d.content.period.date}` : `Run digest · run #${d.content.run_id}`) : 'Digest'}
        subtitle={d ? `Created ${formatDateTime(d.created_at)}` : null}
      />
      {api.error?.status === 404 ? (
        <EmptyState title="Digest not found" />
      ) : (
        <Resource api={api} label="this digest">
          {(digest) => (
            <>
              {digest.kind === 'daily' ? <DailyDigest c={digest.content} /> : <RunDigest c={digest.content} />}
              <Panel title="Notifications" className="workers-panel">
                {digest.deliveries.length ? (
                  <div className="table-scroll" role="region" aria-label="Notification attempts" tabIndex={0}>
                    <table className="table">
                      <thead>
                        <tr>
                          <th scope="col">Destination</th>
                          <th scope="col">Result</th>
                          <th scope="col" className="num">Attempt</th>
                          <th scope="col">When</th>
                        </tr>
                      </thead>
                      <tbody>
                        {digest.deliveries.map((x, i) => (
                          <tr key={i}>
                            <td>
                              {x.destination.kind} · {x.destination.label}
                            </td>
                            <td className="table__wrap">
                              <StatusIndicator status={`notify_${x.status}`} />
                              {x.error && <span className="table__sub">{x.error}</span>}
                            </td>
                            <td className="num">{x.attempt}</td>
                            <td>
                              <RelTime iso={x.attempted_at} />
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ) : (
                  <EmptyState title="Not sent anywhere">
                    <p>
                      No destination matched, or notifications are off. <Link to="/integrations">Manage destinations</Link>
                    </p>
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
