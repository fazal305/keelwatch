import { useRef, useState } from 'react';
import { Link } from 'react-router';
import { useAuth } from '../auth/AuthContext.js';
import { EmptyState, InlineError, Panel } from '../components/Feedback.jsx';
import { PageHeader, RelTime, RepoLabel } from '../components/Page.jsx';
import { Resource } from '../components/Resource.jsx';
import { StatusIndicator } from '../components/StatusIndicator.jsx';
import { fetchJson } from '../lib/api.js';
import { SEVERITIES } from '../lib/status.js';
import { useApi } from '../lib/useApi.js';

const KIND_LABELS = { slack: 'Slack', discord: 'Discord' };
const URL_SHAPES = {
  slack: 'https://hooks.slack.com/services/…',
  discord: 'https://discord.com/api/webhooks/…',
};
const SEVERITY_HINT = {
  critical: 'Critical only',
  high: 'High and above',
  medium: 'Medium and above',
  low: 'Low and above',
  info: 'Everything',
};

/** Mirrors the server's rules closely enough to catch typos; the server decides. */
function checkUrl(kind, raw) {
  const url = raw.trim();
  if (!url) return 'Paste the webhook URL.';
  let parsed;
  try {
    parsed = new URL(url);
  } catch {
    return 'That doesn’t look like a URL.';
  }
  if (parsed.protocol !== 'https:') return 'Webhook URLs must use https.';
  const hosts = kind === 'slack' ? ['hooks.slack.com'] : ['discord.com', 'discordapp.com'];
  if (!hosts.includes(parsed.hostname)) return `Expected ${URL_SHAPES[kind]}`;
  return null;
}

function Field({ id, label, error, hint, children }) {
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {children}
      {hint && (
        <p className="field__hint" id={`${id}-hint`}>
          {hint}
        </p>
      )}
      {error && (
        <p className="field__error" id={`${id}-error`}>
          {error}
        </p>
      )}
    </div>
  );
}

