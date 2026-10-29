import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { MediaInputs } from '../frontend/mediaInputs';
import { RecordingControl } from '../frontend/recordingControl';
import { SignaturePad } from '../frontend/signaturePad';
import { addImageCrop } from '../frontend/imageCrop';
import { canSelectFiles, pngBlob, selectFiles } from '../frontend/mediaFiles';

class Transfer {
  files: File[] = [];
  items = { add: (file: File) => { this.files.push(file); } };
}
class Recorder {
  static isTypeSupported = vi.fn(() => true);
  static instances: Recorder[] = [];
  state = 'inactive';
  mimeType: string;
  ondataavailable?: (event: { data: Blob }) => void;
  onstop?: () => void;
  onerror?: () => void;
  constructor(_stream: unknown, options: { mimeType: string }) { this.mimeType = options.mimeType; Recorder.instances.push(this); }
  start() { this.state = 'recording'; }
  stop() { this.state = 'inactive'; queueMicrotask(() => { this.ondataavailable?.({ data: new Blob(['clip']) }); this.onstop?.(); }); }
}
let form: HTMLFormElement;
let input: HTMLInputElement;
let host: HTMLElement;
let manager: MediaInputs | undefined;
let signature: SignaturePad | undefined;
let recording: RecordingControl | undefined;
const stopped = vi.fn();
const stream = () => ({ getTracks: () => [Object.assign(new EventTarget(), { stop: stopped })] });
const getUserMedia = vi.fn();
const create = vi.fn(() => 'blob:clip');
const revoke = vi.fn();
const canvas = { fillRect: vi.fn(), beginPath: vi.fn(), moveTo: vi.fn(), lineTo: vi.fn(), stroke: vi.fn(), drawImage: vi.fn() };
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

function setup(kind: 'signature' | 'audio' | 'video' = 'audio') {
  document.body.innerHTML = `<form><div class="swf-field"><input type="file" ${kind === 'signature' ? 'data-swf-signature accept="image/png"' : `data-swf-recording="${kind}" accept=".webm,.mp4,.ogg" data-swf-record-seconds="2" data-swf-record-bytes="100"`}><div class="${kind === 'signature' ? 'swf-signature' : 'swf-recorder'}" hidden>${kind === 'signature' ? '<canvas width="800" height="300"></canvas><button type="button" class="swf-signature-use">use</button><button type="button" class="swf-signature-clear">clear</button>' : '<button type="button" class="swf-record-start">start</button><button type="button" class="swf-record-stop" disabled>stop</button><button type="button" class="swf-record-clear">clear</button>'}<p class="swf-media-status" data-error="failed" data-ready="ready" data-active="recording" data-limit="too large"></p></div></div></form>`;
  form = document.querySelector('form')!; input = form.querySelector('input')!; host = input.nextElementSibling as HTMLElement;
  let files: File[] = [];
  Object.defineProperty(input, 'files', { configurable: true, get: () => files, set: (value: File[]) => { files = value; } });
}
const click = (selector: string) => host.querySelector<HTMLButtonElement>(selector)!.click();
function pointer(type: string, x = 10, y = 10) {
  const event = new Event(type);
  Object.assign(event, { pointerId: 1, button: 0, clientX: x, clientY: y });
  host.querySelector('canvas')!.dispatchEvent(event);
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal('DataTransfer', Transfer); vi.stubGlobal('MediaRecorder', Recorder); vi.stubGlobal('isSecureContext', true);
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia } });
  getUserMedia.mockReset().mockResolvedValue(stream()); stopped.mockClear(); Recorder.instances = []; Recorder.isTypeSupported.mockReset().mockReturnValue(true);
  create.mockClear(); revoke.mockClear();
  vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: revoke });
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => canvas as unknown as CanvasRenderingContext2D);
  vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => callback(new Blob(['png'], { type: 'image/png' })));
  HTMLCanvasElement.prototype.setPointerCapture = vi.fn();
  vi.spyOn(HTMLCanvasElement.prototype, 'getBoundingClientRect').mockReturnValue({ x: 0, y: 0, left: 0, top: 0, right: 800, bottom: 300, width: 800, height: 300, toJSON: () => ({}) });
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {}); vi.spyOn(HTMLMediaElement.prototype, 'load').mockImplementation(() => {});
  setup();
});
afterEach(() => {
  manager?.destroy(); signature?.destroy(); recording?.destroy(); manager = undefined; signature = undefined; recording = undefined;
  vi.restoreAllMocks(); vi.unstubAllGlobals(); vi.useRealTimers(); document.body.replaceChildren();
});

