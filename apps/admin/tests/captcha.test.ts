import { afterEach, describe, expect, it, vi } from 'vitest';

afterEach(() => { document.body.innerHTML = ''; document.head.querySelectorAll('script[src*="captcha"], script[src*="turnstile"]').forEach((s) => s.remove()); vi.unstubAllGlobals(); });

async function setup(provider: 'turnstile' | 'hcaptcha') {
  vi.resetModules();
  const { CaptchaControl } = await import('../frontend/captcha');
  document.body.innerHTML = '<form><div class="swf-captcha"></div><input name="swf_captcha" value=""></form>';
  const form = document.querySelector('form')!;
  let callbacks: Record<string, unknown> = {};
  const widget = { render: vi.fn((_container, options) => { callbacks = options; return 'widget-1'; }), reset: vi.fn(), remove: vi.fn() };
  vi.stubGlobal(provider, widget);
  const control = new CaptchaControl(form, { provider, siteKey: 'public-site-key' });
  const script = document.head.querySelector<HTMLScriptElement>('script')!;
  return { control, form, widget, script, callbacks: () => callbacks };
}

describe('CAPTCHA widget lifecycle', () => {
  for (const provider of ['turnstile', 'hcaptcha'] as const) {
    it(`${provider} loads only its configured script and passes a token through FormData`, async () => {
      const s = await setup(provider);
      expect(s.script.src).toContain(provider === 'turnstile' ? 'challenges.cloudflare.com/turnstile' : 'js.hcaptcha.com/1/');
      s.script.dispatchEvent(new Event('load')); await Promise.resolve();
      expect(s.widget.render).toHaveBeenCalledOnce();
      (s.callbacks().callback as (token: string) => void)('solved-token');
      expect(new FormData(s.form).get('swf_captcha')).toBe('solved-token');
      (s.callbacks()['expired-callback'] as () => void)();
      expect(new FormData(s.form).get('swf_captcha')).toBe('');
      s.control.reset(); expect(s.widget.reset).toHaveBeenCalledWith('widget-1');
      s.control.destroy(); expect(s.widget.remove).toHaveBeenCalledWith('widget-1');
      s.control.reset(); expect(s.widget.reset).toHaveBeenCalledOnce();
    });
  }
  it('does not render a widget after teardown while the script is loading', async () => {
    const s = await setup('turnstile');
    s.control.destroy(); s.script.dispatchEvent(new Event('load')); await Promise.resolve();
    expect(s.widget.render).not.toHaveBeenCalled();
  });
  it('does not load any script when CAPTCHA is not configured', async () => {
    vi.resetModules(); const { CaptchaControl } = await import('../frontend/captcha');
    document.body.innerHTML = '<form></form>';
    new CaptchaControl(document.querySelector('form')!);
    expect(document.head.querySelector('script')).toBeNull();
  });
});
