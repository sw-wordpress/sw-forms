interface WidgetApi {
  render(container: HTMLElement, options: Record<string, unknown>): string | number;
  reset(id: string | number): void;
  remove(id: string | number): void;
}
const scripts = new Map<string, Promise<void>>();
export class CaptchaControl {
  private id: string | number | null = null;
  private api: WidgetApi | null = null;
  private disposed = false;
  constructor(private readonly form: HTMLFormElement, config?: { provider: string; siteKey?: string }) {
    if (!config || !['turnstile', 'hcaptcha'].includes(config.provider) || !config.siteKey) return;
    const provider = config.provider;
    const url = provider === 'turnstile' ? 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit' : 'https://js.hcaptcha.com/1/api.js?render=explicit';
    let ready = scripts.get(provider);
    if (!ready) {
      ready = new Promise<void>((resolve, reject) => {
        const script = document.createElement('script'); script.src = url; script.async = true;
        script.onload = () => resolve(); script.onerror = () => { scripts.delete(provider); reject(new Error('CAPTCHA unavailable')); };
        document.head.append(script);
      });
      scripts.set(provider, ready);
    }
    void ready.then(() => {
      if (this.disposed || !this.form.isConnected) return;
      this.api = (window as unknown as Record<string, WidgetApi>)[provider] ?? null;
      const container = this.form.querySelector<HTMLElement>('.swf-captcha');
      if (!this.api || !container) return;
      this.id = this.api.render(container, {
        sitekey: config.siteKey, action: 'swforms',
        callback: (token: string) => this.setToken(token),
        'expired-callback': () => this.setToken(''), 'error-callback': () => this.setToken(''),
      });
    }).catch(() => this.setToken(''));
  }
  private setToken(token: string): void {
    const input = this.form.querySelector<HTMLInputElement>('input[name="swf_captcha"]');
    if (input && !this.disposed) input.value = token;
  }
  reset(): void { if (this.disposed) return; this.setToken(''); if (this.id !== null) this.api?.reset(this.id); }
  destroy(): void { this.disposed = true; if (this.id !== null) this.api?.remove(this.id); }
}
