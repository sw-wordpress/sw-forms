import { pngBlob, selectFiles } from './mediaFiles';

export class SignaturePad {
  private readonly events = new AbortController();
  private readonly canvas: HTMLCanvasElement;
  private readonly context: CanvasRenderingContext2D;
  private readonly status: HTMLElement;
  private pointer: number | null = null;
  private drawn = false;
  private version = 0;

  constructor(private readonly input: HTMLInputElement, private readonly host: HTMLElement) {
    this.canvas = host.querySelector('canvas')!;
    const context = this.canvas.getContext('2d');
    if (!context) throw new Error('Canvas unavailable');
    this.context = context;
    this.status = host.querySelector('.swf-media-status')!;
    this.clearCanvas();
    const options = { signal: this.events.signal };
    this.canvas.addEventListener('pointerdown', (event) => {
      if (input.disabled || this.pointer !== null || event.button !== 0) return;
      this.pointer = event.pointerId;
      this.canvas.setPointerCapture(event.pointerId);
      const [x, y] = this.position(event);
      this.context.beginPath(); this.context.moveTo(x, y); this.context.lineTo(x + 0.1, y + 0.1); this.context.stroke();
      this.drawn = true; this.status.textContent = '';
    }, options);
    this.canvas.addEventListener('pointermove', (event) => {
      if (input.disabled || this.pointer !== event.pointerId) return;
      const [x, y] = this.position(event);
      this.context.lineTo(x, y); this.context.stroke();
    }, options);
    for (const name of ['pointerup', 'pointercancel', 'lostpointercapture']) this.canvas.addEventListener(name, () => { this.pointer = null; }, options);
    host.querySelector('.swf-signature-use')!.addEventListener('click', () => { void this.use(); }, options);
    host.querySelector('.swf-signature-clear')!.addEventListener('click', () => { this.reset(); selectFiles(input, []); }, options);
    input.addEventListener('change', () => { this.version++; delete input.dataset.swfMediaBusy; this.clearCanvas(); }, options);
    host.hidden = false;
  }

  private position(event: PointerEvent): [number, number] {
    const rect = this.canvas.getBoundingClientRect();
    return [Math.max(0, Math.min(this.canvas.width, (event.clientX - rect.left) * this.canvas.width / rect.width)), Math.max(0, Math.min(this.canvas.height, (event.clientY - rect.top) * this.canvas.height / rect.height))];
  }

  private clearCanvas(): void {
    this.context.fillStyle = '#ffffff'; this.context.fillRect(0, 0, this.canvas.width, this.canvas.height);
    this.context.strokeStyle = '#111111'; this.context.lineWidth = 3; this.context.lineCap = 'round'; this.context.lineJoin = 'round';
    this.pointer = null; this.drawn = false;
  }

  private async use(): Promise<void> {
    if (!this.drawn || this.input.disabled || this.input.dataset.swfMediaBusy) return;
    const version = ++this.version;
    const previous = this.input.files?.[0];
    this.input.dataset.swfMediaBusy = '1';
    try {
      const blob = await pngBlob(this.canvas);
      if (version !== this.version || this.input.disabled || !this.input.isConnected || this.input.files?.[0] !== previous) return;
      if (!selectFiles(this.input, [new File([blob], 'signature.png', { type: 'image/png' })])) throw new Error('File selection unavailable');
      this.status.textContent = this.status.dataset.ready ?? '';
    } catch { if (version === this.version) this.status.textContent = this.status.dataset.error ?? ''; }
    finally { if (version === this.version) delete this.input.dataset.swfMediaBusy; }
  }

  reset(): void { this.version++; delete this.input.dataset.swfMediaBusy; this.clearCanvas(); this.status.textContent = ''; }
  destroy(): void { this.reset(); this.events.abort(); this.host.hidden = true; }
}
