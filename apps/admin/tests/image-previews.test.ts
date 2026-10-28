import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ImagePreviews } from '../frontend/imagePreviews';

let form: HTMLFormElement;
let input: HTMLInputElement;
let host: HTMLElement;
let previews: ImagePreviews;
const create = vi.fn<(file: Blob) => string>();
const revoke = vi.fn<(url: string) => void>();
const select = (...files: File[]) => { input.files = files as unknown as FileList; };

beforeEach(() => {
  document.body.innerHTML = '<form><div class="swf-field"><input type="file" data-swf-image data-swf-preview-limit="2" data-swf-preview-error="Cannot preview"><div class="swf-image-previews" hidden></div></div></form>';
  form = document.querySelector('form')!;
  input = form.querySelector('input')!;
  host = form.querySelector('.swf-image-previews')!;
  Object.defineProperty(input, 'files', { value: [] as unknown as FileList, writable: true });
  create.mockReset().mockImplementation(() => `blob:local-${create.mock.calls.length}`);
  revoke.mockReset();
  vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: revoke });
  previews = new ImagePreviews(form);
});
afterEach(() => { previews.clear(); vi.unstubAllGlobals(); document.body.replaceChildren(); });

describe('local image previews', () => {
  it('shows bounded previews with literal file names and leaves selected files intact', () => {
    const first = new File(['image'], '<img src=x>.png', { type: 'image/png' });
    const second = new File(['image'], 'photo.jpg', { type: 'image/jpeg' });
    select(first, second, new File(['image'], 'extra.webp', { type: 'image/webp' }));
    const selected = input.files;
    previews.update(input);
    expect(host.hidden).toBe(false);
    expect(create).toHaveBeenCalledTimes(2);
    expect(host.querySelectorAll('img')).toHaveLength(2);
    expect(host.querySelector('img')!.alt).toBe(first.name);
    expect(host.querySelector('figcaption')!.textContent).toBe(first.name);
    expect(host.querySelector('img[src="x"]')).toBeNull();
    expect(input.files).toBe(selected);
    expect(input.files).toHaveLength(3);
    previews.sync();
    previews.update(input);
    expect(create).toHaveBeenCalledTimes(2);
  });
  it('releases old URLs and captions when a selection changes or clears', () => {
    select(new File(['one'], 'one.gif', { type: 'image/gif' })); previews.update(input);
    select(new File(['two'], 'two.webp', { type: 'image/webp' })); previews.update(input);
    expect(revoke).toHaveBeenCalledWith('blob:local-1');
    expect(host.textContent).toBe('two.webp');
    select(); previews.update(input);
    expect(revoke).toHaveBeenCalledWith('blob:local-2');
    expect(host.hidden).toBe(true);
    expect(host.childElementCount).toBe(0);
  });
  it('releases hidden and removed controls, then restores a visible selected control', () => {
    select(new File(['one'], 'one.png', { type: 'image/png' })); previews.sync();
    input.disabled = true; previews.sync();
    expect(host.hidden).toBe(true);
    expect(revoke).toHaveBeenCalledTimes(1);
    input.disabled = false; previews.sync();
    expect(host.hidden).toBe(false);
    form.querySelector('.swf-field')!.remove(); previews.sync();
    expect(revoke).toHaveBeenCalledTimes(2);
  });
  it('cleans up all previews on reset or completed submission', () => {
    select(new File(['one'], 'one.png', { type: 'image/png' })); previews.update(input);
    previews.clear(); previews.clear();
    expect(revoke).toHaveBeenCalledTimes(1);
    expect(host.hidden).toBe(true);
  });
  it('keeps native upload usable when a preview fails to decode', () => {
    select(new File(['broken'], 'broken.png', { type: 'image/png' })); previews.update(input);
    host.querySelector('img')!.dispatchEvent(new Event('error'));
    expect(host.querySelector('img')).toBeNull();
    expect(host.textContent).toContain('Cannot preview');
    expect(input.files).toHaveLength(1);
    expect(input.disabled).toBe(false);
  });
  it('does not preview SVG, HTML or unknown types', () => {
    select(new File(['<svg/>'], 'vector.svg', { type: 'image/svg+xml' }), new File(['html'], 'page.html', { type: 'text/html' }));
    previews.update(input);
    expect(create).not.toHaveBeenCalled();
    expect(host.querySelectorAll('img,svg')).toHaveLength(0);
    expect(host.textContent).toBe('vector.svgpage.html');
  });
  it('degrades to the native control without object URL support or a preview host', () => {
    select(new File(['one'], 'one.png', { type: 'image/png' }));
    vi.stubGlobal('URL', {}); previews.update(input);
    expect(host.hidden).toBe(true);
    vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: revoke });
    host.remove(); previews.update(input);
    expect(create).not.toHaveBeenCalled();
  });
  it('handles object URL creation errors without blocking form input', () => {
    select(new File(['one'], 'one.png', { type: 'image/png' }));
    create.mockImplementation(() => { throw new Error('not supported'); });
    expect(() => previews.update(input)).not.toThrow();
    expect(input.files).toHaveLength(1);
    expect(host.textContent).toBe('one.png');
  });
  it('ignores disabled, empty and non-image controls and clamps configured counts', () => {
    previews.update(input); expect(create).not.toHaveBeenCalled();
    select(new File(['one'], 'one.png', { type: 'image/png' })); input.disabled = true;
    previews.update(input); expect(create).not.toHaveBeenCalled();
    input.disabled = false; input.removeAttribute('data-swf-image'); previews.update(input);
    expect(create).not.toHaveBeenCalled();
    input.setAttribute('data-swf-image', ''); input.dataset.swfPreviewLimit = 'bad'; previews.update(input);
    expect(create).toHaveBeenCalledTimes(1);
    input.files = null; previews.sync(); expect(host.hidden).toBe(true);
  });
  it('bounds large preview lists to twenty images', () => {
    input.dataset.swfPreviewLimit = '999';
    select(...Array.from({ length: 21 }, () => new File(['one'], 'one.png', { type: 'image/png' })));
    previews.update(input);
    expect(create).toHaveBeenCalledTimes(20);
  });
});
