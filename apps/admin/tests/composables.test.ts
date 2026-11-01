import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { Dark } from 'quasar';
import type * as Quasar from 'quasar';
import { defineComponent, effectScope, nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/api/client';
import { useAsyncData, toApiError } from '@/composables/useAsyncData';
import { useCapabilities } from '@/composables/useCapabilities';
import { useClipboard } from '@/composables/useClipboard';
import { useFormat } from '@/composables/useFormat';
import { useNotify } from '@/composables/useNotify';
import { useThemeSync } from '@/composables/useTheme';
import { setConfigForTests } from '@/config';
import { useAppStore } from '@/stores/app';
import de from '@/i18n/de';
import en from '@/i18n/en';

/** Runs a composable inside a component that has i18n installed. */
function withSetup<T>(composable: () => T, locale = 'en'): T {
  let result!: T;
  mount(defineComponent({
    setup() {
      result = composable();
      return () => null;
    },
  }), { global: { plugins: [createI18n({ legacy: false, locale, messages: { en, de } })] } });
  return result;
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

type Listener = (e: { matches: boolean }) => void;
function stubMatchMedia(matches: boolean) {
  const listeners = new Set<Listener>();
  const mql = {
    matches,
    addEventListener: vi.fn((_: string, fn: Listener) => listeners.add(fn)),
    removeEventListener: vi.fn((_: string, fn: Listener) => listeners.delete(fn)),
  };
  window.matchMedia = vi.fn(() => mql) as unknown as typeof window.matchMedia;
  return { mql, emit: (value: boolean) => listeners.forEach((fn) => fn({ matches: value })), listeners };
}

const notifyCreate = vi.hoisted(() => vi.fn());
vi.mock('quasar', async (importOriginal) => ({
  ...(await importOriginal<typeof Quasar>()),
  Notify: { create: notifyCreate },
}));

const originalMatchMedia = window.matchMedia;

afterEach(() => {
  vi.restoreAllMocks();
  notifyCreate.mockReset();
  window.matchMedia = originalMatchMedia;
  window.localStorage.clear();
});

describe('app store', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('defaults to the system theme and follows the media query', () => {
    stubMatchMedia(true);
    const app = useAppStore();
    expect(app.themePreference).toBe('system');
    expect(app.systemDark).toBe(true);
    expect(app.isDark).toBe(true);
    app.systemDark = false;
    expect(app.isDark).toBe(false);
  });

  it('works without matchMedia', () => {
    // jsdom has no matchMedia by default.
    (window as { matchMedia?: unknown }).matchMedia = undefined;
    const app = useAppStore();
    expect(app.systemDark).toBe(false);
  });

  it('persists manual themes and forgets the system choice', () => {
    window.localStorage.setItem('swf-theme', 'dark');
    const app = useAppStore();
    expect(app.themePreference).toBe('dark');
    expect(app.isDark).toBe(true);
    app.toggleTheme();
    expect(app.themePreference).toBe('light');
    expect(window.localStorage.getItem('swf-theme')).toBe('light');
    app.toggleTheme();
    expect(window.localStorage.getItem('swf-theme')).toBe('dark');
    app.setTheme('system');
    expect(window.localStorage.getItem('swf-theme')).toBeNull();
  });

  it('ignores unknown stored values and unavailable storage', () => {
    window.localStorage.setItem('swf-theme', 'purple');
    expect(useAppStore().themePreference).toBe('system');

    setActivePinia(createPinia());
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('denied');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('denied');
    });
    const app = useAppStore();
    expect(app.themePreference).toBe('system');
    expect(() => app.setTheme('dark')).not.toThrow();
    expect(app.themePreference).toBe('dark');
  });

  it('announces messages through a reset live region', async () => {
    const app = useAppStore();
    app.announcement = 'old';
    app.announce('Saved');
    expect(app.announcement).toBe('');
    await Promise.resolve();
    expect(app.announcement).toBe('Saved');
  });
});

describe('useThemeSync', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('applies the dark class to the root, portals and new portal nodes', async () => {
    const media = stubMatchMedia(false);
    const setDark = vi.spyOn(Dark, 'set').mockImplementation(() => undefined);
    const root = document.createElement('div');
    const portal = document.createElement('div');
    portal.className = 'swf-root';
    document.body.append(root, portal);

    const scope = effectScope();
    scope.run(() => useThemeSync(() => root));
    await nextTick();
    expect(setDark).toHaveBeenLastCalledWith(false);
    expect(root.classList.contains('swf-dark')).toBe(false);

    media.emit(true);
    await nextTick();
    expect(setDark).toHaveBeenLastCalledWith(true);
    expect(root.classList.contains('swf-dark')).toBe(true);
    expect(portal.classList.contains('swf-dark')).toBe(true);

    const added = document.createElement('div');
    added.className = 'swf-root';
    const plain = document.createElement('div');
    document.body.append(added, plain, document.createTextNode('x'));
    await flushPromises();
    expect(added.classList.contains('swf-dark')).toBe(true);
    expect(plain.classList.contains('swf-dark')).toBe(false);

    useAppStore().setTheme('light');
    await nextTick();
    expect(root.classList.contains('swf-dark')).toBe(false);

    scope.stop();
    expect(media.mql.removeEventListener).toHaveBeenCalled();
    expect(media.listeners.size).toBe(0);
    root.remove();
    portal.remove();
    added.remove();
    plain.remove();
  });

  it('copes with a missing root and no matchMedia', async () => {
    (window as { matchMedia?: unknown }).matchMedia = undefined;
    const setDark = vi.spyOn(Dark, 'set').mockImplementation(() => undefined);
    const scope = effectScope();
    scope.run(() => useThemeSync(() => null));
    await nextTick();
    expect(setDark).toHaveBeenCalledWith(false);
    scope.stop();
  });
});

