/**
 * Thin fetch wrapper that turns every failure into an ApiError with a
 * `kind` the UI can act on. Callers never see raw fetch/JSON exceptions.
 *
 * kinds: offline | timeout | network | unreachable | http | parse | aborted
 *        | unauthenticated (401) | forbidden (403) | validation (422)
 */
export class ApiError extends Error {
  constructor(kind, message, { status = null, correlationId = null, fields = null, code = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.kind = kind;
    this.status = status;
    this.correlationId = correlationId;
    this.fields = fields;
    this.code = code;
  }
}

const GATEWAY_STATUSES = new Set([500, 502, 503, 504]);

// The CSRF token for state-changing requests, set by the auth layer after
// sign-in. Kept in memory only (never localStorage).
let csrfToken = null;
export function setCsrfToken(token) {
  csrfToken = token;
}

// Notified when the API says the session is gone, so the whole app can show
// "session expired" instead of each page failing on its own.
const unauthenticatedListeners = new Set();
export function onUnauthenticated(listener) {
  unauthenticatedListeners.add(listener);
  return () => unauthenticatedListeners.delete(listener);
}

function isOffline() {
  return typeof navigator !== 'undefined' && navigator.onLine === false;
}

export async function fetchJson(path, { signal, timeoutMs = 8000, method = 'GET', body, quietAuth = false } = {}) {
  if (isOffline()) {
    throw new ApiError('offline', 'You appear to be offline.');
  }

  const controller = new AbortController();
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, timeoutMs);
  const forwardAbort = () => controller.abort();
  signal?.addEventListener('abort', forwardAbort, { once: true });

  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && csrfToken) headers['X-CSRF-Token'] = csrfToken;

  let response;
  try {
    response = await fetch(path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      signal: controller.signal,
    });
  } catch {
    if (timedOut) {
      // A write that timed out may still have been applied: say so, so nobody
      // blindly retries into a duplicate.
      throw new ApiError(
        'timeout',
        method === 'GET'
          ? `The API did not respond within ${timeoutMs / 1000} seconds.`
          : `The API did not respond within ${timeoutMs / 1000} seconds. The change may still have been saved; reload the page to check before trying again.`,
      );
    }
    if (signal?.aborted) {
      throw new ApiError('aborted', 'The request was cancelled.');
    }
    if (isOffline()) {
      throw new ApiError('offline', 'You appear to be offline.');
    }
    throw new ApiError('network', 'Could not connect to the Keelwatch API.');
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener('abort', forwardAbort);
  }

  const headerCorrelationId = response.headers.get('X-Request-Id');
  if (response.status === 204) return null;

  let text;
  try {
    text = await response.text();
  } catch {
    throw new ApiError('network', 'The connection dropped while reading the response.', {
      status: response.status,
      correlationId: headerCorrelationId,
    });
  }

  let payload = null;
  if (text) {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
  }

  if (!response.ok) {
    const error = payload?.error ?? {};
    const meta = {
      status: response.status,
      correlationId: error.correlation_id || headerCorrelationId,
      fields: error.fields ?? null,
      code: error.code ?? null,
    };
    if (response.status === 401) {
      if (!quietAuth) unauthenticatedListeners.forEach((listener) => listener());
      throw new ApiError('unauthenticated', error.message || 'Please sign in.', meta);
    }
    if (response.status === 403) {
      throw new ApiError('forbidden', error.message || 'You do not have permission to do this.', meta);
    }
    if (response.status === 422) {
      throw new ApiError('validation', error.message || 'Some fields need attention.', meta);
    }
    if (payload === null && GATEWAY_STATUSES.has(response.status)) {
      // A proxy or gateway answered because the API process itself did not.
      throw new ApiError('unreachable', 'The Keelwatch API is not reachable.', meta);
    }
    throw new ApiError('http', error.message || `The API responded with HTTP ${response.status}.`, meta);
  }

  if (payload === null) {
    throw new ApiError('parse', 'The API returned a response that could not be read.', {
      status: response.status,
      correlationId: headerCorrelationId,
    });
  }

  return payload;
}

/** Builds "/path?a=1&b=2", dropping empty values. */
export function withQuery(path, params = {}) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') query.set(key, String(value));
  }
  const qs = query.toString();
  return qs ? `${path}?${qs}` : path;
}
