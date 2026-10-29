/** Keep enhancements on the native multipart path, with a fallback when FileList assignment fails. */
export function canSelectFiles(): boolean {
  try { return typeof DataTransfer !== 'undefined' && new DataTransfer().items !== undefined; }
  catch { return false; }
}

export function selectFiles(input: HTMLInputElement, files: File[]): boolean {
  try {
    const transfer = new DataTransfer();
    for (const file of files) transfer.items.add(file);
    input.files = transfer.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  } catch { return false; }
}

export function pngBlob(canvas: HTMLCanvasElement): Promise<Blob> {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('Canvas export failed')), 'image/png');
  });
}
