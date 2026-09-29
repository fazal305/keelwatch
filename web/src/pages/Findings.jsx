import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { EmptyState, Panel } from '../components/Feedback.jsx';
import {
  FilterBar,
  LoadMore,
  NoResults,
  PageHeader,
  RelTime,
  RepoLabel,
  SearchFilter,
  SelectFilter,
} from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { SeverityTag } from '../components/Severity.jsx';
import { useFilters } from '../lib/pageHooks.js';
import { SEVERITIES } from '../lib/status.js';
import { useApi } from '../lib/useApi.js';
import { usePaged } from '../lib/usePaged.js';

const SOURCE_LABELS = { rule: 'Rule', osv: 'OSV.dev', llm: 'AI review' };

function Location({ path, line }) {
  if (!path) return <span className="muted">—</span>;
  return (
    <code className="path" title={path}>
      {path}
      {line ? `:${line}` : ''}
    </code>
  );
}

export function FindingsTable({ findings, showRepo = true }) {
  return (
    <div className="table-scroll" role="region" aria-label="Findings" tabIndex={0}>
      <table className="table">
        <thead>
          <tr>
            <th scope="col">Severity</th>
            <th scope="col">Finding</th>
            {showRepo && <th scope="col" className="col--secondary">Repository</th>}
            <th scope="col" className="col--secondary">Location</th>
            <th scope="col" className="col--secondary">Confidence</th>
          </tr>
        </thead>
        <tbody>
          {findings.map((f) => (
            <tr key={f.id}>
              <td>
                <SeverityTag severity={f.severity} />
              </td>
              <td className="table__wrap">
                <Link to={`/findings/${f.id}`}>{f.title}</Link>
                <span className="table__sub">
                  {SOURCE_LABELS[f.source]} · {f.category}
                  {f.recurring ? ' · recurring' : ' · new'}
                </span>
              </td>
              {showRepo && (
                <td className="col--secondary">
                  <RepoLabel repo={f.repository} />
                </td>
              )}
              <td className="col--secondary">
                <Location path={f.file_path} line={f.line} />
              </td>
              <td className="col--secondary">{f.confidence}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function Findings() {
  const filters = useFilters(['severity', 'category', 'source', 'q', 'repository_id', 'run_id']);
  const [searchKey, setSearchKey] = useState(0);
  const paged = usePaged('/api/findings', filters.values);
  const clear = () => {
    filters.clear();
    setSearchKey((k) => k + 1);
  };

  return (
    <div className="page">
      <PageHeader
        title="Findings"
        subtitle="Signals from rules, vulnerability data and optional AI review. Each has a stated confidence; confirm before acting."
      />
      <FilterBar active={filters.active} onClear={clear}>
        <SearchFilter key={searchKey} label="Search titles" value={filters.values.q} onChange={(v) => filters.set('q', v)} />
        <SelectFilter label="Severity" value={filters.values.severity} options={SEVERITIES} onChange={(v) => filters.set('severity', v)} />
        <SelectFilter
          label="Category"
          value={filters.values.category}
          options={['security', 'dependency', 'quality', 'logic', 'architecture']}
          onChange={(v) => filters.set('category', v)}
        />
        <SelectFilter
          label="Source"
          value={filters.values.source}
          options={Object.entries(SOURCE_LABELS).map(([value, label]) => ({ value, label }))}
          onChange={(v) => filters.set('source', v)}
        />
      </FilterBar>
      {(filters.values.run_id || filters.values.repository_id) && (
        <p className="notice">
          Showing findings for {filters.values.run_id ? `run #${filters.values.run_id}` : `repository #${filters.values.repository_id}`}.
        </p>
      )}
      <Resource
        api={paged}
        label="findings"
        isEmpty={(items) => items.length === 0}
        empty={
          filters.active ? (
            <NoResults onClear={clear} />
          ) : (
            <EmptyState title="No findings yet">
              <p>Findings appear after an analysis run completes. An empty list is not proof of safety; check runs for skipped checks.</p>
            </EmptyState>
          )
        }
      >
        {(items) => (
          <>
            <div className="panel">
              <FindingsTable findings={items} />
            </div>
            <LoadMore hasMore={paged.hasMore} loading={paged.loading} onClick={paged.loadMore} />
          </>
        )}
      </Resource>
    </div>
  );
}

export function FindingDetail() {
  const { id } = useParams();
  const api = useApi(`/api/findings/${encodeURIComponent(id)}`);
  const f = api.data;

  return (
    <div className="page">
      <PageHeader title={f ? f.title : 'Finding'} subtitle={f ? <SeverityTag severity={f.severity} /> : null} />
      {api.error?.status === 404 ? (
        <EmptyState title="Finding not found">
          <p>
            <Link to="/findings">Back to findings</Link>
          </p>
        </EmptyState>
      ) : (
        <Resource api={api} label="this finding">
          {(d) => (
            <>
              <Panel title="What was found">
                <p className="prose">{d.description}</p>
                {d.recommendation && (
                  <>
                    <h2 className="label detail__label">Suggested action</h2>
                    <p className="prose">{d.recommendation}</p>
                  </>
                )}
              </Panel>

              {d.evidence && (
                <Panel title="Evidence" meta={d.evidence.redacted ? <span className="muted">secrets masked before storage</span> : null}>
                  {d.evidence.snippet && <pre className="code-block">{d.evidence.snippet}</pre>}
                  {d.evidence.package && (
                    <dl className="kv">
                      <dt>Package</dt>
                      <dd>
                        <code>{d.evidence.package}</code> ({d.evidence.ecosystem})
                      </dd>
                      <dt>Version</dt>
                      <dd className="num">
                        {d.evidence.version_before ?? '(new)'} → {d.evidence.version_after ?? '(removed)'}
                      </dd>
                      {d.evidence.advisory_ids?.length > 0 && (
                        <>
                          <dt>Advisories</dt>
                          <dd>
                            {d.evidence.advisory_ids.map((a) => (
                              <a key={a} href={`https://osv.dev/vulnerability/${encodeURIComponent(a)}`} target="_blank" rel="noreferrer noopener">
                                <code>{a}</code>
                              </a>
                            ))}
                          </dd>
                        </>
                      )}
                    </dl>
                  )}
                  {d.evidence.metric && (
                    <p className="num">
                      {d.evidence.metric.replaceAll('_', ' ')}: {d.evidence.value} (threshold {d.evidence.threshold})
                    </p>
                  )}
                </Panel>
              )}

              <Panel title="Provenance">
                <dl className="kv">
                  <dt>Repository</dt>
                  <dd>
                    <RepoLabel repo={d.repository} />
                  </dd>
                  <dt>Location</dt>
                  <dd>
                    <Location path={d.location?.file_path} line={d.location?.line_start} />
                  </dd>
                  <dt>Run</dt>
                  <dd>
                    <Link to={`/runs/${d.run.id}`}>#{d.run.id}</Link> · phase <code>{d.phase}</code>
                  </dd>
                  <dt>Source</dt>
                  <dd>
                    {SOURCE_LABELS[d.source]}
                    {d.rule_id && (
                      <>
                        {' '}
                        · <code>{d.rule_id}</code>
                      </>
                    )}
                    {d.provider && ` · ${d.provider} / ${d.model}`}
                  </dd>
                  <dt>Confidence</dt>
                  <dd>{d.confidence}</dd>
                  <dt>History</dt>
                  <dd>
                    {d.seen_in_runs > 1 ? `Seen in ${d.seen_in_runs} runs, first in ` : 'First seen in '}
                    <Link to={`/runs/${d.first_seen_run_id}`}>run #{d.first_seen_run_id}</Link>
                  </dd>
                  <dt>Recorded</dt>
                  <dd>
                    <RelTime iso={d.created_at} />
                  </dd>
                </dl>
              </Panel>
              <p className="page__footnote">
                Findings are signals derived from the change, not verdicts. Confidence reflects how reliable this kind of signal usually is.
              </p>
            </>
          )}
        </Resource>
      )}
    </div>
  );
}
