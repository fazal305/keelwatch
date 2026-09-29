import { useEffect, useState } from 'react';

const TOKENS = {
  series1: '--chart-series-1',
  series2: '--chart-series-2',
  rest: '--chart-rest',
  critical: '--danger',
  surface: '--surface',
  grid: '--border',
  axis: '--text-muted',
  text: '--text-primary',
  textSecondary: '--text-secondary',
  tooltip: '--surface-elevated',
  tooltipBorder: '--border-strong',
};

function read() {
  const style = getComputedStyle(document.documentElement);
  return Object.fromEntries(Object.entries(TOKENS).map(([k, v]) => [k, style.getPropertyValue(v).trim()]));
}

/**
 * Chart colours come from the same CSS tokens as the rest of the UI (canvas
 * can't read CSS variables itself), re-read whenever the theme changes: the
 * user's toggle (data-theme on <html>) or the OS preference.
 */
export function useChartTheme() {
  const [theme, setTheme] = useState(read);
  useEffect(() => {
    const update = () => setTheme(read());
    const observer = new MutationObserver(update);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
    const media = window.matchMedia?.('(prefers-color-scheme: light)');
    media?.addEventListener?.('change', update);
    return () => {
      observer.disconnect();
      media?.removeEventListener?.('change', update);
    };
  }, []);
  return theme;
}
