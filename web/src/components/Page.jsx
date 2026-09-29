import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router';
import { formatAge, formatDateTime, secondsSince } from '../lib/format.js';
import { usePageTitle } from '../lib/pageHooks.js';
import { useNow } from '../lib/useNow.js';

export function PageHeader({ title, subtitle, actions }) {
  usePageTitle(title);
  return (
    <header className="page__header">
      <div className="page__heading">
        <h1>{title}</h1>
        {subtitle && <p className="page__subtitle">{subtitle}</p>}
      </div>
      {actions && <div className="page__actions">{actions}</div>}
    </header>
  );
}

export function SelectFilter({ label, value, options, onChange, allLabel = 'All' }) {
  const id = useId();
  return (
    <div className="filter">
      <label htmlFor={id}>{label}</label>
      <select id={id} value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">{allLabel}</option>
        {options.map((o) => (
          <option key={o.value ?? o} value={o.value ?? o}>
            {o.label ?? o}
          </option>
        ))}
      </select>
    </div>
  );
}

export function SearchFilter({ label, value, onChange, placeholder }) {
  const id = useId();
  const [draft, setDraft] = useState(value);
  useEffect(() => {
    if (draft === value) return undefined;
    const t = setTimeout(() => onChange(draft.trim()), 350);
    return () => clearTimeout(t);
  }, [draft, value, onChange]);
  return (
    <div className="filter filter--search">
      <label htmlFor={id}>{label}</label>
      <input
        id={id}
        type="search"
        value={draft}
        maxLength={100}
        placeholder={placeholder}
        onChange={(e) => setDraft(e.target.value)}
      />
    </div>
  );
}

export function FilterBar({ children, active, onClear }) {
  return (
    <div className="filter-bar" role="search">
      {children}
      {active && (
        <button type="button" className="button filter-bar__clear" onClick={onClear}>
          Clear filters
        </button>
      )}
    </div>
  );
}

export function NoResults({ onClear }) {
  return (
    <div className="empty-state">
      <p className="empty-state__title">Nothing matches these filters</p>
      <div className="empty-state__body">
        <button type="button" className="button" onClick={onClear}>
          Clear filters
        </button>
      </div>
    </div>
  );
}

export function LoadMore({ hasMore, loading, onClick }) {
  if (!hasMore) return null;
  return (
    <div className="load-more">
      <button type="button" className="button" onClick={onClick} disabled={loading}>
        {loading ? 'Loading…' : 'Load more'}
      </button>
    </div>
  );
}

export function RelTime({ iso }) {
  const now = useNow(30_000);
  if (!iso) return <span className="muted">—</span>;
  return (
    <time dateTime={iso} title={formatDateTime(iso)} className="num">
      {formatAge(secondsSince(iso, now))}
    </time>
  );
}

export function Sha({ sha }) {
  if (!sha) return <span className="muted">—</span>;
  return (
    <code className="sha" title={sha}>
      {sha.slice(0, 7)}
    </code>
  );
}

export function RepoLabel({ repo, link = true }) {
  if (!repo) return <span className="muted">—</span>;
  const name = <span className="repo-label__name">{repo.full_name}</span>;
  return (
    <span className="repo-label">
      {link ? <Link to={`/repositories/${repo.id}`}>{name}</Link> : name}
      {repo.private && <span className="tag">private</span>}
    </span>
  );
}
