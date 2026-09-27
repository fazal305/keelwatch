import { createContext, useContext } from 'react';

export const HealthContext = createContext(null);

export function useHealth() {
  const value = useContext(HealthContext);
  if (value === null) {
    throw new Error('useHealth must be used inside <HealthProvider>');
  }
  return value;
}

export function initialHealthState() {
  return {
    phase: 'loading', // loading | ready | error
    report: null,
    error: null,
    lastUpdated: null,
    lastAttempt: null,
    fetching: false,
    slow: false,
    online: typeof navigator === 'undefined' ? true : navigator.onLine !== false,
  };
}

export function healthReducer(state, action) {
  switch (action.type) {
    case 'start':
      return { ...state, fetching: true, slow: false };
    case 'slow':
      return state.fetching ? { ...state, slow: true } : state;
    case 'success':
      return {
        ...state,
        phase: 'ready',
        report: action.report,
        error: null,
        lastUpdated: action.at,
        lastAttempt: action.at,
        fetching: false,
        slow: false,
      };
    case 'failure':
      // Keep the last good report on screen (marked stale) rather than
      // blanking the page because one poll failed.
      return {
        ...state,
        phase: state.report ? 'ready' : 'error',
        error: action.error,
        lastAttempt: action.at,
        fetching: false,
        slow: false,
      };
    case 'cancelled':
      return { ...state, fetching: false, slow: false };
    case 'offline':
      return { ...state, online: false, fetching: false, slow: false };
    case 'online':
      return { ...state, online: true };
    default:
      return state;
  }
}
