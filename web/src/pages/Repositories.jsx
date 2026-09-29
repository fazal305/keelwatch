import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { EmptyState, Panel } from '../components/Feedback.jsx';
import {
  FilterBar,
  NoResults,
  PageHeader,
  RelTime,
  RepoLabel,
  SearchFilter,
  SelectFilter,
  } from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { useFilters } from '../lib/pageHooks.js';
import { SeverityCounts } from '../components/Severity.jsx';
import { StatusIndicator } from '../components/StatusIndicator.jsx';
import { withQuery } from '../lib/api.js';
import { useApi } from '../lib/useApi.js';
import { FindingsTable } from './Findings.jsx';
import { RunsTable } from './Overview.jsx';

const POLICY_LABELS = {
  none: 'Never sent to AI',
  public_only: 'AI only while public',
  allowed: 'AI allowed',
};

export function Repositories() {
  const filters = useFilters(['q', 'state']);
  const [searchKey, setSearchKey] = useState(0);
  const api = useApi(withQuery('/api/repositories', filters.values));
  const clear = () => {
    filters.clear();
    setSearchKey((k) => k + 1);
  };

  return (
    <div className="page">
      <PageHeader title="Repositories" subtitle="Repositories the GitHub App can see." />
      <FilterBar active={filters.active} onClear={clear}>
        <SearchFilter key={searchKey} label="Search" value={filters.values.q} onChange={(v) => filters.set('q', v)} placeholder="owner/name" />
        <SelectFilter
          label="Show"
          value={filters.values.state}
          allLabel="Active"
          options={[
            { value: 'removed', label: 'Removed' },
            { value: 'all', label: 'All' },
          ]}
          onChange={(v) => filters.set('state', v)}
        />
      </FilterBar>
      <Resource
        api={api}
        label="repositories"
        isEmpty={(d) => d.items.length === 0}
        empty={
          filters.active ? (
            <NoResults onClear={clear} />
          ) : (
            <EmptyState title="No repositories yet">
              <p>Repositories appear once the GitHub App is installed and GitHub sends an event.</p>
            </EmptyState>
          )
        }
      >
        {(d) => (
          <div className="panel table-scroll" role="region" aria-label="Repositories" tabIndex={0}>
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">Repository</th>
                  <th scope="col">Last run</th>
                  <th scope="col">Latest findings</th>
                  <th scope="col" className="col--secondary">Last event</th>
                  <th scope="col" className="col--secondary">AI policy</th>
                </tr>
              </thead>
              <tbody>
                {d.items.map((r) => (
                  <tr key={r.id}>
                    <td>
                      <RepoLabel repo={{ id: r.id, full_name: r.full_name, private: r.private }} />
                      {!r.analysis_enabled && <span className="tag">analysis off</span>}
                      {r.removed && <span className="tag">removed</span>}
                    </td>
                    <td>
                      {r.last_run ? (
                        <Link to={`/runs/${r.last_run.id}`}>
                          <StatusIndicator status={`run_${r.last_run.status}`} />
                        </Link>
                      ) : (
                        <span className="muted">No runs</span>
                      )}
                    </td>
                    <td>
                      {r.last_run ? <SeverityCounts counts={r.last_run.findings_by_severity} /> : <span className="muted">—</span>}
                    </td>
                    <td className="col--secondary">
                      <RelTime iso={r.last_event_at} />
                    </td>
                    <td className="col--secondary">{POLICY_LABELS[r.llm_policy]}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Resource>
    </div>
  );
}

export function RepositoryDetail() {
  const { id } = useParams();
  const api = useApi(`/api/repositories/${encodeURIComponent(id)}`);
  const name = api.data?.full_name ?? 'Repository';

  return (
    <div className="page">
      <PageHeader
        title={name}
        subtitle={
          api.data ? (
            <>
              {api.data.private ? 'Private' : 'Public'} · default branch <code>{api.data.default_branch ?? '—'}</code> ·{' '}
              {POLICY_LABELS[api.data.llm_policy]}
              {!api.data.analysis_enabled && ' · analysis turned off'}
            </>
          ) : null
        }
      />
      {api.error?.status === 404 ? (
        <EmptyState title="Repository not found">
          <p>
            It may have been removed. <Link to="/repositories">Back to repositories</Link>
          </p>
        </EmptyState>
      ) : (
        <Resource api={api} label="this repository">
          {(r) => (
            <>
              <Panel
                title="Findings from the latest completed run"
                meta={r.latest_completed_run_id ? <Link to={`/runs/${r.latest_completed_run_id}`}>Run #{r.latest_completed_run_id}</Link> : null}
                className="workers-panel"
              >
                {r.latest_completed_run_id === null ? (
                  <EmptyState title="No completed analysis yet" />
                ) : r.latest_findings.length ? (
                  <FindingsTable findings={r.latest_findings} showRepo={false} />
                ) : (
                  <EmptyState title="No findings in the latest run">
                    <p>Check the run's timeline for anything that was skipped or not checked.</p>
                  </EmptyState>
                )}
              </Panel>
              <Panel title="Recent runs" className="workers-panel">
                {r.recent_runs.length ? <RunsTable runs={r.recent_runs} showRepo={false} /> : <EmptyState title="No runs yet" />}
              </Panel>
              <p className="page__footnote">
                <RepoLabel repo={{ id: r.id, full_name: r.full_name, private: r.private }} link={false} /> · connected{' '}
                <RelTime iso={r.created_at} />
              </p>
            </>
          )}
        </Resource>
      )}
    </div>
  );
}
