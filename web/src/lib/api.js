/**
 * Thin fetch wrapper that turns every failure into an ApiError with a
 * `kind` the UI can act on. Callers never see raw fetch/JSON exceptions.
 *
 * kinds: offline | timeout | network | unreachable | http | parse | aborted
 */
export class ApiError extends Error {
  constructor(kind, message, { status = null, correlationId = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.kind = kind;
    this.status = status;
    this.correlationId = correlationId;
  }
}

const GATEWAY_STATUSES = new Set([500, 502, 503, 504]);

function isOffline() {
  return typeof navigator !== 'undefined' && navigator.onLine === false;
}

export async function fetchJson(path, { signal, timeoutMs = 8000 } = {}) {
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

  let response;
  try {
    response = await fetch(path, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    });
  } catch {
    if (timedOut) {
      throw new ApiError('timeout', `The API did not respond within ${timeoutMs / 1000} seconds.`);
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
  let text;
  try {
    text = await response.text();
  } catch {
    throw new ApiError('network', 'The connection dropped while reading the response.', {
      status: response.status,
      correlationId: headerCorrelationId,
    });
  }

  let body = null;
  if (text) {
    try {
      body = JSON.parse(text);
    } catch {
      body = null;
    }
  }

  if (!response.ok) {
    if (body === null && GATEWAY_STATUSES.has(response.status)) {
      // A proxy or gateway answered because the API process itself did not.
      throw new ApiError('unreachable', 'The Keelwatch API is not reachable.', {
        status: response.status,
        correlationId: headerCorrelationId,
      });
    }
    throw new ApiError(
      'http',
      body?.error?.message || `The API responded with HTTP ${response.status}.`,
      {
        status: response.status,
        correlationId: body?.error?.correlation_id || headerCorrelationId,
      },
    );
  }

  if (body === null) {
    throw new ApiError('parse', 'The API returned a response that could not be read.', {
      status: response.status,
      correlationId: headerCorrelationId,
    });
  }

  return body;
}
