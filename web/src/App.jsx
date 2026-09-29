import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router';
import { AuthProvider } from './auth/AuthProvider.jsx';
import { useAuth } from './auth/AuthContext.js';
import { AppShell } from './components/AppShell.jsx';
import { InlineError } from './components/Feedback.jsx';
import { HealthProvider } from './health/HealthProvider.jsx';
import { Account } from './pages/Account.jsx';
import { DigestDetail, Digests } from './pages/Digests.jsx';
import { Events } from './pages/Events.jsx';
import { FindingDetail, Findings } from './pages/Findings.jsx';
import { Integrations } from './pages/Integrations.jsx';
import { NotFound } from './pages/NotFound.jsx';
import { Overview } from './pages/Overview.jsx';
import { Readiness, ReadinessDetail } from './pages/Readiness.jsx';
import { Repositories, RepositoryDetail } from './pages/Repositories.jsx';
import { RunDetail, Runs } from './pages/Runs.jsx';
import { SignIn } from './pages/SignIn.jsx';
import { SystemHealth } from './pages/SystemHealth.jsx';

// Chart.js is only needed here, so it loads with this page instead of the app shell.
const Analytics = lazy(() => import('./pages/Analytics.jsx').then((m) => ({ default: m.Analytics })));

/**
 * Gate for everything except /signin. While the session check is in flight
 * nothing protected renders; a signed-out or expired user is sent to sign in
 * with a note of where they were going.
 */
function RequireAuth({ children }) {
  const { status, error, recheck } = useAuth();
  const location = useLocation();

  if (status === 'checking') {
    return (
      <main className="signin" id="main" aria-busy="true">
        <p className="muted">Checking your session…</p>
      </main>
    );
  }
  if (status === 'unavailable') {
    return (
      <main className="signin" id="main">
        <div className="signin__card">
          <InlineError
            title="Keelwatch is not reachable"
            message={error?.message ?? 'The API did not answer.'}
            correlationId={error?.correlationId}
            onRetry={recheck}
          />
        </div>
      </main>
    );
  }
  if (status !== 'signed-in') {
    return <Navigate to="/signin" replace state={{ from: location.pathname + location.search }} />;
  }
  return children;
}

function SignInRoute() {
  const { status } = useAuth();
  const location = useLocation();
  if (status === 'signed-in') {
    return <Navigate to={location.state?.from ?? '/'} replace />;
  }
  return <SignIn />;
}

export function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="signin" element={<SignInRoute />} />
        <Route
          element={
            <RequireAuth>
              <HealthProvider>
                <AppShell />
              </HealthProvider>
            </RequireAuth>
          }
        >
          <Route index element={<Overview />} />
          <Route path="repositories" element={<Repositories />} />
          <Route path="repositories/:id" element={<RepositoryDetail />} />
          <Route path="runs" element={<Runs />} />
          <Route path="runs/:id" element={<RunDetail />} />
          <Route path="findings" element={<Findings />} />
          <Route path="findings/:id" element={<FindingDetail />} />
          <Route path="events" element={<Events />} />
          <Route path="digests" element={<Digests />} />
          <Route path="digests/:id" element={<DigestDetail />} />
          <Route
            path="analytics"
            element={
              <Suspense fallback={<p className="muted" role="status">Loading analytics…</p>}>
                <Analytics />
              </Suspense>
            }
          />
          <Route path="readiness" element={<Readiness />} />
          <Route path="readiness/:id" element={<ReadinessDetail />} />
          <Route path="integrations" element={<Integrations />} />
          <Route path="system" element={<SystemHealth />} />
          <Route path="account" element={<Account />} />
          <Route path="*" element={<NotFound />} />
        </Route>
      </Routes>
    </AuthProvider>
  );
}
