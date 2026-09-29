import { useRef, useState } from 'react';
import { useAuth } from '../auth/AuthContext.js';
import { Panel } from '../components/Feedback.jsx';
import { PageHeader } from '../components/Page.jsx';
import { fetchJson } from '../lib/api.js';

const MIN = 12;

export function Account() {
  const { user, accept } = useAuth();
  const [values, setValues] = useState({ current: '', next: '', confirm: '' });
  const [errors, setErrors] = useState({});
  const [status, setStatus] = useState({ state: 'idle', message: null });
  const refs = { current: useRef(null), next: useRef(null), confirm: useRef(null) };

  const update = (field) => (e) => {
    setValues((v) => ({ ...v, [field]: e.target.value }));
    setErrors((x) => ({ ...x, [field]: undefined }));
    if (status.state !== 'saving') setStatus({ state: 'idle', message: null });
  };

  const onSubmit = async (event) => {
    event.preventDefault();
    const found = {};
    if (!values.current) found.current = 'Enter your current password.';
    if (values.next.length < MIN) found.next = `Use at least ${MIN} characters.`;
    else if (user && values.next.toLowerCase().includes(user.username)) found.next = 'Don’t include your username.';
    if (values.confirm !== values.next) found.confirm = 'The passwords don’t match.';
    setErrors(found);
    const first = ['current', 'next', 'confirm'].find((f) => found[f]);
    if (first) {
      refs[first].current?.focus();
      return;
    }

    setStatus({ state: 'saving', message: null });
    try {
      const payload = await fetchJson('/api/auth/password', {
        method: 'POST',
        body: { current_password: values.current, new_password: values.next },
      });
      accept(payload);
      setValues({ current: '', next: '', confirm: '' });
      setStatus({ state: 'saved', message: 'Password changed. Other devices were signed out.' });
    } catch (error) {
      if (error.kind === 'validation' && error.fields) {
        setErrors({ current: error.fields.current_password, next: error.fields.new_password });
        setStatus({ state: 'idle', message: null });
      } else {
        setStatus({ state: 'error', message: error.message });
      }
    }
  };

  const field = (name, label, autoComplete, hint) => (
    <div className="field">
      <label htmlFor={`pw-${name}`}>{label}</label>
      <input
        ref={refs[name]}
        id={`pw-${name}`}
        type="password"
        autoComplete={autoComplete}
        value={values[name]}
        onChange={update(name)}
        aria-invalid={Boolean(errors[name])}
        aria-describedby={[hint ? `pw-${name}-hint` : null, errors[name] ? `pw-${name}-error` : null].filter(Boolean).join(' ') || undefined}
        required
      />
      {hint && (
        <p className="field__hint" id={`pw-${name}-hint`}>
          {hint}
        </p>
      )}
      {errors[name] && (
        <p className="field__error" id={`pw-${name}-error`}>
          {errors[name]}
        </p>
      )}
    </div>
  );

  return (
    <div className="page">
      <PageHeader title="Account" subtitle={user ? `Signed in as ${user.username} (${user.role})` : null} />
      <Panel title="Change password" className="account-panel">
        <form className="form-stack" onSubmit={onSubmit} noValidate>
          {field('current', 'Current password', 'current-password')}
          {field('next', 'New password', 'new-password', `At least ${MIN} characters. A passphrase is easiest to remember.`)}
          {field('confirm', 'Repeat new password', 'new-password')}
          <div aria-live="polite">
            {status.state === 'saved' && <p className="notice notice--success">{status.message}</p>}
            {status.state === 'error' && (
              <p className="signin__error" role="alert">
                {status.message}
              </p>
            )}
          </div>
          <div>
            <button type="submit" className="button button--primary" disabled={status.state === 'saving'}>
              {status.state === 'saving' ? 'Saving…' : 'Change password'}
            </button>
          </div>
        </form>
      </Panel>
    </div>
  );
}