describe('native file bridge', () => {
  it('assigns files and notifies conditions, with a safe unsupported fallback', async () => {
    const change = vi.fn(); input.addEventListener('change', change);
    expect(canSelectFiles()).toBe(true); expect(selectFiles(input, [new File(['x'], 'a.png')])).toBe(true); expect(change).toHaveBeenCalledOnce();
    vi.stubGlobal('DataTransfer', undefined);
    expect(canSelectFiles()).toBe(false); expect(selectFiles(input, [])).toBe(false); expect(input.files).toHaveLength(1);
    vi.stubGlobal('DataTransfer', class { constructor() { throw new Error('Unavailable'); } }); expect(canSelectFiles()).toBe(false);
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => callback(null));
    await expect(pngBlob(document.createElement('canvas'))).rejects.toThrow('export failed');
  });
});

describe('signature drawing', () => {
  it('exports only a deliberate nonempty drawing, with mouse/touch coordinates and a clear action', async () => {
    setup('signature'); signature = new SignaturePad(input, host);
    expect(host.hidden).toBe(false); click('.swf-signature-use'); await flush(); expect(input.files).toHaveLength(0);
    pointer('pointermove'); pointer('pointerdown'); pointer('pointermove', 40, 50); pointer('pointerup');
    click('.swf-signature-use'); await flush();
    expect(input.files?.[0]?.name).toBe('signature.png'); expect(input.files?.[0]?.type).toBe('image/png'); expect(host.textContent).toContain('ready');
    expect(input.dataset.swfMediaBusy).toBeUndefined();
    click('.swf-signature-clear'); expect(input.files).toHaveLength(0);
    input.disabled = true; pointer('pointerdown'); click('.swf-signature-use'); await flush(); expect(input.files).toHaveLength(0);
  });
  it('ignores an export completed after reset/removal and retains a native file on export error', async () => {
    setup('signature'); signature = new SignaturePad(input, host);
    let done: BlobCallback = () => {};
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => { done = callback; });
    pointer('pointerdown'); click('.swf-signature-use'); signature.reset(); done(new Blob(['x'])); await flush(); expect(input.files).toHaveLength(0);
    pointer('pointerdown'); click('.swf-signature-use'); done(null); await flush(); expect(host.textContent).toContain('failed');
    expect(input.dataset.swfMediaBusy).toBeUndefined();
  });
  it('does not let a cancelled export clear the busy state or status of a newer export', async () => {
    setup('signature'); signature = new SignaturePad(input, host); const callbacks: BlobCallback[] = [];
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => { callbacks.push(callback); });
    pointer('pointerdown'); click('.swf-signature-use'); signature.reset(); pointer('pointerdown'); click('.swf-signature-use');
    callbacks[0]!(null); await flush(); expect(input.dataset.swfMediaBusy).toBe('1'); expect(host.textContent).not.toContain('failed');
    callbacks[1]!(new Blob(['png'])); await flush(); expect(input.dataset.swfMediaBusy).toBeUndefined(); expect(input.files?.[0]?.name).toBe('signature.png');
  });
});

