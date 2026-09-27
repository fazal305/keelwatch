import { useCallback, useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router';
import { Icon } from './Icon.jsx';
import { StatusBar } from './StatusBar.jsx';
import { ThemeToggle } from './ThemeToggle.jsx';

// Only pages that exist are listed. The nav grows as phases ship.
const NAV_ITEMS = [{ to: '/system', label: 'System health', icon: 'pulse' }];

const MOBILE_QUERY = '(max-width: 767.98px)';

function useMediaQuery(query) {
  const [matches, setMatches] = useState(() => window.matchMedia(query).matches);
  useEffect(() => {
    const mql = window.matchMedia(query);
    const onChange = () => setMatches(mql.matches);
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
  }, [query]);
  return matches;
}

function Brand() {
  return (
    <span className="brand">
      <svg className="brand__mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
        <path d="M6 12h20l-4.5 7.5H10.5z" fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" />
        <path d="M16 19.5V26" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" />
        <circle cx="16" cy="7" r="2" className="brand__dot" />
      </svg>
      <span className="brand__name">Keelwatch</span>
    </span>
  );
}

export function AppShell() {
  const isMobile = useMediaQuery(MOBILE_QUERY);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const menuButtonRef = useRef(null);
  const navRef = useRef(null);
  const location = useLocation();

  const closeDrawer = useCallback((restoreFocus = true) => {
    setDrawerOpen(false);
    if (restoreFocus) menuButtonRef.current?.focus();
  }, []);

  // Close the drawer after navigating.
  const lastPath = useRef(location.pathname);
  useEffect(() => {
    if (lastPath.current === location.pathname) return;
    lastPath.current = location.pathname;
    const id = setTimeout(() => setDrawerOpen(false), 0);
    return () => clearTimeout(id);
  }, [location.pathname]);

  useEffect(() => {
    if (!drawerOpen) return;
    // Land on the first destination, not the drawer's Close button.
    navRef.current?.querySelector('.rail__link')?.focus();
    const onKey = (event) => {
      if (event.key === 'Escape') closeDrawer();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [drawerOpen, closeDrawer]);

  const navHidden = isMobile && !drawerOpen;

  return (
    <div className="shell" data-drawer-open={drawerOpen || undefined}>
      <a className="skip-link" href="#main">
        Skip to content
      </a>

      <header className="topbar">
        <button
          ref={menuButtonRef}
          type="button"
          className="icon-button"
          aria-label="Open navigation"
          aria-expanded={drawerOpen}
          aria-controls="primary-nav"
          onClick={() => setDrawerOpen(true)}
        >
          <Icon name="menu" />
        </button>
        <Brand />
      </header>

      {isMobile && drawerOpen && (
        <div className="scrim" aria-hidden="true" onClick={() => closeDrawer()} />
      )}

      <nav
        id="primary-nav"
        ref={navRef}
        className="rail"
        aria-label="Primary"
        inert={navHidden}
        aria-hidden={navHidden || undefined}
      >
        <div className="rail__head">
          <Brand />
          {isMobile && (
            <button type="button" className="icon-button" aria-label="Close navigation" onClick={() => closeDrawer()}>
              <Icon name="close" />
            </button>
          )}
        </div>
        <ul className="rail__list">
          {NAV_ITEMS.map((item) => (
            <li key={item.to}>
              <NavLink to={item.to} className="rail__link" title={item.label}>
                <Icon name={item.icon} />
                <span className="rail__label">{item.label}</span>
              </NavLink>
            </li>
          ))}
        </ul>
        <div className="rail__foot">
          <ThemeToggle showLabel={isMobile} />
        </div>
      </nav>

      <div className="workspace">
        <StatusBar />
        <main id="main" className="main" tabIndex={-1}>
          <Outlet />
        </main>
      </div>
    </div>
  );
}
