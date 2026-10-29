import { canSelectFiles, pngBlob, selectFiles } from './mediaFiles';

/** Percentage controls are usable with touch, mouse and keyboard; original bytes change only on Apply. */
export function addImageCrop(input: HTMLInputElement, figure: HTMLElement, image: HTMLImageElement, file: File, index: number): void {
  if (!input.hasAttribute('data-swf-crop') || !canSelectFiles()) return;
  const labels = JSON.parse(input.dataset.swfCropLabels ?? '{}') as Record<string, string>;
  const details = document.createElement('details');
  details.className = 'swf-image-crop';
  const summary = document.createElement('summary');
  summary.textContent = labels.crop ?? '';
  details.appendChild(summary);
  const values = {} as Record<'x' | 'y' | 'width' | 'height', HTMLInputElement>;
  for (const key of ['x', 'y', 'width', 'height'] as const) {
    const label = document.createElement('label');
    label.textContent = labels[key] ?? '';
    const value = document.createElement('input');
    value.type = 'number'; value.min = key === 'x' || key === 'y' ? '0' : '1'; value.max = '100'; value.step = '1';
    value.value = key === 'x' || key === 'y' ? '0' : '100';
    values[key] = value; label.appendChild(value); details.appendChild(label);
  }
  const apply = document.createElement('button');
  apply.type = 'button'; apply.textContent = labels.apply ?? ''; apply.className = 'swf-crop-apply';
  const cancel = document.createElement('button');
  cancel.type = 'button'; cancel.textContent = labels.cancel ?? '';
  const status = document.createElement('p'); status.setAttribute('role', 'status');
  let version = 0;
  cancel.addEventListener('click', () => {
    version++; delete input.dataset.swfMediaBusy;
    details.open = false; apply.disabled = false; status.textContent = '';
    for (const key of ['x', 'y', 'width', 'height'] as const) values[key].value = key === 'x' || key === 'y' ? '0' : '100';
  });
  apply.addEventListener('click', () => { void (async () => {
    if (input.disabled || input.dataset.swfMediaBusy) return;
    const selected = Array.from(input.files ?? []);
    const current = ++version;
    apply.disabled = true; input.dataset.swfMediaBusy = '1';
    try {
      if (!image.naturalWidth || !image.naturalHeight || selected[index] !== file || Object.values(values).some((value) => !value.checkValidity() || value.value === '')) throw new Error('Invalid crop');
      const x = Math.floor(image.naturalWidth * Number(values.x.value) / 100);
      const y = Math.floor(image.naturalHeight * Number(values.y.value) / 100);
      const width = Math.floor(image.naturalWidth * Number(values.width.value) / 100);
      const height = Math.floor(image.naturalHeight * Number(values.height.value) / 100);
      if (width < 1 || height < 1 || x + width > image.naturalWidth || y + height > image.naturalHeight) throw new Error('Crop outside image');
      const canvas = document.createElement('canvas');
      const scale = Math.min(1, 4096 / Math.max(width, height));
      canvas.width = Math.max(1, Math.round(width * scale)); canvas.height = Math.max(1, Math.round(height * scale));
      const context = canvas.getContext('2d');
      if (!context) throw new Error('Canvas unavailable');
      context.drawImage(image, x, y, width, height, 0, 0, canvas.width, canvas.height);
      const blob = await pngBlob(canvas);
      if (current !== version || input.disabled || !figure.isConnected || selected.some((original, i) => input.files?.[i] !== original) || selected.length !== input.files?.length) return;
      const name = file.name.replace(/\.[^.]*$/, '') + '-cropped.png';
      selected[index] = new File([blob], name, { type: 'image/png' });
      if (!selectFiles(input, selected)) throw new Error('File selection unavailable');
    } catch { status.textContent = labels.error ?? ''; }
    finally { if (version === current) { delete input.dataset.swfMediaBusy; apply.disabled = false; } }
  })(); });
  details.append(apply, cancel, status); figure.appendChild(details);
}
