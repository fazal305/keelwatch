import { useId, useMemo } from 'react';
import { Link } from 'react-router';
import { EmptyState, Panel } from '../components/Feedback.jsx';
import { PageHeader, SelectFilter } from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { SeverityTag } from '../components/Severity.jsx';
import { TrendChart } from '../components/TrendChart.jsx';
import { withQuery } from '../lib/api.js';
import { formatMs } from '../lib/format.js';
import { useFilters } from '../lib/pageHooks.js';
import { SEVERITIES } from '../lib/status.js';
import { useApi } from '../lib/useApi.js';

const RANGES = ['7', '30', '90'];
const DEFAULT_RANGE = '30';
const CATEGORY_LABELS = {
  security: 'Security',
  dependency: 'Dependencies',
  logic: 'Logic',
  architecture: 'Architecture',
  quality: 'Quality',
};

const dayFormatter = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', timeZone: 'UTC' });
const longDayFormatter = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
const shortDay = (iso) => dayFormatter.format(new Date(`${iso}T00:00:00Z`));
const longDay = (iso) => longDayFormatter.format(new Date(`${iso}T00:00:00Z`));
const count = (n) => new Intl.NumberFormat().format(n);
const plural = (n, word) => `${count(n)} ${word}${n === 1 ? '' : 's'}`;
const seconds = (ms) => (ms >= 60_000 ? `${Math.round(ms / 60_000)} min` : `${Math.round(ms / 100) / 10} s`);

