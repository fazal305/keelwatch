import { describe, expect, it, vi } from 'vitest';
import { ApiError, fetchJson } from './api.js';

function respond(status, body, headers = {}) {
  return vi.fn().mockResolvedValue(
    new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers }),
  );
}

async function failure(promise) {
  try {
    await promise;
  } catch (error) {
    return error;
  }
  throw new Error('expected rejection');
}

describe('fetchJson', () => {
  it('returns parsed JSON on success', async () => {
    vi.stubGlobal('fetch', respond(200, { status: 'ok' }));
    await expect(fetchJson('/x')).resolves.toEqual({ status: 'ok' });
  });

  it('classifies an API error body and keeps its correlation id', async () => {
    vi.stubGlobal(
      'fetch',
      respond(500, { error: { code: 'internal_error', message: 'Boom', correlation_id: 'abc12345' } }),
    );
    const error = await failure(fetchJson('/x'));
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ kind: 'http', status: 500, message: 'Boom', correlationId: 'abc12345' });
  });

  it('treats an empty gateway error as the API being unreachable', async () => {
    vi.stubGlobal('fetch', respond(502, '', { 'X-Request-Id': 'proxy-1234' }));
    const error = await failure(fetchJson('/x'));
    expect(error).toMatchObject({ kind: 'unreachable', status: 502, correlationId: 'proxy-1234' });
  });

  it('reports network failures', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));
    expect(await failure(fetchJson('/x'))).toMatchObject({ kind: 'network' });
  });

  it('reports unreadable success bodies', async () => {
    vi.stubGlobal('fetch', respond(200, '<html>not json</html>'));
    expect(await failure(fetchJson('/x'))).toMatchObject({ kind: 'parse' });
  });

  it('times out slow requests', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        (_url, { signal }) =>
          new Promise((_resolve, reject) => {
            signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
          }),
      ),
    );
    expect(await failure(fetchJson('/x', { timeoutMs: 20 }))).toMatchObject({ kind: 'timeout' });
  });

  it('warns that a timed-out write may still have been applied', async () => {
    const hang = vi.fn(
      (_url, { signal }) =>
        new Promise((_resolve, reject) => {
          signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
        }),
    );
    vi.stubGlobal('fetch', hang);
    const read = await failure(fetchJson('/x', { timeoutMs: 20 }));
    const write = await failure(fetchJson('/x', { method: 'POST', body: {}, timeoutMs: 20 }));
    expect(read.message).not.toMatch(/may still have been saved/);
    expect(write).toMatchObject({ kind: 'timeout' });
    expect(write.message).toMatch(/may still have been saved; reload the page to check/);
  });

  it('distinguishes caller cancellation from timeouts', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        (_url, { signal }) =>
          new Promise((_resolve, reject) => {
            signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
          }),
      ),
    );
    const controller = new AbortController();
    const pending = fetchJson('/x', { signal: controller.signal, timeoutMs: 5000 });
    controller.abort();
    expect(await failure(pending)).toMatchObject({ kind: 'aborted' });
  });

  it('fails fast when the browser is offline', async () => {
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
    const fetchSpy = vi.fn();
    vi.stubGlobal('fetch', fetchSpy);
    expect(await failure(fetchJson('/x'))).toMatchObject({ kind: 'offline' });
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
