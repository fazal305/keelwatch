import { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { useAuth } from '../auth/AuthContext.js';

function Brand() {
  return (
    <span className="brand signin__brand">
      <svg className="brand__mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path d="M6 12h20l-4.5 7.5H10.5z" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
        <path d="M16 19.5V26" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" />
        <circle cx="16" cy="7" r="2" className="brand__dot" />
      </svg>
      <span className="brand__name">Keelwatch</span>
    </span>
  );
}

export function SignIn() {
  const { status, signIn } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = location.state?.from && location.state.from !== '/signin' ? location.state.from : '/';

  const [values, setValues] = useState({ username: '', password: '' });
  const [fieldErrors, setFieldErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const usernameRef = useRef(null);
  const passwordRef = useRef(null);

  useEffect(() => {
    document.title = 'Sign in · Keelwatch';
  }, []);

  const update = (field) => (event) => {
    setValues((v) => ({ ...v, [field]: event.target.value }));
    setFieldErrors((e) => ({ ...e, [field]: undefined }));
  };

  const onSubmit = async (event) => {
    event.preventDefault();
    const errors = {};
    if (!values.username.trim()) errors.username = 'Enter your username.';
    if (!values.password) errors.password = 'Enter your password.';
    setFieldErrors(errors);
    setFormError(null);
    if (errors.username) {
      usernameRef.current?.focus();
      return;
    }
    if (errors.password) {
      passwordRef.current?.focus();
      return;
    }

    setSubmitting(true);
    try {
      await signIn(values.username.trim(), values.password);
      navigate(from, { replace: true });
    } catch (error) {
      setSubmitting(false);
      setValues((v) => ({ ...v, password: '' }));
      if (error.kind === 'validation' && error.fields) {
        setFieldErrors(error.fields);
      } else if (error.status === 429) {
        setFormError('Too many sign-in attempts. Wait a few minutes and try again.');
      } else if (error.kind === 'unauthenticated') {
        setFormError('Incorrect username or password.');
      } else {
        setFormError(error.message || 'Sign-in failed. Try again.');
      }
      passwordRef.current?.focus();
    }
  };

  return (
    <main className="signin" id="main">
      <div className="signin__card">
        <Brand />
        <h1>Sign in</h1>
        {status === 'expired' && (
          <p className="notice notice--warning" role="status">
            Your session expired. Sign in again to continue where you left off.
          </p>
        )}
        {formError && (
          <p className="signin__error" role="alert">
            {formError}
          </p>
        )}
        <form onSubmit={onSubmit} noValidate>
          <div className="field">
            <label htmlFor="username">Username</label>
            <input
              ref={usernameRef}
              id="username"
              name="username"
              autoComplete="username"
              autoCapitalize="none"
              spellCheck="false"
              value={values.username}
              onChange={update('username')}
              aria-invalid={Boolean(fieldErrors.username)}
              aria-describedby={fieldErrors.username ? 'username-error' : undefined}
              required
            />
            {fieldErrors.username && (
              <p className="field__error" id="username-error">
                {fieldErrors.username}
              </p>
            )}
          </div>
          <div className="field">
            <label htmlFor="password">Password</label>
            <input
              ref={passwordRef}
              id="password"
              name="password"
              type="password"
              autoComplete="current-password"
              value={values.password}
              onChange={update('password')}
              aria-invalid={Boolean(fieldErrors.password)}
              aria-describedby={fieldErrors.password ? 'password-error' : undefined}
              required
            />
            {fieldErrors.password && (
              <p className="field__error" id="password-error">
                {fieldErrors.password}
              </p>
            )}
          </div>
          <button type="submit" className="button button--primary" disabled={submitting}>
            {submitting ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
        <p className="signin__hint">Accounts are created by an administrator.</p>
      </div>
    </main>
  );
}
