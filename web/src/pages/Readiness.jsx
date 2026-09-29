import { Link, useParams } from 'react-router';
import { EmptyState, Panel } from '../components/Feedback.jsx';
import { NoResults, PageHeader, RelTime, SelectFilter } from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { StatusIndicator } from '../components/StatusIndicator.jsx';
import { withQuery } from '../lib/api.js';
import { useFilters } from '../lib/pageHooks.js';
import { useApi } from '../lib/useApi.js';

const status = (s) => `readiness_${s.status}`;

/** Shown wherever readiness appears: the limits travel with the signals. */
function WhatThisIs({ windowDays }) {
  return (
    <Panel title="How to read these signals" className="readiness-note">
      <ul className="prose-list">
        <li>
          These are signals about <strong>repositories</strong>, as Keelwatch observed them over the last {windowDays} days. They are not a
          measure of any person’s skill, and individual commit authors are never scored.
        </li>
        <li>There is no combined score, by design. Each criterion stands alone, with its rule and its limits.</li>
        <li>
          <em>Not enough data</em> means exactly that. It is not a pass or a fail.
        </li>
        <li>Work in other repositories, before Keelwatch was installed, or outside GitHub is invisible here.</li>
      </ul>
    </Panel>
  );
}

function Legend() {
  return (
    <p className="readiness-legend">
      <StatusIndicator status="readiness_met" /> <StatusIndicator status="readiness_not_met" /> <StatusIndicator status="readiness_insufficient" />
    </p>
  );
}

function AccountTable({ account, criteria }) {
  return (
    <Panel
      title={account.account_login}
      meta={
        <span className="muted">
          {account.account_type === 'Organization' ? 'Organization' : 'Personal account'} · {account.repositories.length}{' '}
          {account.repositories.length === 1 ? 'repository' : 'repositories'}
        </span>
      }
      className="workers-panel"
    >
      {/* Phones: one card per repository with every criterion visible, instead of a sideways-scrolling table. */}
      <ul className="readiness-cards">
        {account.repositories.map((repo) => (
          <li key={repo.id} className="readiness-card">
            <Link to={`/readiness/${repo.id}`} className="readiness-card__name">
              {repo.full_name}
            </Link>
            <span className="table__sub">
              {repo.is_private ? 'Private' : 'Public'} · added <RelTime iso={repo.watching_since} />
              {!repo.analysis_enabled && ' · analysis paused'}
            </span>
            <dl className="readiness-card__list">
              {criteria.map((c) => (
                <div key={c.key} className="readiness-card__row">
                  <dt>{c.label}</dt>
                  <dd>
                    <StatusIndicator status={status(repo.signals[c.key])} />
                  </dd>
                </div>
              ))}
            </dl>
          </li>
        ))}
      </ul>
      <div className="table-scroll readiness-table-wrap" role="region" aria-label={`Readiness signals for ${account.account_login}`} tabIndex={0}>
        <table className="table readiness-table">
          <thead>
            <tr>
              <th scope="col">Repository</th>
              {criteria.map((c) => (
                <th key={c.key} scope="col" title={c.definition}>
                  {c.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {account.repositories.map((repo) => (
              <tr key={repo.id}>
                <th scope="row" className="table__wrap">
                  <Link to={`/readiness/${repo.id}`}>{repo.full_name}</Link>
                  <span className="table__sub">
                    {repo.is_private ? 'Private' : 'Public'} · added <RelTime iso={repo.watching_since} />
                    {!repo.analysis_enabled && ' · analysis paused'}
                  </span>
                </th>
                {criteria.map((c) => (
                  <td key={c.key} title={repo.signals[c.key].evidence}>
                    <StatusIndicator status={status(repo.signals[c.key])} />
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}

export function Readiness() {
  const filters = useFilters(['installation_id']);
  const api = useApi(withQuery('/api/readiness', { installation_id: filters.values.installation_id }));

  return (
    <div className="page">
      <PageHeader
        title="Readiness"
        subtitle="Transparent engineering signals per repository, grouped by the GitHub account that connected them."
      />
      <div className="filter-bar analytics-filters">
        <SelectFilter
          label="Account"
          allLabel="All accounts"
          value={filters.values.installation_id}
          options={(api.data?.account_options ?? []).map((i) => ({ value: String(i.id), label: i.account_login }))}
          onChange={(v) => filters.set('installation_id', v)}
        />
      </div>
      <Resource
        api={api}
        label="readiness signals"
        onResetFilters={filters.clear}
        isEmpty={(d) => d.accounts.length === 0}
        empty={
          filters.values.installation_id ? (
            <NoResults onClear={() => filters.set('installation_id', '')} />
          ) : (
            <EmptyState title="No repositories to assess yet">
              <p>Readiness signals appear once the GitHub App is installed on an account and its repositories start sending events.</p>
            </EmptyState>
          )
        }
      >
        {(d) => (
          <>
            <WhatThisIs windowDays={d.window_days} />
            <Legend />
            {d.accounts.map((a) => (
              <AccountTable key={a.id} account={a} criteria={d.criteria} />
            ))}
            <p className="page__footnote">Open a repository to see the evidence, rule and limits behind each signal.</p>
          </>
        )}
      </Resource>
    </div>
  );
}

export function ReadinessDetail() {
  const { id } = useParams();
  const api = useApi(`/api/readiness/repositories/${encodeURIComponent(id)}`);
  const d = api.data;

  return (
    <div className="page">
      <PageHeader
        title={d ? d.repository.full_name : 'Readiness'}
        subtitle={d ? `Readiness signals · ${d.account.account_login} · last ${d.window_days} days` : null}
      />
      {api.error?.status === 404 ? (
        <EmptyState title="Repository not found">
          <p>
            <Link to="/readiness">Back to readiness</Link>
          </p>
        </EmptyState>
      ) : (
        <Resource api={api} label="readiness signals">
          {(data) => {
            // Deliberately no "N of 7 met" tally: a count is a score by another name (ADR 0004).
            return (
              <>
                <ol className="criteria-list">
                  {data.criteria.map((c) => {
                    const s = data.signals[c.key];
                    return (
                      <li key={c.key} className="criterion">
                        <div className="criterion__head">
                          <h2 className="criterion__title">{c.label}</h2>
                          <StatusIndicator status={status(s)} />
                        </div>
                        <p className="criterion__evidence">{s.evidence}</p>
                        <dl className="kv criterion__rule">
                          <dt>Rule</dt>
                          <dd>{c.definition}</dd>
                          <dt>Limits</dt>
                          <dd>{c.limitation}</dd>
                        </dl>
                      </li>
                    );
                  })}
                </ol>
                <WhatThisIs windowDays={data.window_days} />
                <p className="page__footnote">
                  Added to Keelwatch <RelTime iso={data.repository.watching_since} />. See also{' '}
                  <Link to={`/findings?repository_id=${data.repository.id}`}>findings</Link> and{' '}
                  <Link to={`/analytics?repository_id=${data.repository.id}&days=90`}>trends</Link> for this repository.
                </p>
              </>
            );
          }}
        </Resource>
      )}
    </div>
  );
}
