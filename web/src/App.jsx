import { Navigate, Route, Routes } from 'react-router';
import { AppShell } from './components/AppShell.jsx';
import { HealthProvider } from './health/HealthProvider.jsx';
import { NotFound } from './pages/NotFound.jsx';
import { SystemHealth } from './pages/SystemHealth.jsx';

export function App() {
  return (
    <HealthProvider>
      <Routes>
        <Route element={<AppShell />}>
          <Route index element={<Navigate to="/system" replace />} />
          <Route path="system" element={<SystemHealth />} />
          <Route path="*" element={<NotFound />} />
        </Route>
      </Routes>
    </HealthProvider>
  );
}
