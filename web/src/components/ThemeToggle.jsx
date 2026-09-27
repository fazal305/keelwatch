import { useState } from 'react';
import { Icon } from './Icon.jsx';

const STORAGE_KEY = 'keelwatch.theme';

function currentTheme() {
  const explicit = document.documentElement.dataset.theme;
  if (explicit === 'light' || explicit === 'dark') return explicit;
  return window.matchMedia?.('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
}

export function ThemeToggle({ showLabel = false }) {
  const [theme, setTheme] = useState(currentTheme);
  const next = theme === 'dark' ? 'light' : 'dark';

  const toggle = () => {
    document.documentElement.dataset.theme = next;
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Storage can be unavailable (private mode); the choice still applies
      // for this page view.
    }
    setTheme(next);
  };

  return (
    <button
      type="button"
      className={showLabel ? 'button theme-toggle' : 'icon-button'}
      onClick={toggle}
      aria-label={`Switch to ${next} theme`}
      title={`Switch to ${next} theme`}
    >
      <Icon name={theme === 'dark' ? 'sun' : 'moon'} />
      {showLabel && <span>{next === 'light' ? 'Light theme' : 'Dark theme'}</span>}
    </button>
  );
}
