import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchJson } from './api.js';
import { useReconnect } from './useReconnect.js';

/**
 * Loads one API resource with the states every page needs:
 * loading, slow (after `slowAfterMs`), error (kept separate from data so a
 * failed refresh doesn't blank the page), and data. Re-fetches when `path`
 * changes; stale responses from an earlier path are ignored.
 */
export function useApi(path, { slowAfterMs = 5000, enabled = true } = {}) {
  const [state, setState] = useState({ data: null, error: null, loading: enabled, slow: false });
  const controllerRef = useRef(null);

  const load = useCallback(async () => {
    if (!enabled || !path) return;
    controllerRef.current?.abort();
    const controller = new AbortController();
    controllerRef.current = controller;
    setState((s) => ({ ...s, loading: true, error: null, slow: false }));
    const slowTimer = setTimeout(() => setState((s) => (s.loading ? { ...s, slow: true } : s)), slowAfterMs);
    try {
      const data = await fetchJson(path, { signal: controller.signal, timeoutMs: 15000 });
      if (controllerRef.current === controller) {
        setState({ data, error: null, loading: false, slow: false });
      }
    } catch (error) {
      if (error?.kind === 'aborted' || controllerRef.current !== controller) return;
      setState((s) => ({ ...s, error, loading: false, slow: false }));
    } finally {
      clearTimeout(slowTimer);
    }
  }, [path, enabled, slowAfterMs]);

  useEffect(() => {
    const id = setTimeout(load, 0);
    return () => {
      clearTimeout(id);
      controllerRef.current?.abort();
    };
  }, [load]);

  useReconnect(state.error, load);

  return { ...state, reload: load };
}
