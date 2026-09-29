import { useCallback, useEffect, useMemo, useState } from 'react';
import { ApiError, fetchJson, onUnauthenticated, setCsrfToken } from '../lib/api.js';
import { AuthContext } from './AuthContext.js';

/**
 * Sign-in state for the whole app.
 *
 * status: checking | signed-out | signed-in | expired | unavailable
 * "expired" is distinct from "signed-out" so the UI can say why the user
 * has to sign in again instead of silently bouncing them to a blank form.
 */
export function AuthProvider({ children }) {
  const [state, setState] = useState({ status: 'checking', user: null, error: null });

  const accept = useCallback((payload) => {
    setCsrfToken(payload.csrf_token);
    setState({ status: 'signed-in', user: payload.user, error: null });
  }, []);

  const check = useCallback(async () => {
    try {
      accept(await fetchJson('/api/auth/session', { quietAuth: true }));
    } catch (error) {
      setCsrfToken(null);
      if (error instanceof ApiError && error.kind === 'unauthenticated') {
        setState({ status: 'signed-out', user: null, error: null });
      } else {
        setState({ status: 'unavailable', user: null, error });
      }
    }
  }, [accept]);

  useEffect(() => {
    const id = setTimeout(check, 0);
    return () => clearTimeout(id);
  }, [check]);

  useEffect(
    () =>
      onUnauthenticated(() => {
        setCsrfToken(null);
        setState((current) =>
          current.status === 'signed-in' ? { status: 'expired', user: null, error: null } : current,
        );
      }),
    [],
  );

  const signIn = useCallback(
    async (username, password) => {
      const payload = await fetchJson('/api/auth/login', {
        method: 'POST',
        body: { username, password },
        quietAuth: true,
      });
      accept(payload);
    },
    [accept],
  );

  const signOut = useCallback(async () => {
    try {
      await fetchJson('/api/auth/logout', { method: 'POST', quietAuth: true });
    } catch {
      // Signing out locally is what matters; the server session expires anyway.
    }
    setCsrfToken(null);
    setState({ status: 'signed-out', user: null, error: null });
  }, []);

  const value = useMemo(
    () => ({ ...state, isAdmin: state.user?.role === 'admin', signIn, signOut, recheck: check, accept }),
    [state, signIn, signOut, check, accept],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
