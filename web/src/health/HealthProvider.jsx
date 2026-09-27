import { useCallback, useEffect, useMemo, useReducer, useRef } from 'react';
import { fetchJson } from '../lib/api.js';
import { HealthContext, healthReducer, initialHealthState } from './HealthContext.js';

/**
 * Single poller for /api/system/health shared by the status bar and the
 * System Health page. Polls only while the tab is visible and the browser
 * is online, never overlaps requests, and records every failure in state.
 */
export function HealthProvider({
  children,
  intervalMs = 10_000,
  timeoutMs = 8_000,
  slowAfterMs = 3_000,
}) {
  const [state, dispatch] = useReducer(healthReducer, undefined, initialHealthState);
  const controllerRef = useRef(null);
  const pollTimerRef = useRef(null);
  const refreshRef = useRef(null);

  const schedule = useCallback(() => {
    clearTimeout(pollTimerRef.current);
    if (document.visibilityState === 'hidden' || navigator.onLine === false) return;
    pollTimerRef.current = setTimeout(() => refreshRef.current?.(), intervalMs);
  }, [intervalMs]);

  const refresh = useCallback(async () => {
    controllerRef.current?.abort();
    clearTimeout(pollTimerRef.current);

    const controller = new AbortController();
    controllerRef.current = controller;
    dispatch({ type: 'start' });
    const slowTimer = setTimeout(() => dispatch({ type: 'slow' }), slowAfterMs);

    try {
      const report = await fetchJson('/api/system/health', {
        signal: controller.signal,
        timeoutMs,
      });
      dispatch({ type: 'success', report, at: Date.now() });
    } catch (error) {
      if (error?.kind === 'aborted') {
        // Superseded by a newer request or by unmount; nothing to report.
        dispatch({ type: 'cancelled' });
        return;
      }
      dispatch({
        type: 'failure',
        at: Date.now(),
        error: {
          kind: error?.kind ?? 'unexpected',
          message: error?.message ?? 'Something unexpected went wrong.',
          status: error?.status ?? null,
          correlationId: error?.correlationId ?? null,
        },
      });
    } finally {
      clearTimeout(slowTimer);
    }

    if (controllerRef.current === controller) schedule();
  }, [schedule, slowAfterMs, timeoutMs]);

  useEffect(() => {
    refreshRef.current = refresh;
  }, [refresh]);

  useEffect(() => {
    const start = setTimeout(() => refreshRef.current?.(), 0);
    return () => {
      clearTimeout(start);
      clearTimeout(pollTimerRef.current);
      controllerRef.current?.abort();
    };
  }, []);

  useEffect(() => {
    const onOffline = () => {
      clearTimeout(pollTimerRef.current);
      controllerRef.current?.abort();
      dispatch({ type: 'offline' });
    };
    const onOnline = () => {
      dispatch({ type: 'online' });
      refreshRef.current?.();
    };
    const onVisibility = () => {
      if (document.visibilityState === 'visible') refreshRef.current?.();
      else clearTimeout(pollTimerRef.current);
    };
    window.addEventListener('offline', onOffline);
    window.addEventListener('online', onOnline);
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      window.removeEventListener('offline', onOffline);
      window.removeEventListener('online', onOnline);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, []);

  const value = useMemo(() => ({ ...state, refresh, intervalMs }), [state, refresh, intervalMs]);

  return <HealthContext.Provider value={value}>{children}</HealthContext.Provider>;
}
