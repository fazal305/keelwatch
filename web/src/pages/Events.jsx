import { Link } from 'react-router';
import { EmptyState } from '../components/Feedback.jsx';
import { FilterBar, LoadMore, NoResults, PageHeader, RelTime, RepoLabel, SelectFilter, Sha } from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { useFilters } from '../lib/pageHooks.js';
import { StatusIndicator } from '../components/StatusIndicator.jsx';
import { usePaged } from '../lib/usePaged.js';

const IGNORE_REASONS = {
  ping: 'GitHub ping',
  unsupported_event: 'event type not analysed',
  unsupported_action: 'action not analysed',
  branch_deleted: 'branch deleted',
  no_installation: 'not from the GitHub App',
  invalid_payload: 'unexpected payload shape',
  analysis_disabled: 'analysis turned off',
};

export function Events() {
  const filters = useFilters(['status', 'event']);
  const paged = usePaged('/api/events', filters.values);

  return (
    <div className="page">
      <PageHeader title="Events" subtitle="Every verified GitHub delivery, and what Keelwatch did with it." />
      <FilterBar active={filters.active} onClear={filters.clear}>
        <SelectFilter label="Outcome" value={filters.values.status} options={['accepted', 'ignored']} onChange={(v) => filters.set('status', v)} />
        <SelectFilter
          label="Event"
          value={filters.values.event}
          options={['pull_request', 'push', 'installation', 'installation_repositories', 'ping']}
          onChange={(v) => filters.set('event', v)}
        />
      </FilterBar>
      <Resource
        api={paged}
        label="events"
        isEmpty={(items) => items.length === 0}
        empty={
          filters.active ? (
            <NoResults onClear={filters.clear} />
          ) : (
            <EmptyState title="No deliveries yet">
              <p>Deliveries appear here once GitHub sends a signed webhook. Rejected (unsigned) requests are logged, not listed.</p>
            </EmptyState>
          )
        }
      >
        {(items) => (
          <>
            <div className="panel table-scroll" role="region" aria-label="Events" tabIndex={0}>
              <table className="table">
                <thead>
                  <tr>
                    <th scope="col">Received</th>
                    <th scope="col">Event</th>
                    <th scope="col">Outcome</th>
                    <th scope="col" className="col--secondary">Repository</th>
                    <th scope="col" className="col--secondary">Commit</th>
                    <th scope="col">Run</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((e) => (
                    <tr key={e.id}>
                      <td>
                        <RelTime iso={e.received_at} />
                      </td>
                      <td className="table__wrap">
                        <code>{e.type ?? (e.action ? `${e.event}.${e.action}` : e.event)}</code>
                        {e.pull_request && <span className="table__sub">PR #{e.pull_request}</span>}
                      </td>
                      <td className="table__wrap">
                        <StatusIndicator status={`delivery_${e.status}`} />
                        {e.ignore_reason && <span className="table__sub">{IGNORE_REASONS[e.ignore_reason] ?? e.ignore_reason}</span>}
                      </td>
                      <td className="col--secondary">
                        <RepoLabel repo={e.repository} />
                      </td>
                      <td className="col--secondary">
                        <Sha sha={e.head_sha} />
                      </td>
                      <td>{e.run_id ? <Link to={`/runs/${e.run_id}`}>#{e.run_id}</Link> : <span className="muted">—</span>}</td>
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
