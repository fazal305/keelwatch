import { useEffect } from 'react';
import { Link, useLocation } from 'react-router';

export function NotFound() {
  const { pathname } = useLocation();

  useEffect(() => {
    document.title = 'Page not found · Keelwatch';
  }, []);

  return (
    <div className="page not-found">
      <p className="label">404</p>
      <h1>This page doesn't exist</h1>
      <p className="not-found__path">
        Nothing is served at <code>{pathname}</code>.
      </p>
      <p>
        <Link to="/">Go to the overview</Link>
      </p>
    </div>
  );
}