describe('recording lifecycle', () => {
  it('requests devices only after Start, stops every track and selects a playable multipart file', async () => {
    recording = new RecordingControl(input, host);
    expect(getUserMedia).not.toHaveBeenCalled(); click('.swf-record-start'); await flush();
    expect(getUserMedia).toHaveBeenCalledWith({ audio: true, video: false }); expect(input.dataset.swfMediaBusy).toBe('1');
    expect(host.textContent).toContain('recording'); click('.swf-record-stop'); await flush();
    expect(stopped).toHaveBeenCalled(); expect(input.files?.[0]?.name).toBe('recording.webm'); expect(host.querySelector('audio')?.controls).toBe(true);
    click('.swf-record-clear'); expect(input.files).toHaveLength(0); expect(revoke).toHaveBeenCalledWith('blob:clip');
  });
  it('supports video/MP4 and stops automatically at the browser recording limit', async () => {
    setup('video'); input.accept = '.mp4'; recording = new RecordingControl(input, host);
    click('.swf-record-start'); await flush(); expect(getUserMedia).toHaveBeenCalledWith({ audio: true, video: true });
    await vi.advanceTimersByTimeAsync(2000); await flush();
    expect(input.files?.[0]?.name).toBe('recording.mp4'); expect(host.querySelector('video')).not.toBeNull(); expect(input.dataset.swfMediaBusy).toBeUndefined();
  });
  it('stops when a device is disconnected', async () => {
    const track = Object.assign(new EventTarget(), { stop: stopped }); getUserMedia.mockResolvedValue({ getTracks: () => [track] });
    recording = new RecordingControl(input, host); click('.swf-record-start'); await flush(); track.dispatchEvent(new Event('ended')); await flush();
    expect(input.files).toHaveLength(1); expect(input.dataset.swfMediaBusy).toBeUndefined();
  });
  it('supports audio Ogg and handles unsupported formats without prompting', async () => {
    input.accept = '.ogg'; recording = new RecordingControl(input, host); click('.swf-record-start'); await flush(); click('.swf-record-stop'); await flush();
    expect(input.files?.[0]?.name).toBe('recording.ogg'); recording.destroy();
    Recorder.isTypeSupported.mockReturnValue(false); recording = new RecordingControl(input, host);
    expect(host.hidden).toBe(true); getUserMedia.mockClear(); click('.swf-record-start'); await flush(); expect(getUserMedia).not.toHaveBeenCalled();
  });
  it('handles denied permissions, asynchronous cancellation and device/recorder errors', async () => {
    recording = new RecordingControl(input, host); getUserMedia.mockRejectedValueOnce(new Error('denied'));
    click('.swf-record-start'); await flush(); expect(host.textContent).toContain('failed'); expect(input.dataset.swfMediaBusy).toBeUndefined();
    let grant: (value: unknown) => void = () => {};
    getUserMedia.mockImplementationOnce(() => new Promise((resolve) => { grant = resolve; }));
    click('.swf-record-start'); recording.reset(); grant(stream()); await flush(); expect(stopped).toHaveBeenCalled(); expect(input.files).toHaveLength(0);
    click('.swf-record-start'); await flush(); Recorder.instances.at(-1)!.onerror?.(); await flush(); expect(host.textContent).toContain('failed'); expect(input.files).toHaveLength(0);
  });
  it('discards oversized output, keeps prior selection and releases preview URLs on replacement', async () => {
    recording = new RecordingControl(input, host); selectFiles(input, [new File(['prior'], 'prior.webm', { type: 'audio/webm' })]);
    click('.swf-record-start'); await flush(); Recorder.instances.at(-1)!.ondataavailable?.({ data: new Blob(['x'.repeat(101)]) }); await flush();
    expect(host.textContent).toContain('too large'); expect(input.files?.[0]?.name).toBe('prior.webm');
    selectFiles(input, [new File(['next'], 'next.webm', { type: 'audio/webm' })]); expect(revoke).toHaveBeenCalledWith('blob:clip');
    create.mockImplementationOnce(() => { throw new Error('no URLs'); }); selectFiles(input, [new File(['next'], 'next.webm', { type: 'audio/webm' })]); expect(input.files).toHaveLength(1);
  });
  it('cancels late stop events without affecting a newer recording', async () => {
    recording = new RecordingControl(input, host); click('.swf-record-start'); await flush(); recording.reset();
    click('.swf-record-start'); await flush(); expect(Recorder.instances.at(-1)!.state).toBe('recording'); expect(input.files).toHaveLength(0);
    input.disabled = true; recording.destroy(); await flush(); expect(input.files).toHaveLength(0);
  });
});

