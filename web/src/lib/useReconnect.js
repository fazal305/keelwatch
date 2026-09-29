import { useEffect, useRef } from 'react';

/** Failures that a restored connection can fix; a 4xx/5xx answer can't. */
export const CONNECTION_KINDS = new Set(['offline', 'network', 'timeout', 'unreachable']);

/**
 * When the browser comes back online, re-run `reload` if the last attempt
 * failed for connection reasons, so pages recover without a manual retry.
 */
export function useReconnect(error, reload) {
  const latest = useRef({ error, reload });
  useEffect(() => {
    latest.current = { error, reload };
  });
  useEffect(() => {
    const onOnline = () => {
      const { error: e, reload: r } = latest.current;
      if (e && CONNECTION_KINDS.has(e.kind)) r();
    };
    window.addEventListener('online', onOnline);
    return () => window.removeEventListener('online', onOnline);
  }, []);
}
