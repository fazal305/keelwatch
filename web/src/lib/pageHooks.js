import { useEffect } from 'react';
import { useSearchParams } from 'react-router';

export function usePageTitle(title) {
  useEffect(() => {
    document.title = `${title} · Keelwatch`;
  }, [title]);
}

/** Filters live in the URL so every filtered view is linkable. */
export function useFilters(keys) {
  const [params, setParams] = useSearchParams();
  const values = Object.fromEntries(keys.map((k) => [k, params.get(k) ?? '']));
  const set = (key, value) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };
  const clear = () => setParams(new URLSearchParams(), { replace: true });
  const active = keys.some((k) => values[k]);
  return { values, set, clear, active };
}
