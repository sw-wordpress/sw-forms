import { describe, expect, it, vi } from 'vitest';
import { ApiClient, isApiError, normaliseError } from '../src/api/client';
import type { ApiError } from '../src/api/client';

const client = (fetchImpl = vi.fn<typeof fetch>()) => new ApiClient({ restRoot: 'https://example.test/wp-json/swforms/v1', nonce: 'initial', fetchImpl });

describe('authenticated REST gateway', () => {
  it('encodes query values and supports WordPress without pretty permalinks', () => {
    expect(client().url('/forms', { q: 'a&b', enabled: false, page: 0, empty: '', nil: null, missing: undefined })).toBe('https://example.test/wp-json/swforms/v1/forms?q=a%26b&enabled=false&page=0');
    const plain = new ApiClient({ restRoot: 'https://example.test/?rest_route=/swforms/v1/', nonce: 'n' });
    expect(plain.authenticatedUrl('entries/1', { view: 'file' })).toBe('https://example.test/?rest_route=/swforms/v1/entries/1&view=file&_wpnonce=n');
  });
  it('sends JSON, same-origin credentials and refreshed nonces', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(new Response('{"ok":true}', { headers: { 'X-WP-Nonce': 'refreshed' } })).mockResolvedValueOnce(new Response(null, { status: 204 }));
    const api = client(fetcher);
    expect(await api.post('forms', { title: 'New' })).toEqual({ ok: true });
    expect(fetcher.mock.calls[0]?.[1]).toMatchObject({ method: 'POST', credentials: 'same-origin', body: '{"title":"New"}', headers: { 'X-WP-Nonce': 'initial', 'Content-Type': 'application/json' } });
    expect(await api.delete('entries/1')).toBeNull();
    expect(fetcher.mock.calls[1]?.[1]?.headers).toMatchObject({ 'X-WP-Nonce': 'refreshed' });
  });
  it('preserves field errors and WordPress status details', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({ code: 'invalid', message: 'Fix fields', data: { status: 422, params: { name: 'required', rows: ['one'] }, errors: [{ path: 'id' }] } }), { status: 400 }));
    await expect(client(fetcher).put('forms/1', {})).rejects.toMatchObject({ code: 'invalid', status: 422, params: { name: 'required', rows: '["one"]' }, data: { errors: [{ path: 'id' }] } });
  });
  it('distinguishes malformed successful JSON from HTTP failures', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValueOnce(new Response('not JSON')).mockResolvedValueOnce(new Response('<html>bad gateway</html>', { status: 502 }));
    const api = client(fetcher);
    await expect(api.get('forms')).rejects.toMatchObject({ code: 'swf_invalid_response', status: 200 });
    await expect(api.get('forms')).rejects.toMatchObject({ code: 'swf_http_502', status: 502 });
  });
  it('distinguishes cancellation from network failures and preserves abort signals', async () => {
    const fetcher = vi.fn<typeof fetch>().mockRejectedValueOnce(new DOMException('cancelled', 'AbortError')).mockRejectedValueOnce(new TypeError('offline'));
    const api = client(fetcher);
    const controller = new AbortController();
    const error = await api.get('forms', undefined, controller.signal).catch((failure: unknown) => failure);
    expect(isApiError(error)).toBe(true);
    expect((error as ApiError).isAborted).toBe(true);
    expect(fetcher.mock.calls[0]?.[1]?.signal).toBe(controller.signal);
    await expect(api.get('forms')).rejects.toMatchObject({ code: 'swf_network' });
    expect(isApiError(new Error('other'))).toBe(false);
  });
  it('handles a WordPress error without optional data', () => {
    expect(normaliseError(403, { code: 'forbidden', message: 'Denied' })).toMatchObject({ code: 'forbidden', status: 403, params: {}, data: {} });
    expect(normaliseError(500, null)).toMatchObject({ code: 'swf_http_500' });
  });
});