const describedBy = (id, hint, error) =>
  [hint ? `${id}-hint` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

function AddDestination({ installations, onAdded }) {
  const active = installations.filter((i) => i.status === 'active');
  const initial = { installation_id: active.length === 1 ? String(active[0].id) : '', kind: 'slack', label: '', url: '', min_severity: 'high' };
  const [values, setValues] = useState(initial);
  const [errors, setErrors] = useState({});
  const [state, setState] = useState({ saving: false, error: null });
  const order = ['installation_id', 'kind', 'label', 'url', 'min_severity'];

  if (active.length === 0) {
    return (
      <EmptyState title="No GitHub account to attach a destination to">
        <p>Install the Keelwatch GitHub App on an account first. Destinations belong to an installation.</p>
      </EmptyState>
    );
  }

  const update = (name) => (event) => {
    setValues((v) => ({ ...v, [name]: event.target.value }));
    setErrors((e) => ({ ...e, [name]: undefined }));
  };

  const focusFirst = (found) => {
    const first = order.find((f) => found[f]);
    if (first) document.getElementById(`dest-${first}`)?.focus();
    return Boolean(first);
  };

  const onSubmit = async (event) => {
    event.preventDefault();
    const found = {};
    if (!values.installation_id) found.installation_id = 'Choose a GitHub account.';
    const label = values.label.trim();
    if (!label) found.label = 'Give it a name you’ll recognise, like #eng-alerts.';
    else if (label.length > 100) found.label = 'Use at most 100 characters.';
    const urlError = checkUrl(values.kind, values.url);
    if (urlError) found.url = urlError;
    setErrors(found);
    setState({ saving: false, error: null });
    if (focusFirst(found)) return;

    setState({ saving: true, error: null });
    try {
      const created = await fetchJson('/api/destinations', {
        method: 'POST',
        body: { ...values, installation_id: Number(values.installation_id), label, url: values.url.trim() },
      });
      setValues({ ...initial, installation_id: values.installation_id });
      setState({ saving: false, error: null });
      onAdded(created);
    } catch (error) {
      // A 422 without per-field errors still has to be shown somewhere.
      const fieldErrors = error.kind === 'validation' && error.fields ? error.fields : null;
      setState({ saving: false, error: fieldErrors ? null : error });
      if (fieldErrors) {
        setErrors(error.fields);
        focusFirst(error.fields);
      }
    }
  };

  const input = (name, props = {}) => ({
    id: `dest-${name}`,
    name,
    value: values[name],
    onChange: update(name),
    'aria-invalid': Boolean(errors[name]),
    ...props,
  });

  return (
    <form className="form-grid" onSubmit={onSubmit} noValidate aria-label="Add a destination">
      <Field id="dest-installation_id" label="GitHub account" error={errors.installation_id}>
        <select {...input('installation_id', { 'aria-describedby': describedBy('dest-installation_id', false, errors.installation_id) })}>
          <option value="">Choose…</option>
          {active.map((i) => (
            <option key={i.id} value={i.id}>
              {i.account_login}
            </option>
          ))}
        </select>
      </Field>
      <Field id="dest-kind" label="Service" error={errors.kind}>
        <select {...input('kind')}>
          <option value="slack">Slack</option>
          <option value="discord">Discord</option>
        </select>
      </Field>
      <Field id="dest-label" label="Name" error={errors.label}>
        <input {...input('label', { maxLength: 100, autoComplete: 'off', 'aria-describedby': describedBy('dest-label', false, errors.label) })} />
      </Field>
      <Field id="dest-min_severity" label="Notify for" error={errors.min_severity}>
        <select {...input('min_severity')}>
          {SEVERITIES.map((s) => (
            <option key={s} value={s}>
              {SEVERITY_HINT[s]}
            </option>
          ))}
        </select>
      </Field>
      <Field
        id="dest-url"
        label="Webhook URL"
        error={errors.url}
        hint={`${URL_SHAPES[values.kind]} — treated as a password: stored encrypted and never shown again.`}
      >
        <input
          {...input('url', {
            type: 'password',
            autoComplete: 'off',
            spellCheck: false,
            'aria-describedby': describedBy('dest-url', true, errors.url),
          })}
        />
      </Field>
      <div className="form-grid__actions">
        <button type="submit" className="button button--primary" disabled={state.saving}>
          {state.saving ? 'Adding…' : 'Add destination'}
        </button>
      </div>
      {state.error && (
        <div className="form-grid__full">
          <InlineError title="Couldn’t add the destination" message={state.error.message} correlationId={state.error.correlationId} />
        </div>
      )}
    </form>
  );
}

function LastDelivery({ delivery, sentCount }) {
  if (!delivery) return <span className="muted">Nothing sent yet</span>;
  return (
    <span className="stack-sm">
      <StatusIndicator status={`notify_${delivery.status}`} />
      <span className="table__sub">
        <RelTime iso={delivery.attempted_at} />
        {delivery.error ? ` · ${delivery.error}` : ''} · {sentCount} sent in total
      </span>
    </span>
  );
}

function DestinationsTable({ items, isAdmin, onChanged, onResult }) {
  const dialogRef = useRef(null);
  const [dialog, setDialog] = useState(null); // {mode: 'delete'|'replace', dest}
  const [replaceUrl, setReplaceUrl] = useState('');
  const [replaceError, setReplaceError] = useState(null);
  const [busy, setBusy] = useState(null);

  // Controls stay focusable while saving (a disabled control drops keyboard
  // focus to <body>); repeat clicks are ignored here instead.
  const mutate = async (dest, method, body, message) => {
    if (busy !== null) return false;
    setBusy(dest.id);
    onResult(null);
    try {
      await fetchJson(`/api/destinations/${dest.id}`, { method, body });
      onResult({ ok: true, message });
      onChanged();
      return true;
    } catch (error) {
      if (error.kind === 'validation' && error.fields?.url) {
        setReplaceError(error.fields.url);
        return false;
      }
      onResult({ ok: false, error });
      return false;
    } finally {
      setBusy(null);
    }
  };

  const open = (mode, dest) => {
    setDialog({ mode, dest });
    setReplaceUrl('');
    setReplaceError(null);
    dialogRef.current?.showModal();
  };

  const confirm = async (event) => {
    event.preventDefault();
    const { mode, dest } = dialog;
    if (mode === 'replace') {
      const problem = checkUrl(dest.kind, replaceUrl);
      if (problem) {
        setReplaceError(problem);
        return;
      }
      if (await mutate(dest, 'PATCH', { url: replaceUrl.trim() }, `Webhook URL for “${dest.label}” replaced.`)) {
        dialogRef.current?.close();
      }
      return;
    }
    dialogRef.current?.close();
    if (await mutate(dest, 'DELETE', undefined, `“${dest.label}” removed.`)) {
      // The focused row is gone; land on the panel instead of <body>.
      document.getElementById('destinations')?.focus();
    }
  };

  return (
    <>
      <div className="table-scroll" role="region" aria-label="Notification destinations" tabIndex={0}>
        <table className="table">
          <thead>
            <tr>
              <th scope="col">Destination</th>
              <th scope="col">Status</th>
              <th scope="col">Notify for</th>
              <th scope="col" className="col--secondary">Last delivery</th>
              {isAdmin && (
                <th scope="col">
                  <span className="visually-hidden">Actions</span>
                </th>
              )}
            </tr>
          </thead>
          <tbody>
            {items.map((d) => (
              <tr key={d.id}>
                <td className="table__wrap">
                  <strong>{d.label}</strong>
                  <span className="table__sub">
                    {KIND_LABELS[d.kind]} · <code>{d.url_host}</code> · {d.installation.account_login}
                  </span>
                </td>
                <td>
                  <StatusIndicator status={d.enabled ? 'ok' : 'unknown'} label={d.enabled ? 'On' : 'Paused'} />
                </td>
                <td>
                  {isAdmin ? (
                    <select
                      className="select--compact"
                      aria-label={`Notify ${d.label} for`}
                      value={d.min_severity}
                      aria-disabled={busy === d.id}
                      onChange={(e) => mutate(d, 'PATCH', { min_severity: e.target.value }, `“${d.label}” now gets ${SEVERITY_HINT[e.target.value].toLowerCase()}.`)}
                    >
                      {SEVERITIES.map((s) => (
                        <option key={s} value={s}>
                          {SEVERITY_HINT[s]}
                        </option>
                      ))}
                    </select>
                  ) : (
                    SEVERITY_HINT[d.min_severity]
                  )}
                </td>
                <td className="col--secondary">
                  <LastDelivery delivery={d.last_delivery} sentCount={d.sent_count} />
                </td>
                {isAdmin && (
                  <td>
                    <div className="row-actions">
                      <button
                        type="button"
                        className="button button--small"
                        aria-disabled={busy === d.id}
                        onClick={() => mutate(d, 'PATCH', { enabled: !d.enabled }, d.enabled ? `“${d.label}” paused.` : `“${d.label}” turned on.`)}
                      >
                        {d.enabled ? 'Pause' : 'Turn on'}
                      </button>
                      <button type="button" className="button button--small" aria-disabled={busy === d.id} onClick={() => open('replace', d)}>
                        Replace URL
                      </button>
                      <button type="button" className="button button--small button--danger" aria-disabled={busy === d.id} onClick={() => open('delete', d)}>
                        Remove
                      </button>
                    </div>
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <dialog ref={dialogRef} className="dialog" onClose={() => setDialog(null)} aria-labelledby="dest-dialog-title">
        {dialog && (
          <form onSubmit={confirm} noValidate>
            <h2 id="dest-dialog-title">
              {dialog.mode === 'delete' ? `Remove “${dialog.dest.label}”?` : `Replace the URL for “${dialog.dest.label}”`}
            </h2>
            {dialog.mode === 'delete' ? (
              <p>Keelwatch stops sending here and forgets the URL. Its delivery history is removed too. To stop temporarily, pause it instead.</p>
            ) : (
              <div className="field">
                <label htmlFor="replace-url">New webhook URL</label>
                <input
                  id="replace-url"
                  type="password"
                  autoComplete="off"
                  spellCheck={false}
                  value={replaceUrl}
                  onChange={(e) => {
                    setReplaceUrl(e.target.value);
                    setReplaceError(null);
                  }}
                  aria-invalid={Boolean(replaceError)}
                  aria-describedby={replaceError ? 'replace-url-hint replace-url-error' : 'replace-url-hint'}
                  autoFocus
                />
                <p className="field__hint" id="replace-url-hint">
                  {URL_SHAPES[dialog.dest.kind]} Use this after revoking a leaked webhook.
                </p>
                {replaceError && (
                  <p className="field__error" id="replace-url-error">
                    {replaceError}
                  </p>
                )}
              </div>
            )}
            <div className="dialog__actions">
              <button type="button" className="button" onClick={() => dialogRef.current?.close()}>
                Keep as is
              </button>
              <button type="submit" className={`button ${dialog.mode === 'delete' ? 'button--danger' : 'button--primary'}`} disabled={busy === dialog.dest.id}>
                {dialog.mode === 'delete' ? 'Remove' : 'Replace URL'}
              </button>
            </div>
          </form>
        )}
      </dialog>
    </>
  );
}

function Destinations({ isAdmin }) {
  const api = useApi('/api/destinations');
  const [added, setAdded] = useState(null);
  // Lives here, not in the table, so a confirmation survives the last row being removed.
  const [result, setResult] = useState(null);
  const onResult = (next) => {
    setResult(next);
    if (next) setAdded(null);
  };
  return (
    <Panel title="Notification destinations" className="workers-panel" id="destinations" tabIndex={-1}>
      <p className="panel__intro">
        Digests of each analysis run (and a daily summary) are posted here. Every digest is also kept under{' '}
        <Link to="/digests">Digests</Link>, whether or not it was sent.
      </p>
      <Resource api={api} label="destinations">
        {(d) => (
          <>
            {!d.encryption_configured && (
              <p className="notice notice--warning">
                Notifications are off: the server has no <code>NOTIFICATION_KEY</code>. Set it in the server’s <code>.env</code> (see{' '}
                <code>.env.example</code>) and restart the API and worker to add destinations.
              </p>
            )}
            {d.items.length ? (
              <DestinationsTable items={d.items} isAdmin={isAdmin} onChanged={api.reload} onResult={onResult} />
            ) : (
              <EmptyState title="No destinations yet">
                <p>Nothing is sent anywhere until you add one. Digests are still created and stored.</p>
              </EmptyState>
            )}
            <div aria-live="polite">
              {result?.ok && <p className="notice notice--success">{result.message}</p>}
              {result && !result.ok && (
                <InlineError
                  title={result.error.kind === 'forbidden' ? 'Not allowed' : 'Couldn’t save the change'}
                  message={result.error.message}
                  correlationId={result.error.correlationId}
                />
              )}
            </div>
            {isAdmin && d.encryption_configured && (
              <section className="subsection" aria-labelledby="add-destination-title">
                <h3 id="add-destination-title" className="subsection__title">
                  Add a destination
                </h3>
                <div aria-live="polite">
                  {added && (
                    <p className="notice notice--success">
                      Added “{added.label}”. The URL is stored encrypted and won’t be shown again.
                    </p>
                  )}
                </div>
                <AddDestination
                  installations={d.installations}
                  onAdded={(created) => {
                    setAdded(created);
                    setResult(null);
                    api.reload();
                  }}
                />
              </section>
            )}
            {!isAdmin && <p className="muted">Only administrators can change destinations.</p>}
          </>
        )}
      </Resource>
    </Panel>
  );
}

const POLICY_OPTIONS = [
  { value: 'none', label: 'Off' },
  { value: 'public_only', label: 'Only while public' },
  { value: 'allowed', label: 'Allowed' },
];

function policyNote(repo) {
  if (repo.llm_policy === 'none') return 'No code is sent to an AI provider.';
  if (repo.is_private && repo.llm_policy === 'public_only') return 'Off while this repository is private.';
  if (repo.is_private) return 'Sent only to providers configured as not training on inputs.';
  return 'Changed code may be sent to an AI provider, if one is configured.';
}

function RepositorySettings({ isAdmin }) {
  const api = useApi('/api/repositories');
  const [busy, setBusy] = useState(null);
  const [result, setResult] = useState(null);

  const save = async (repo, changes, message) => {
    if (busy !== null) return;
    setBusy(repo.id);
    setResult(null);
    try {
      await fetchJson(`/api/repositories/${repo.id}/settings`, { method: 'PATCH', body: changes });
      setResult({ ok: true, message });
      api.reload();
    } catch (error) {
      setResult({ ok: false, error });
    } finally {
      setBusy(null);
    }
  };

  return (
    <Panel title="Repository analysis" className="workers-panel">
      <p className="panel__intro">
        Paused repositories still record GitHub events, but no analysis runs start. Rule and vulnerability checks always run. AI review
        also needs an AI provider configured on the worker; new private repositories start with it off.
      </p>
      <Resource
        api={api}
        label="repositories"
        isEmpty={(d) => d.items.length === 0}
        empty={
          <EmptyState title="No repositories yet">
            <p>Repositories appear once the GitHub App is installed and GitHub sends their first event.</p>
          </EmptyState>
        }
      >
        {(d) => (
          <div className="table-scroll" role="region" aria-label="Repository analysis settings" tabIndex={0}>
            <table className="table">
              <thead>
                <tr>
                  <th scope="col">Repository</th>
                  <th scope="col">Analysis</th>
                  <th scope="col">AI review</th>
                </tr>
              </thead>
              <tbody>
                {d.items.map((repo) => (
                  <tr key={repo.id}>
                    <td className="table__wrap">
                      <RepoLabel repo={repo} />
                      <span className="table__sub">{repo.is_private ? 'Private' : 'Public'}</span>
                    </td>
                    <td>
                      {isAdmin ? (
                        <label className="toggle">
                          <input
                            type="checkbox"
                            aria-label={`Analyse ${repo.full_name}`}
                            checked={repo.analysis_enabled}
                            aria-disabled={busy === repo.id}
                            onChange={(e) =>
                              save(
                                repo,
                                { analysis_enabled: e.target.checked },
                                e.target.checked ? `Analysis turned on for ${repo.full_name}.` : `Analysis paused for ${repo.full_name}.`,
                              )
                            }
                          />
                          <span>Analyse</span>
                        </label>
                      ) : (
                        <StatusIndicator status={repo.analysis_enabled ? 'ok' : 'unknown'} label={repo.analysis_enabled ? 'On' : 'Paused'} />
                      )}
                    </td>
                    <td className="table__wrap">
                      {isAdmin ? (
                        <select
                          className="select--compact"
                          aria-label={`AI review for ${repo.full_name}`}
                          aria-describedby={`policy-${repo.id}`}
                          value={repo.llm_policy}
                          aria-disabled={busy === repo.id}
                          onChange={(e) =>
                            save(repo, { llm_policy: e.target.value }, `AI review for ${repo.full_name}: ${POLICY_OPTIONS.find((o) => o.value === e.target.value).label.toLowerCase()}.`)
                          }
                        >
                          {POLICY_OPTIONS.map((o) => (
                            <option key={o.value} value={o.value}>
                              {o.label}
                            </option>
                          ))}
                        </select>
                      ) : (
                        POLICY_OPTIONS.find((o) => o.value === repo.llm_policy)?.label
                      )}
                      <span className="table__sub" id={`policy-${repo.id}`}>
                        {policyNote(repo)}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Resource>
      <div aria-live="polite">
        {result?.ok && <p className="notice notice--success">{result.message}</p>}
        {result && !result.ok && (
          <InlineError
            title={result.error.kind === 'forbidden' ? 'Not allowed' : 'Couldn’t save the setting'}
            message={result.error.message}
            correlationId={result.error.correlationId}
          />
        )}
      </div>
    </Panel>
  );
}

export function Integrations() {
  const { isAdmin } = useAuth();
  return (
    <div className="page">
      <PageHeader title="Integrations" subtitle="Where digests are sent, and what each repository runs." />
      <Destinations isAdmin={isAdmin} />
      <RepositorySettings isAdmin={isAdmin} />
    </div>
  );
}
