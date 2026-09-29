import { useEffect, useState } from 'react';
import { EmptyState, InlineError } from './Feedback.jsx';

/** Shows children only after a short delay, so fast loads don't flash. */
function Delayed({ ms = 300, children }) {
  const [shown, setShown] = useState(false);
  useEffect(() => {
    const id = setTimeout(() => setShown(true), ms);
    return () => clearTimeout(id);
  }, [ms]);
  return shown ? children : null;
}

export function TableSkeleton({ rows = 5 }) {
  return (
    <div className="panel skeleton" aria-hidden="true">
      {Array.from({ length: rows }, (_, i) => (
        <span key={i} className={`skeleton__line${i % 3 === 0 ? ' skeleton__line--mid' : ''}`} />
      ))}
    </div>
  );
}

/**
 * The shared loading / slow / error / empty frame around a page's data.
 * - loading with no data yet: a delayed skeleton
 * - slow: a polite note (the request is still running)
 * - error with no data: an actionable error with retry and reference
 * - error with data (a failed refresh): the error above the last good data
 * - empty: the caller's empty state (distinct from "no results")
 * - invalid filters (422 on a list, e.g. an old bookmark): name them and
 *   offer to clear them, since retrying the same request can't succeed
 */
export function Resource({ api, label, isEmpty, empty, children, onResetFilters }) {
  const { data, error, loading, slow, reload } = api;
  const badFilters = error?.kind === 'validation' && onResetFilters;
  return (
    <>
      {slow && (
        <p className="notice" role="status">
          Loading {label} is taking longer than usual. Still waiting…
        </p>
      )}
      {badFilters && (
        <div className="empty-state" role="alert">
          <p className="empty-state__title">This link has filters that aren’t valid</p>
          <div className="empty-state__body">
            {error.fields && <p>{Object.keys(error.fields).join(', ')}: {Object.values(error.fields)[0]}</p>}
            <button type="button" className="button" onClick={onResetFilters}>
              Clear filters
            </button>
          </div>
        </div>
      )}
      {error && !badFilters && error.kind !== 'unauthenticated' && (
        <InlineError
          title={data ? `Couldn't refresh ${label}` : `Couldn't load ${label}`}
          message={
            // Only an offline browser gets the "reloads by itself" promise: the
            // online event is what triggers the retry (useReconnect).
            error.kind === 'offline'
              ? `${error.message} ${data ? 'Showing what was loaded before. ' : ''}This reloads by itself when you're back online.`
              : error.message
          }
          correlationId={error.correlationId}
          onRetry={reload}
          retrying={loading}
        />
      )}
      {!data && loading && (
        <Delayed>
          <TableSkeleton />
        </Delayed>
      )}
      {data && (isEmpty?.(data) ? empty ?? <EmptyState title={`No ${label} yet`} /> : children(data))}
    </>
  );
}