describe('enhancement manager', () => {
  it('initializes visible inputs once and cancels hidden, removed and reset controls', async () => {
    manager = new MediaInputs(form); manager.sync(); manager.sync(); click('.swf-record-start'); await flush();
    input.disabled = true; manager.sync(); await flush(); expect(stopped).toHaveBeenCalled(); expect(host.hidden).toBe(true);
    input.disabled = false; manager.sync(); expect(host.hidden).toBe(false); form.remove(); manager.sync(); expect(host.hidden).toBe(true);
    manager.destroy(); setup('signature'); manager = new MediaInputs(form); manager.sync(); expect(host.hidden).toBe(false); manager.clear(); expect(host.hidden).toBe(true);
    window.dispatchEvent(new Event('pageshow')); expect(host.hidden).toBe(false); window.dispatchEvent(new Event('pagehide')); expect(host.hidden).toBe(true);
    manager.destroy(); window.dispatchEvent(new Event('pageshow')); expect(host.hidden).toBe(true);
  });
  it('retains the native fallback without file transfer, canvas, HTTPS or recording APIs', () => {
    manager = new MediaInputs(form); vi.stubGlobal('isSecureContext', false); manager.sync(); expect(host.hidden).toBe(true);
    vi.stubGlobal('isSecureContext', true); vi.stubGlobal('DataTransfer', undefined); manager.sync(); expect(host.hidden).toBe(true);
    manager.destroy(); vi.stubGlobal('DataTransfer', Transfer); setup('signature'); vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
    manager = new MediaInputs(form); manager.sync(); expect(host.hidden).toBe(true);
  });
});

describe('explicit image crop', () => {
  function crop() {
    input.dataset.swfCrop = ''; input.dataset.swfCropLabels = JSON.stringify({ crop: 'crop', apply: 'apply', cancel: 'cancel', error: 'failed' });
    const file = new File(['original'], 'photo.jpg', { type: 'image/jpeg' }); selectFiles(input, [file, new File(['other'], 'other.png')]);
    const figure = document.createElement('figure'); const image = document.createElement('img'); figure.appendChild(image); host.appendChild(figure);
    Object.defineProperty(image, 'naturalWidth', { value: 200 }); Object.defineProperty(image, 'naturalHeight', { value: 100 });
    addImageCrop(input, figure, image, file, 0); return figure;
  }
  it('replaces only the requested image on Apply with correctly cropped PNG pixels', async () => {
    const figure = crop(); const widths = figure.querySelectorAll<HTMLInputElement>('input'); widths[2]!.value = '50';
    expect(input.files?.[0]?.name).toBe('photo.jpg'); figure.querySelector<HTMLButtonElement>('.swf-crop-apply')!.click(); await flush();
    expect(canvas.drawImage).toHaveBeenLastCalledWith(expect.any(HTMLImageElement), 0, 0, 100, 100, 0, 0, 100, 100);
    expect(input.files?.[0]?.name).toBe('photo-cropped.png'); expect(input.files?.[1]?.name).toBe('other.png'); expect(input.dataset.swfMediaBusy).toBeUndefined();
  });
  it('rejects invalid crops, supports Cancel, and ignores asynchronous stale results', async () => {
    const figure = crop(); const values = figure.querySelectorAll<HTMLInputElement>('input'); const apply = figure.querySelector<HTMLButtonElement>('.swf-crop-apply')!;
    values[0]!.value = '80'; apply.click(); await flush(); expect(figure.textContent).toContain('failed'); expect(input.files?.[0]?.name).toBe('photo.jpg');
    figure.querySelectorAll('button')[1]!.click(); expect(values[0]!.value).toBe('0');
    let done: BlobCallback = () => {}; vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation((callback) => { done = callback; });
    apply.click(); figure.remove(); done(new Blob(['crop'])); await flush(); expect(input.files?.[0]?.name).toBe('photo.jpg');
  });
  it('skips unavailable capabilities and retains originals when canvas export fails', async () => {
    const figure = crop(); vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
    figure.querySelector<HTMLButtonElement>('.swf-crop-apply')!.click(); await flush(); expect(figure.textContent).toContain('failed');
    vi.stubGlobal('DataTransfer', undefined); const another = document.createElement('figure');
    addImageCrop(input, another, document.createElement('img'), new File([], 'x'), 0); expect(another.childElementCount).toBe(0);
  });
});
