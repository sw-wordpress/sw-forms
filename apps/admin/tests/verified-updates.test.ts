import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const win = window as unknown as Record<string, unknown>;
let request: ReturnType<typeof vi.fn>;
async function load() {
  // @ts-expect-error The deliberately standalone WordPress script has no module API.
  await import('../../../assets/js/sw-forms-updates.js');
}
beforeEach(() => {
  vi.resetModules();
  vi.useFakeTimers();
  document.body.innerHTML = '<button id="swf-verified-updates-check"></button><p id="swf-verified-updates-result" hidden></p><a id="swf-verified-updates-reload" href="/wp-admin/plugins.php" hidden>Reload</a>';
  win.swfVerifiedUpdates = { due: false, nonce: 'test', url: '/admin-ajax.php', checking: 'Checking', completed: 'Done', failed: 'Failed' };
  request = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: { message: 'Verified' } }) });
  vi.stubGlobal('fetch', request);
});
afterEach(() => { vi.useRealTimers(); vi.unstubAllGlobals(); delete win.swfVerifiedUpdates; delete win.requestIdleCallback; });
it('does nothing without the authorized server configuration', async () => {
  delete win.swfVerifiedUpdates;
  await load();
  await vi.runAllTimersAsync();
  expect(request).not.toHaveBeenCalled();
});
it('checks manually once while pending and displays the escaped result', async () => {
  let resolve!: (v: unknown) => void;
  request.mockReturnValue(new Promise(r => { resolve = r; }));
  await load();
  const button = document.querySelector('button')!;
  button.dispatchEvent(new Event('click')); button.dispatchEvent(new Event('click'));
  expect(request).toHaveBeenCalledTimes(1);
  expect(button.disabled).toBe(true);
  expect(document.querySelector('p')!.hidden).toBe(false);
  const options = request.mock.calls[0]![1];
  expect(Object.fromEntries(options.body)).toEqual({ action: 'swf_check_github_updates', nonce: 'test', manual: '1' });
  resolve({ ok: true, json: async () => ({ success: true, data: { message: '<b>Verified</b>' } }) });
  await vi.advanceTimersByTimeAsync(0);
  expect(document.querySelector('p')!.textContent).toBe('<b>Verified</b>');
  expect(document.querySelector('p b')).toBeNull();
  expect(button.disabled).toBe(false);
  expect(document.querySelector('a')!.hidden).toBe(false);
});
it.each(['http', 'network', 'empty'])('handles %s responses and releases the button', async mode => {
  if (mode === 'http') request.mockResolvedValue({ ok: false });
  if (mode === 'network') request.mockRejectedValue(new Error());
  if (mode === 'empty') request.mockResolvedValue({ ok: true, json: async () => ({}) });
  await load(); document.querySelector('button')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(document.querySelector('p')!.textContent).toBe(mode === 'empty' ? 'Done' : 'Failed');
  expect(document.querySelector('button')!.disabled).toBe(false);
});
it.each([false, true])('defers a daily check; idle callback available: %s', async idle => {
  (win.swfVerifiedUpdates as Record<string, unknown>).due = true;
  if (idle) win.requestIdleCallback = (fn: () => void) => window.setTimeout(fn, 1);
  await load(); expect(request).not.toHaveBeenCalled();
  await vi.advanceTimersByTimeAsync(1500);
  expect(request).toHaveBeenCalledTimes(1);
  expect(request.mock.calls[0]![1].body.get('manual')).toBe('0');
  expect(document.querySelector('p')!.hidden).toBe(true);
  expect(document.querySelector('a')!.hidden).toBe(true);
});
it('works on other backend pages without controls and aborts timed out requests', async () => {
  document.body.innerHTML = '';
  (win.swfVerifiedUpdates as Record<string, unknown>).due = true;
  request.mockImplementation((_url, options) => new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error()))));
  await load(); await vi.advanceTimersByTimeAsync(92000);
  expect(request.mock.calls[0]![1].signal.aborted).toBe(true);
});

it('offers reload only on successful manual checks and hides it on retry or refusal', async () => {
  await load();
  document.querySelector('button')!.click();
  await vi.advanceTimersByTimeAsync(0);
  expect(document.querySelector('a')!.hidden).toBe(false);
  expect(document.querySelector('a')!.getAttribute('href')).toBe('/wp-admin/plugins.php');
  request.mockResolvedValue({ ok: true, json: async () => ({ success: false, data: { message: 'Throttled' } }) });
  document.querySelector('button')!.click();
  expect(document.querySelector('a')!.hidden).toBe(true);
  await vi.advanceTimersByTimeAsync(0);
  expect(document.querySelector('p')!.textContent).toBe('Throttled');
  expect(document.querySelector('a')!.hidden).toBe(true);
});