function RangeFilter({ value, onChange }) {
  const name = useId();
  return (
    <fieldset className="filter segmented">
      <legend>Period</legend>
      <div className="segmented__options">
        {RANGES.map((r) => (
          <label key={r} className="segmented__option">
            <input type="radio" name={name} value={r} checked={value === r} onChange={() => onChange(r === DEFAULT_RANGE ? '' : r)} />
            <span>{r} days</span>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

function StatTile({ label, value, detail }) {
  return (
    <div className="stat-tile">
      <dt className="stat-tile__label">{label}</dt>
      <dd className="stat-tile__value">{value}</dd>
      {detail && <dd className="stat-tile__detail">{detail}</dd>}
    </div>
  );
}

/** The accessible twin of every chart: the same numbers as a table. */
function TableView({ caption, columns, rows }) {
  return (
    <details className="table-view">
      <summary>Show as table</summary>
      <div className="table-scroll" role="region" aria-label={caption} tabIndex={0}>
        <table className="table table--compact">
          <caption className="visually-hidden">{caption}</caption>
          <thead>
            <tr>
              {columns.map((c) => (
                <th key={c.key} scope="col" className={c.numeric ? 'num' : undefined}>
                  {c.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.key}>
                {columns.map((c, i) =>
                  i === 0 ? (
                    <th key={c.key} scope="row">
                      {row[c.key]}
                    </th>
                  ) : (
                    <td key={c.key} className={c.numeric ? 'num' : undefined}>
                      {row[c.key] ?? '—'}
                    </td>
                  ),
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </details>
  );
}

/** Totals as plain HTML bars: readable without a chart library or a table twin. */
function BarList({ title, entries, total, renderLabel }) {
  const max = Math.max(1, ...entries.map((e) => e.value));
  return (
    <Panel title={title} className="bar-list-panel">
      {total === 0 ? (
        <p className="muted">No findings in this period.</p>
      ) : (
        <ul className="bar-list">
          {entries.map((e) => (
            <li key={e.key} className="bar-list__row">
              <span className="bar-list__label">{renderLabel(e.key)}</span>
              <span className="bar-list__track" aria-hidden="true">
                {e.value > 0 && <span className="bar-list__bar" style={{ width: `${(e.value / max) * 100}%` }} />}
              </span>
              <span className="bar-list__value num">{count(e.value)}</span>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

function Charts({ data }) {
  const { days, totals } = data;
  const labels = useMemo(() => days.map((d) => shortDay(d.date)), [days]);

  const runSeries = useMemo(
    () => [
      { key: 'failed', label: 'Failed', color: 'critical', values: days.map((d) => d.runs_failed) },
      { key: 'other', label: 'Other outcomes', color: 'rest', values: days.map((d) => (d.runs == null ? null : d.runs - d.runs_failed)) },
    ],
    [days],
  );
  const findingSeries = useMemo(
    () => [
      { key: 'new', label: 'New', color: 'series1', values: days.map((d) => d.findings_new) },
      { key: 'recurring', label: 'Recurring', color: 'rest', values: days.map((d) => d.findings_recurring) },
    ],
    [days],
  );
  const durationSeries = useMemo(
    () => [
      { key: 'p50', label: 'Median (p50)', color: 'series1', values: days.map((d) => d.duration_p50_ms) },
      { key: 'p95', label: 'Slowest 5% (p95)', color: 'series2', values: days.map((d) => d.duration_p95_ms) },
    ],
    [days],
  );
  const hasDurations = days.some((d) => d.duration_p50_ms != null);
  const rows = days.map((d) => ({ key: d.date, ...d, date: longDay(d.date) }));
  const failedDays = days.filter((d) => d.runs_failed > 0).length;
  const watchedDays = days.filter((d) => d.runs != null).length;

  return (
    <div className="analytics-grid">
      <Panel title="Analysis runs per day" className="analytics-grid__wide">
        <TrendChart
          labels={labels}
          series={runSeries}
          format={count}
          summary={`Analysis runs per day: ${plural(totals.runs, 'run')}, ${count(totals.runs_failed)} failed, with failures on ${plural(failedDays, 'day')} of ${plural(watchedDays, 'watched day')}.`}
        />
        <TableView
          caption="Analysis runs per day"
          columns={[
            { key: 'date', label: 'Day (UTC)' },
            { key: 'runs', label: 'Runs', numeric: true },
            { key: 'runs_failed', label: 'Failed', numeric: true },
          ]}
          rows={rows}
        />
      </Panel>

      <Panel title="Findings per day" className="analytics-grid__wide">
        <TrendChart
          labels={labels}
          series={findingSeries}
          format={count}
          summary={`Findings per day: ${count(totals.findings_new)} new and ${count(totals.findings_recurring)} recurring in the period.`}
        />
        <p className="chart-note">“New” is the first time an issue appears in a repository; “recurring” was already reported by an earlier run.</p>
        <TableView
          caption="Findings per day"
          columns={[
            { key: 'date', label: 'Day (UTC)' },
            { key: 'findings_new', label: 'New', numeric: true },
            { key: 'findings_recurring', label: 'Recurring', numeric: true },
          ]}
          rows={rows}
        />
      </Panel>

      <Panel title="Run time of completed runs" className="analytics-grid__wide">
        {hasDurations ? (
          <>
            <TrendChart
              kind="line"
              labels={labels}
              series={durationSeries}
              format={seconds}
              summary={`Run time of completed runs; median over the period ${formatMs(totals.duration_p50_ms)}.`}
            />
            <p className="chart-note">
              Days with fewer than {data.min_duration_samples} completed runs have no percentile and appear as gaps. Failed and cancelled
              runs are left out: their run time says nothing about how long analysis takes.
            </p>
            <TableView
              caption="Run time of completed runs"
              columns={[
                { key: 'date', label: 'Day (UTC)' },
                { key: 'completed_runs', label: 'Completed runs', numeric: true },
                { key: 'p50', label: 'Median', numeric: true },
                { key: 'p95', label: 'p95', numeric: true },
              ]}
              rows={rows.map((r) => ({
                ...r,
                p50: r.duration_p50_ms == null ? null : formatMs(r.duration_p50_ms),
                p95: r.duration_p95_ms == null ? null : formatMs(r.duration_p95_ms),
              }))}
            />
          </>
        ) : (
          <EmptyState title="Not enough completed runs for a trend">
            <p>
              A day needs at least {data.min_duration_samples} completed runs before its median and p95 mean anything. {count(totals.completed_runs)}{' '}
              completed in this period.
            </p>
          </EmptyState>
        )}
      </Panel>

      <BarList
        title="Findings by severity"
        total={totals.findings_new + totals.findings_recurring}
        entries={SEVERITIES.map((s) => ({ key: s, value: data.findings_by_severity[s] ?? 0 }))}
        renderLabel={(s) => <SeverityTag severity={s} />}
      />
      <BarList
        title="Findings by category"
        total={totals.findings_new + totals.findings_recurring}
        entries={Object.keys(CATEGORY_LABELS).map((c) => ({ key: c, value: data.findings_by_category[c] ?? 0 }))}
        renderLabel={(c) => CATEGORY_LABELS[c]}
      />
    </div>
  );
}

function Report({ data }) {
  const { totals, days } = data;
  const activeDays = days.filter((d) => d.runs > 0).length;
  const knownDays = days.filter((d) => d.runs != null).length;
  const failRate = totals.runs ? Math.round((totals.runs_failed / totals.runs) * 100) : 0;
  const quiet = totals.runs === 0 && totals.findings_new + totals.findings_recurring === 0;

  return (
    <>
      <dl className="stat-tiles">
        <StatTile label="Analysis runs" value={count(totals.runs)} detail={`on ${activeDays} of ${plural(knownDays, 'watched day')}`} />
        <StatTile label="Failed runs" value={count(totals.runs_failed)} detail={totals.runs ? `${failRate}% of runs` : 'No runs yet'} />
        <StatTile label="New findings" value={count(totals.findings_new)} detail={`${count(totals.findings_recurring)} recurring`} />
        <StatTile
          label="Median run time"
          value={totals.duration_p50_ms == null ? '—' : formatMs(totals.duration_p50_ms)}
          detail={
            totals.duration_p50_ms == null
              ? `Needs ${data.min_duration_samples} completed runs (${count(totals.completed_runs)} so far)`
              : `across ${count(totals.completed_runs)} completed runs`
          }
        />
        <StatTile
          label="Notifications sent"
          value={totals.notifications ? count(totals.notifications.sent) : '—'}
          detail={
            totals.notifications
              ? `${count(totals.notifications.failed)} failed attempts`
              : 'Only for all repositories: daily digests span an installation'
          }
        />
      </dl>

      {knownDays < days.length && (
        <p className="notice">
          {data.watching_since
            ? `Keelwatch started watching ${data.repository_id ? 'this repository' : 'repositories'} on ${longDay(data.watching_since)}. Earlier days show no data, not zero.`
            : 'No repositories are being watched yet, so there is no history to show.'}
        </p>
      )}
      {!quiet && activeDays > 0 && activeDays < 3 && (
        <p className="notice">Only {activeDays} {activeDays === 1 ? 'day has' : 'days have'} activity in this period. Treat these as snapshots, not trends.</p>
      )}

      {quiet ? (
        <Panel title="No analysis activity in this period">
          <EmptyState title="Nothing to chart yet">
            <p>
              Runs start when a pull request opens or updates, or when commits are pushed to a default branch. Check{' '}
              <Link to="/events">Events</Link> to confirm GitHub deliveries are arriving, or choose a longer period.
            </p>
          </EmptyState>
        </Panel>
      ) : (
        <Charts data={data} />
      )}
    </>
  );
}

export function Analytics() {
  const filters = useFilters(['days', 'repository_id']);
  const days = RANGES.includes(filters.values.days) ? filters.values.days : DEFAULT_RANGE;
  const api = useApi(withQuery('/api/analytics', { days, repository_id: filters.values.repository_id }));
  const repos = useApi('/api/repositories');

  return (
    <div className="page">
      <PageHeader title="Analytics" subtitle="Trends in analysis runs and findings. Days are calendar days in UTC." />
      <div className="filter-bar analytics-filters">
        <RangeFilter value={days} onChange={(v) => filters.set('days', v)} />
        <SelectFilter
          label="Repository"
          allLabel="All repositories"
          value={filters.values.repository_id}
          options={(repos.data?.items ?? []).map((r) => ({ value: String(r.id), label: r.full_name }))}
          onChange={(v) => filters.set('repository_id', v)}
        />
      </div>
      <div className={api.loading && api.data ? 'is-refreshing' : undefined} aria-busy={api.loading || undefined}>
        <Resource api={api} label="analytics">
          {(data) => <Report data={data} />}
        </Resource>
      </div>
    </div>
  );
}
