import { useCallback, useEffect, useRef, useState } from 'react';
import { fetchJson, withQuery } from './api.js';

/**
 * Cursor-paginated list ({items, next_before}) with "load more".
 * Changing `params` starts over from the first page.
 */
export function usePaged(path, params) {
  const key = JSON.stringify(params);
  const [state, setState] = useState({ items: null, next: null, error: null, loading: true, slow: false });
  const controllerRef = useRef(null);

  const fetchPage = useCallback(
    async (before) => {
      controllerRef.current?.abort();
      const controller = new AbortController();
      controllerRef.current = controller;
      setState((s) => ({ ...s, loading: true, error: null, slow: false }));
      const slowTimer = setTimeout(() => setState((s) => (s.loading ? { ...s, slow: true } : s)), 5000);
      try {
        const data = await fetchJson(withQuery(path, { ...JSON.parse(key), before }), {
          signal: controller.signal,
          timeoutMs: 15000,
        });
        if (controllerRef.current !== controller) return;
        setState((s) => ({
          items: before ? [...(s.items ?? []), ...data.items] : data.items,
          next: data.next_before,
          error: null,
          loading: false,
          slow: false,
        }));
      } catch (error) {
        if (error?.kind === 'aborted' || controllerRef.current !== controller) return;
        setState((s) => ({ ...s, error, loading: false, slow: false }));
      } finally {
        clearTimeout(slowTimer);
      }
    },
    [path, key],
  );

  useEffect(() => {
    const id = setTimeout(() => {
      setState({ items: null, next: null, error: null, loading: true, slow: false });
      fetchPage(undefined);
    }, 0);
    return () => {
      clearTimeout(id);
      controllerRef.current?.abort();
    };
  }, [fetchPage]);

  return {
    data: state.items,
    error: state.error,
    loading: state.loading,
    slow: state.slow,
    hasMore: state.next !== null,
    loadMore: () => fetchPage(state.next),
    reload: () => fetchPage(undefined),
  };
}
