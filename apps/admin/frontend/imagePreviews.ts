import { addImageCrop } from './imageCrop';

/** Local previews never upload bytes. Optional cropping requires an explicit Apply action. */
export class ImagePreviews {
  private readonly previews = new Map<HTMLInputElement, { urls: string[]; host: HTMLElement; files: File[] }>();

  constructor(private readonly form: HTMLFormElement) {}

  update(input: HTMLInputElement): void {
    const previous = this.previews.get(input);
    const files = Array.from(input.files ?? []);
    if (previous && !input.disabled && input.matches('[data-swf-image]') && files.length === previous.files.length && files.every((file, index) => file === previous.files[index])) return;
    this.release(input);
    if (!input.matches('input[type="file"][data-swf-image]') || input.disabled || !input.files?.length) return;
    const host = input.closest('.swf-field')?.querySelector<HTMLElement>(':scope > .swf-image-previews');
    if (!host || typeof URL.createObjectURL !== 'function' || typeof URL.revokeObjectURL !== 'function') return;
    const state = { urls: [] as string[], host, files };
    this.previews.set(input, state);
    const limit = Math.min(input.files.length, Math.max(1, Math.min(20, Number(input.dataset.swfPreviewLimit) || 1)));
    for (let i = 0; i < limit; i++) {
      const file = input.files[i]!;
      const figure = document.createElement('figure');
      const caption = document.createElement('figcaption');
      caption.textContent = file.name;
      figure.appendChild(caption);
      if (['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
        const image = document.createElement('img');
        try {
          const url = URL.createObjectURL(file);
          state.urls.push(url);
          image.alt = file.name;
          image.src = url;
          image.addEventListener('error', () => {
            image.remove();
            const message = document.createElement('p');
            message.textContent = input.dataset.swfPreviewError ?? '';
            figure.appendChild(message);
          }, { once: true });
          figure.prepend(image);
          addImageCrop(input, figure, image, file, i);
        } catch {
          // The native file control and server validation remain usable if previews are unavailable.
        }
      }
      host.appendChild(figure);
    }
    host.hidden = false;
  }

  /** Called after visibility/repeater changes; release hidden and removed selections. */
  sync(): void {
    for (const input of this.previews.keys()) {
      if (!this.form.contains(input) || input.disabled || !input.files?.length) this.release(input);
    }
    this.form.querySelectorAll<HTMLInputElement>('input[type="file"][data-swf-image]').forEach((input) => {
      if (!this.previews.has(input)) this.update(input);
    });
  }

  clear(): void {
    for (const input of this.previews.keys()) this.release(input);
  }

  private release(input: HTMLInputElement): void {
    const state = this.previews.get(input);
    if (!state) return;
    state.urls.forEach((url) => URL.revokeObjectURL(url));
    state.host.replaceChildren();
    state.host.hidden = true;
    this.previews.delete(input);
    delete input.dataset.swfMediaBusy;
  }
}