describe('useAsyncData', () => {
  it('wraps unknown errors', () => {
    const api = new ApiError('x', 'y', 400);
    expect(toApiError(api)).toBe(api);
    expect(toApiError(new Error('boom'))).toMatchObject({ code: 'swf_unknown', message: 'boom', status: 0 });
    expect(toApiError('weird').message).toBe('Unknown error');
  });

  it('loads immediately and exposes data', async () => {
    const scope = effectScope();
    const state = scope.run(() => useAsyncData(async () => 42))!;
    expect(state.loading.value).toBe(true);
    await flushPromises();
    expect(state.data.value).toBe(42);
    expect(state.loading.value).toBe(false);
    expect(state.error.value).toBeNull();
    scope.stop();
  });

  it('keeps errors and retries on demand', async () => {
    const fetcher = vi.fn<() => Promise<string>>().mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce('ok');
    const scope = effectScope();
    const state = scope.run(() => useAsyncData(fetcher, { immediate: false }))!;
    expect(fetcher).not.toHaveBeenCalled();
    expect(await state.load()).toBeNull();
    expect(state.error.value?.message).toBe('offline');
    expect(await state.load()).toBe('ok');
    expect(state.error.value).toBeNull();
    expect(state.data.value).toBe('ok');
    scope.stop();
  });

  it('drops stale responses, aborted errors and results after dispose', async () => {
    const first = deferred<number>();
    const second = deferred<number>();
    const fetcher = vi.fn<() => Promise<number>>().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    const scope = effectScope();
    const state = scope.run(() => useAsyncData(fetcher, { immediate: false }))!;
    const a = state.load();
    const b = state.load();
    second.resolve(2);
    await b;
    expect(state.data.value).toBe(2);
    first.resolve(1);
    expect(await a).toBe(1);
    expect(state.data.value).toBe(2);
    expect(state.loading.value).toBe(false);

    fetcher.mockRejectedValueOnce(new ApiError('swf_aborted', 'cancelled', 0));
    await state.load();
    expect(state.error.value).toBeNull();

    const late = deferred<number>();
    fetcher.mockReturnValueOnce(late.promise);
    const pending = state.load();
    scope.stop();
    late.resolve(3);
    await pending;
    expect(state.data.value).toBe(2);
  });
});

describe('useNotify', () => {
  it('creates success, info and error notifications', () => {
    const create = notifyCreate;
    const notify = withSetup(() => useNotify());
    notify.success('Done');
    notify.info('FYI');
    notify.error(new ApiError('swf_bad', 'Bad thing', 400));
    notify.error(new ApiError('swf_empty', '', 400), 'Fallback');
    notify.error(new ApiError('swf_empty', '', 400));
    notify.error(new ApiError('swf_aborted', 'x', 0));
    expect(create).toHaveBeenCalledTimes(5);
    expect(create.mock.calls[0]?.[0]).toMatchObject({ type: 'positive', message: 'Done' });
    expect(create.mock.calls[1]?.[0]).toMatchObject({ type: 'info', message: 'FYI' });
    expect(create.mock.calls[2]?.[0]).toMatchObject({ type: 'negative', message: 'Bad thing', caption: 'swf_bad' });
    expect(create.mock.calls[3]?.[0]).toMatchObject({ message: 'Fallback' });
    expect(create.mock.calls[4]?.[0]).toMatchObject({ message: 'Something went wrong.', actions: [{ 'aria-label': 'Close' }] });
  });
});

describe('useClipboard', () => {
  it('copies text and falls back to showing it', async () => {
    const create = notifyCreate;
    const writeText = vi.fn<(text: string) => Promise<void>>().mockResolvedValueOnce(undefined).mockRejectedValueOnce(new Error('denied'));
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    const copy = withSetup(() => useClipboard());
    await copy('[sw_form id="1"]');
    expect(writeText).toHaveBeenCalledWith('[sw_form id="1"]');
    expect(create.mock.calls[0]?.[0]).toMatchObject({ type: 'positive', message: 'Copied to the clipboard' });
    await copy('fallback');
    expect(create.mock.calls[1]?.[0]).toMatchObject({ type: 'info', message: 'fallback' });
  });
});

describe('useFormat', () => {
  it('formats dates and numbers for the UI locale', () => {
    const fmt = withSetup(() => useFormat(), 'de');
    const expected = new Intl.DateTimeFormat('de', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date('2024-05-06T07:08:00Z'));
    expect(fmt.dateTime('2024-05-06 07:08:00'.replace(' ', 'T'))).toBe(expected);
    expect(fmt.dateTime('2024-05-06T07:08:00Z')).toBe(expected);
    expect(fmt.dateTime('2024-05-06T09:08:00+02:00')).toBe(expected);
    expect(fmt.dateTime('not a date')).toBe('not a date');
    expect(fmt.number(1234.5)).toBe(new Intl.NumberFormat('de').format(1234.5));
  });
});

describe('useCapabilities', () => {
  afterEach(() => setConfigForTests(null));

  it('checks capabilities from the config', () => {
    setConfigForTests({ restRoot: 'http://x/', wpRestRoot: 'http://x/', nonce: '', locale: 'en_US', version: '', adminUrl: '', capabilities: ['swf_view_entries'], siteUrl: '' });
    const { can } = useCapabilities();
    expect(can('swf_view_entries')).toBe(true);
    expect(can('swf_settings')).toBe(false);
  });
});
