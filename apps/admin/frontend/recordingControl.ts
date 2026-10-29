import { selectFiles } from './mediaFiles';

const FORMATS = {
  audio: ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'],
  video: ['video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'],
};

export class RecordingControl {
  private readonly events = new AbortController();
  private readonly startButton: HTMLButtonElement;
  private readonly stopButton: HTMLButtonElement;
  private readonly status: HTMLElement;
  private readonly kind: 'audio' | 'video';
  private readonly formats: string[];
  private stream?: MediaStream;
  private recorder?: MediaRecorder;
  private timer?: ReturnType<typeof setTimeout>;
  private version = 0;
  private url?: string;
  private preview?: HTMLMediaElement;

  constructor(private readonly input: HTMLInputElement, private readonly host: HTMLElement) {
    this.kind = input.dataset.swfRecording === 'video' ? 'video' : 'audio';
    const allowed = input.accept.split(',');
    this.formats = FORMATS[this.kind].filter((mime) => {
      const extension = mime.includes('webm') ? '.webm' : mime.includes('ogg') ? '.ogg' : '.mp4';
      return allowed.includes(extension) && MediaRecorder.isTypeSupported(mime);
    });
    this.startButton = host.querySelector('.swf-record-start')!;
    this.stopButton = host.querySelector('.swf-record-stop')!;
    this.stopButton.disabled = true;
    this.status = host.querySelector('.swf-media-status')!;
    const options = { signal: this.events.signal };
    this.startButton.addEventListener('click', () => { void this.start(); }, options);
    this.stopButton.addEventListener('click', () => this.stop(), options);
    host.querySelector('.swf-record-clear')!.addEventListener('click', () => { this.reset(); selectFiles(input, []); }, options);
    input.addEventListener('change', () => {
      this.reset();
      const file = input.files?.[0];
      if (file) this.showPreview(file);
    }, options);
    host.hidden = this.formats.length === 0;
    if (!host.hidden && input.files?.[0]) this.showPreview(input.files[0]);
  }

  private async start(): Promise<void> {
    if (this.input.disabled || this.input.dataset.swfMediaBusy || this.formats.length === 0) return;
    const version = ++this.version;
    this.input.dataset.swfMediaBusy = '1'; this.startButton.disabled = true; this.status.textContent = '';
    let acquired: MediaStream | undefined;
    try {
      acquired = await navigator.mediaDevices.getUserMedia({ audio: true, video: this.kind === 'video' });
      if (version !== this.version || this.input.disabled || !this.input.isConnected) { acquired.getTracks().forEach((track) => track.stop()); return; }
      this.stream = acquired;
      const recorder = new MediaRecorder(acquired, { mimeType: this.formats[0]!, audioBitsPerSecond: 64000, videoBitsPerSecond: 1000000 });
      this.recorder = recorder;
      const chunks: Blob[] = [];
      let bytes = 0;
      let oversized = false;
      const maxBytes = Math.max(1, Number(this.input.dataset.swfRecordBytes) || 10485760);
      recorder.ondataavailable = (event) => {
        bytes += event.data.size;
        if (bytes > maxBytes) { oversized = true; chunks.length = 0; this.stop(); }
        else if (!oversized && event.data.size) chunks.push(event.data);
      };
      recorder.onerror = () => { this.reset(); this.status.textContent = this.status.dataset.error ?? ''; };
      recorder.onstop = () => {
        if (version !== this.version || this.input.disabled || !this.input.isConnected) return;
        this.releaseStream();
        delete this.input.dataset.swfMediaBusy; this.startButton.disabled = false; this.stopButton.disabled = true;
        if (oversized || bytes === 0) { this.status.textContent = this.status.dataset[oversized ? 'limit' : 'error'] ?? ''; return; }
        const mime = recorder.mimeType.split(';')[0]!;
        const extension = mime.includes('webm') ? 'webm' : mime.includes('ogg') ? 'ogg' : 'mp4';
        if (selectFiles(this.input, [new File(chunks, `recording.${extension}`, { type: mime })])) this.status.textContent = this.status.dataset.ready ?? '';
        else this.status.textContent = this.status.dataset.error ?? '';
      };
      for (const track of acquired.getTracks()) track.addEventListener('ended', () => this.stop(), { signal: this.events.signal });
      recorder.start(250); this.stopButton.disabled = false; this.status.textContent = this.status.dataset.active ?? '';
      this.timer = setTimeout(() => this.stop(), Math.max(1, Math.min(600, Number(this.input.dataset.swfRecordSeconds) || 60)) * 1000);
    } catch {
      acquired?.getTracks().forEach((track) => track.stop());
      if (version === this.version) { this.reset(); this.status.textContent = this.status.dataset.error ?? ''; }
    }
  }

  private stop(): void {
    if (this.recorder?.state === 'recording' || this.recorder?.state === 'paused') this.recorder.stop();
    this.releaseStream(); this.stopButton.disabled = true;
  }

  private releaseStream(): void {
    clearTimeout(this.timer); this.timer = undefined;
    this.stream?.getTracks().forEach((track) => track.stop()); this.stream = undefined;
  }

  private showPreview(file: File): void {
    if (!/^(audio|video)\//.test(file.type)) return;
    try {
      this.url = URL.createObjectURL(file);
      this.preview = document.createElement(this.kind);
      this.preview.controls = true; this.preview.preload = 'metadata'; this.preview.src = this.url;
      this.host.appendChild(this.preview);
    } catch { /* Native file upload remains usable. */ }
  }

  reset(): void {
    this.version++;
    this.stop(); this.recorder = undefined; delete this.input.dataset.swfMediaBusy;
    this.startButton.disabled = false; this.stopButton.disabled = true; this.status.textContent = '';
    if (this.preview) { this.preview.pause(); this.preview.removeAttribute('src'); this.preview.load(); this.preview.remove(); this.preview = undefined; }
    if (this.url) { URL.revokeObjectURL(this.url); this.url = undefined; }
  }

  destroy(): void { this.reset(); this.events.abort(); this.host.hidden = true; }
}
