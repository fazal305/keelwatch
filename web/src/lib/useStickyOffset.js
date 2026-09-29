import { useEffect } from 'react';

/**
 * Publishes how much of the viewport the sticky bars cover as
 * `--sticky-offset` on <html>, which `scroll-padding-top` uses so a focused
 * field is never scrolled in underneath them (WCAG 2.4.11). Measured rather
 * than hard-coded because the status bar wraps to more lines on narrow screens.
 */
export function useStickyOffset(layoutKey) {
  useEffect(() => {
    const root = document.documentElement;
    // Re-collected whenever the layout changes, since the top bar only exists on mobile.
    const elements = ['.topbar', '.statusbar'].map((s) => document.querySelector(s)).filter(Boolean);

    const measure = () => {
      let offset = 0;
      for (const el of elements) {
        const style = getComputedStyle(el);
        if (style.position !== 'sticky' && style.position !== 'fixed') continue;
        if (style.display === 'none') continue;
        // Where the bar's bottom edge sits once it is stuck.
        offset = Math.max(offset, (parseFloat(style.top) || 0) + el.offsetHeight);
      }
      root.style.setProperty('--sticky-offset', `${Math.ceil(offset)}px`);
    };

    measure();
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure);
    elements.forEach((el) => observer?.observe(el));
    window.addEventListener('resize', measure);
    return () => {
      observer?.disconnect();
      window.removeEventListener('resize', measure);
      root.style.removeProperty('--sticky-offset');
    };
  }, [layoutKey]);
}
