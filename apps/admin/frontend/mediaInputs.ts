import { canSelectFiles } from './mediaFiles';
import { RecordingControl } from './recordingControl';
import { SignaturePad } from './signaturePad';

/** One lifecycle per visible control, including dynamically added repeater rows. */
export class MediaInputs {
  private readonly controls = new Map<HTMLInputElement, SignaturePad | RecordingControl>();
  private readonly events = new AbortController();
  constructor(private readonly form: HTMLFormElement) {
    const options = { signal: this.events.signal };
    window.addEventListener('pagehide', () => this.clear(), options);
    window.addEventListener('pageshow', () => this.sync(), options);
    document.addEventListener('visibilitychange', () => { if (document.hidden) this.clear(); else this.sync(); }, options);
  }

  sync(): void {
    if (!this.form.isConnected) { this.clear(); return; }
    for (const [input, control] of this.controls) {
      if (!this.form.contains(input) || input.disabled) { control.destroy(); this.controls.delete(input); }
    }
    if (!canSelectFiles()) return;
    this.form.querySelectorAll<HTMLInputElement>('input[data-swf-signature], input[data-swf-recording]').forEach((input) => {
      if (input.disabled || this.controls.has(input)) return;
      const field = input.closest('.swf-field');
      try {
        if (input.hasAttribute('data-swf-signature')) {
          const host = field?.querySelector<HTMLElement>(':scope > .swf-signature');
          if (host) this.controls.set(input, new SignaturePad(input, host));
        } else if (window.isSecureContext && typeof navigator.mediaDevices?.getUserMedia === 'function' && typeof MediaRecorder !== 'undefined') {
          const host = field?.querySelector<HTMLElement>(':scope > .swf-recorder');
          if (host) this.controls.set(input, new RecordingControl(input, host));
        }
      } catch { /* Keep native file controls when enhancement APIs are unavailable. */ }
    });
  }

  clear(): void { for (const control of this.controls.values()) control.destroy(); this.controls.clear(); }
  destroy(): void { this.clear(); this.events.abort(); }
}
