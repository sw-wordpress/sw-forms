/**
 * SW Forms frontend runtime: conditional logic, repeaters, token refresh and AJAX submission.
 * Without this script forms still work (server side postback).
 */
import './form.css';
import { CaptchaControl } from './captcha';
import { TimePickers } from './timePicker';
import { ImagePreviews } from './imagePreviews';
import { MediaInputs } from './mediaInputs';
import { fileNames } from './fileValues';
import { parseInterval } from './intervalValues';
import { parseTags } from './tagValues';
import { phoneValue } from './inputValues';
import { SelectSearch } from './selectSearch';
import { Evaluator } from '../shared/logic';
import { Tree } from '../shared/tree';
import type { ElementNode, FieldValue, TypeCatalog, Values } from '../shared/types';

interface Config {
  captcha?: { provider: string; siteKey?: string };
  formId: number;
  rest: string;
  ajax: string;
  preview: boolean;
  nonce: string;
  fields: ElementNode[];
  i18n: { sending: string; networkError: string; summary: string };
}

interface SubmitResponse {
  status: 'success' | 'invalid' | 'error';
  code: string;
  message: string;
  errors: Record<string, string>;
  redirect: string;
}

const INDEX = '__INDEX__';
const ROW_ATTRIBUTES = ['name', 'id', 'for', 'aria-describedby', 'aria-labelledby', 'aria-controls', 'data-swf-path', 'data-swf-row', 'href'];

/**
 * Replaces the first `__INDEX__` (the one of the row being added) in all row specific attributes,
 * including the templates of nested repeaters, which keep their own placeholder.
 */
function replacePlaceholder(root: ParentNode, token: string): void {
  root.querySelectorAll<HTMLElement>('*').forEach((el) => {
    for (const attribute of ROW_ATTRIBUTES) {
      const value = el.getAttribute(attribute);
      if (value && value.includes(INDEX)) {
        // ARIA IDREF lists can mention several controls in the same row.
        const resolved = attribute.startsWith('aria-')
          ? value.split(/\s+/).map((reference) => reference.replace(INDEX, token)).join(' ')
          : value.replace(INDEX, token);
        el.setAttribute(attribute, resolved);
      }
    }
    if (el instanceof HTMLTemplateElement) replacePlaceholder(el.content, token);
  });
}

/** The catalog is derived from the public tree: every node says whether it is data and how it nests. */
function catalogFrom(nodes: ElementNode[]): TypeCatalog {
  const types = new Map<string, { data: boolean; container: '' | 'children' | 'columns' }>();
  const walk = (list: ElementNode[]): void => {
    for (const node of list) {
      types.set(node.type, { data: node.data === true, container: node.container ?? '' });
      walk(node.children ?? []);
      for (const column of node.columns ?? []) walk(column.children);
    }
  };
  walk(nodes);
  return {
    has: (type) => types.has(type),
    isData: (type) => types.get(type)?.data ?? false,
    container: (type) => types.get(type)?.container ?? '',
  };
}

function cssEscape(value: string): string {
  return typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(value) : value.replace(/["\\]/g, '\\$&');
}

class SwForm {
  private readonly config: Config;
  private readonly captcha: CaptchaControl;
  private readonly timePickers: TimePickers;
  private readonly imagePreviews: ImagePreviews;
  private readonly mediaInputs: MediaInputs;
  private readonly selects: SelectSearch;
  private readonly tree: Tree;
  private readonly evaluator: Evaluator;
  private nonce: string;
  private rowCounter = 1000;
  private scheduled = false;
  private busy = false;

  constructor(private readonly form: HTMLFormElement) {
    const raw = form.querySelector('script.swf-config')?.textContent ?? '{}';
    this.config = JSON.parse(raw) as Config;
    this.tree = new Tree(this.config.fields, catalogFrom(this.config.fields));
    this.evaluator = new Evaluator(this.tree);
    this.nonce = this.config.nonce;
    this.captcha = new CaptchaControl(form, this.config.captcha);

    this.imagePreviews = new ImagePreviews(form);
    this.mediaInputs = new MediaInputs(form);
    this.selects = new SelectSearch(form);
    this.updateRangeOutputs(form);
    form.addEventListener('reset', () => {
      setTimeout(() => {
        this.captcha.reset();
        this.selects.reset();
        this.updateRangeOutputs(form);
        this.imagePreviews.clear();
        this.mediaInputs.clear();
        this.apply();
      }, 0);
    });
    form.addEventListener('dragover', (event) => {
      const upload = (event.target as Element | null)?.closest('.swf-upload');
      if (upload && !upload.querySelector<HTMLInputElement>('input')?.disabled) event.preventDefault();
    });
    form.addEventListener('drop', (event) => {
      const input = (event.target as Element | null)?.closest('.swf-upload')?.querySelector<HTMLInputElement>('input[type="file"]');
      if (!input || input.disabled || !event.dataTransfer) return;
      event.preventDefault();
      input.files = event.dataTransfer.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    form.addEventListener('input', (e) => this.onChange(e));
    form.addEventListener('change', (e) => this.onChange(e));
    form.addEventListener('click', (e) => this.onClick(e));
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      void this.submit(true);
    });
    this.form.querySelectorAll<HTMLElement>('.swf-repeater').forEach((r) => this.updateRowButtons(r));
    this.timePickers = new TimePickers(form);
    this.apply();
    this.watchForToken();
  }

  // ---------- values & visibility ----------

  private scopeName(prefix: string, id: string): string {
    return `${prefix}[${id}]`;
  }

  private control(scope: ParentNode, name: string): HTMLInputElement[] {
    return Array.from(scope.querySelectorAll<HTMLInputElement>(`[name="${cssEscape(name)}"]`));
  }

  private readValue(node: ElementNode, scope: ParentNode, prefix: string): FieldValue {
    const name = this.scopeName(prefix, node.id);
    switch (node.kind) {
      case 'bool':
        return this.control(scope, name).some((el) => el.checked);
      case 'list': {
        const upload = scope.querySelector<HTMLInputElement>(`input[type="file"][name="${cssEscape(name + '[]')}"]`);
        if (upload) return fileNames(upload.files);
        const from = this.control(scope, name + '[from]')[0];
        const to = this.control(scope, name + '[to]')[0];
        if (from || to) return parseInterval({ from: from?.value ?? '', to: to?.value ?? '' });
        const tags = this.control(scope, name)[0];
        if (tags?.matches('textarea[data-swf-tags]')) return parseTags(tags.value);
        const select = scope.querySelector<HTMLSelectElement>(`select[name="${cssEscape(name + '[]')}"]`);
        if (select) return Array.from(select.selectedOptions).map((o) => o.value);
        return this.control(scope, name + '[]')
          .filter((el) => el.checked)
          .map((el) => el.value);
      }
      case 'matrix':
        return Object.fromEntries(Array.from(scope.querySelectorAll<HTMLInputElement>('input[data-swf-matrix-row]'))
          .filter((input) => input.name === `${name}[${input.dataset.swfMatrixRow}]` && input.checked)
          .map((input) => [input.dataset.swfMatrixRow!, input.value]));
      case 'radio': {
        const checked = this.control(scope, name).find((el) => el.checked);
        if (!checked) return '';
        if (checked.value === '__other__') {
          return this.control(scope, this.scopeName(prefix, node.id + ':other'))[0]?.value ?? '';
        }
        return checked.value;
      }
      default: {
        const value = this.control(scope, name)[0]?.value ?? '';
        const country = this.control(scope, this.scopeName(prefix, node.id + ':country'))[0];
        return country ? phoneValue(value, country.value) : value;
      }
    }
  }

  /** Rows of a repeater in DOM order with their name token. */
  private rows(repeater: Element | null): HTMLElement[] {
    const container = repeater?.querySelector(':scope > .swf-rows');
    return container ? Array.from(container.querySelectorAll<HTMLElement>(':scope > .swf-row')) : [];
  }

  private element(scope: ParentNode, id: string): HTMLElement | null {
    return scope.querySelector<HTMLElement>(`[data-swf-id="${cssEscape(id)}"]`);
  }

  private collect(ids: string[], scope: ParentNode, prefix: string): Values {
    const values: Values = {};
    for (const id of ids) {
      const node = this.tree.node(id);
      if (!node) continue;
      if (this.tree.isRepeater(id)) {
        values[id] = this.rows(this.element(scope, id)).map((row) =>
          this.collect(this.tree.childIds(id), row, `${prefix}[${id}][${row.dataset.swfRow ?? ''}]`),
        );
      } else if (this.tree.isLeafData(id)) {
        values[id] = this.readValue(node, scope, prefix);
      } else {
        Object.assign(values, this.collect(this.tree.childIds(id), scope, prefix));
      }
    }
    return values;
  }

  private updateRangeOutputs(root: ParentNode): void {
    root.querySelectorAll<HTMLInputElement>('input[type="range"]').forEach((input) => {
      const output = input.parentElement?.querySelector<HTMLOutputElement>('output.swf-range-value');
      if (output) {
        output.textContent = input.value;
        output.hidden = false;
      }
    });
  }

  private onChange(event: Event): void {
    const target = event.target as HTMLElement | null;
    if (event.type === 'change' && target instanceof HTMLInputElement && target.matches('[data-swf-image]')) this.imagePreviews.update(target);
    if (target instanceof HTMLInputElement && target.type === 'range') {
      const output = target.parentElement?.querySelector('output.swf-range-value');
      if (output) output.textContent = target.value;
    }
    const wrapper = target?.closest<HTMLElement>('[data-swf-path]');
    if (wrapper?.classList.contains('swf-field--error')) this.clearError(wrapper);
    if (target instanceof HTMLInputElement && target.classList.contains('swf-other-text') && target.value !== '') {
      const radio = target.parentElement?.querySelector<HTMLInputElement>('input[type="radio"]');
      if (radio) radio.checked = true;
    }
    if (this.scheduled) return;
    this.scheduled = true;
    requestAnimationFrame(() => {
      this.scheduled = false;
      this.apply();
    });
  }

  /** Evaluates the logic and shows/hides elements; hidden controls are disabled (not submitted). */
  private apply(): void {
    const states = this.evaluator.states(this.collect(this.tree.rootIds, this.form, 'swf'));
    const visibility = states.visible;
    this.applyTo(this.tree.rootIds, this.form, '', visibility, states.enabled, states.required);
    this.imagePreviews.sync();
    this.timePickers.sync();
    this.mediaInputs.sync();
    this.selects.sync();
  }

  private applyTo(ids: string[], scope: ParentNode, prefix: string, visibility: Map<string, boolean>, enabled: Map<string, boolean>, required: Map<string, boolean>): void {
    for (const id of ids) {
      const el = this.element(scope, id);
      const visible = visibility.get(prefix + id) ?? true;
      const active = visible && (enabled.get(prefix + id) ?? true);
      if (el) {
        el.hidden = !visible;
        el.setAttribute('aria-disabled', active ? 'false' : 'true');
        el.querySelectorAll<HTMLInputElement>('input, select, textarea, button, fieldset').forEach((control) => {
          if (!active) {
            if (!control.disabled) control.dataset.swfLogicDisabled = '1';
            control.disabled = true;
          } else if (control.dataset.swfLogicDisabled) {
            control.disabled = false;
            delete control.dataset.swfLogicDisabled;
          }
        });
        if (this.tree.isLeafData(id)) {
          const needed = required.get(prefix + id) ?? false;
          el.querySelectorAll('input, select, textarea').forEach((control) => control.setAttribute('aria-required', needed ? 'true' : 'false'));
          const label = el.querySelector('.swf-label');
          let marker = label?.querySelector<HTMLElement>('.swf-required');
          if (needed && label && !marker) {
            marker = document.createElement('span'); marker.className = 'swf-required'; marker.setAttribute('aria-hidden', 'true'); marker.textContent = '*'; label.append(marker);
          }
          if (marker) marker.hidden = !needed;
          label?.querySelectorAll<HTMLElement>('.swf-sr').forEach((sr) => { sr.hidden = !needed; });
        }
      }
      if (!visible) continue;
      if (this.tree.isRepeater(id)) {
        this.rows(el).forEach((row, index) =>
          this.applyTo(this.tree.childIds(id), row, `${prefix}${id}[${index}].`, visibility, enabled, required),
        );
        if (el) this.updateRowButtons(el);
      } else {
        this.applyTo(this.tree.childIds(id), scope, prefix, visibility, enabled, required);
      }
    }
  }

  // ---------- repeater ----------

  private onClick(event: Event): void {
    const button = (event.target as HTMLElement | null)?.closest('button');
    if (!button) return;
    const repeater = button.closest<HTMLElement>('.swf-repeater');
    if (!repeater) return;
    if (button.classList.contains('swf-row-add')) {
      this.addRow(repeater);
    } else if (button.classList.contains('swf-row-remove')) {
      this.removeRow(repeater, button.closest<HTMLElement>('.swf-row'));
    }
  }

  private addRow(repeater: HTMLElement): void {
    const template = repeater.querySelector<HTMLTemplateElement>(':scope > .swf-row-template');
    const container = repeater.querySelector<HTMLElement>(':scope > .swf-rows');
    if (!template || !container) return;
    const token = String(this.rowCounter++);
    const fragment = template.content.cloneNode(true) as DocumentFragment;
    replacePlaceholder(fragment, token);
    container.appendChild(fragment);
    this.updateRangeOutputs(container);
    this.updateRowButtons(repeater);
    this.apply();
    const rows = this.rows(repeater);
    this.selects.focus(rows[rows.length - 1]?.querySelector<HTMLElement>('input:not([type="hidden"]):not([hidden]), select, textarea'));
  }

  private removeRow(repeater: HTMLElement, row: HTMLElement | null): void {
    if (!row) return;
    const rows = this.rows(repeater);
    const index = rows.indexOf(row);
    row.remove();
    this.updateRowButtons(repeater);
    this.apply();
    const remaining = this.rows(repeater);
    const focusTarget =
      remaining[Math.max(0, index - 1)]?.querySelector<HTMLElement>('input:not([type="hidden"]):not([hidden]), select, textarea') ??
      repeater.querySelector<HTMLElement>(':scope > .swf-row-add');
    this.selects.focus(focusTarget);
  }

  private updateRowButtons(repeater: HTMLElement): void {
    const count = this.rows(repeater).length;
    const min = Number(repeater.dataset.swfMin ?? 0);
    const max = Number(repeater.dataset.swfMax ?? 50);
    const add = repeater.querySelector<HTMLElement>(':scope > .swf-row-add');
    if (add) add.hidden = count >= max;
    this.rows(repeater).forEach((row) => {
      const remove = row.querySelector<HTMLElement>(':scope > .swf-row-remove');
      if (remove) remove.hidden = count <= min;
    });
  }

  // ---------- token ----------

  private watchForToken(): void {
    if (this.config.preview) return;
    if (!('IntersectionObserver' in window)) {
      void this.refreshToken();
      return;
    }
    const observer = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) {
        observer.disconnect();
        void this.refreshToken();
      }
    });
    observer.observe(this.form);
  }

  /** A fresh token: the page (and the token in it) may come from a cache. */
  private async refreshToken(): Promise<void> {
    try {
      const url = `${this.config.ajax}?action=swf_token&form=${this.config.formId}`;
      const response = await fetch(url, { credentials: 'same-origin' });
      const json = (await response.json()) as { success: boolean; data?: { token: string; nonce: string } };
      if (json.success && json.data) {
        const input = this.form.querySelector<HTMLInputElement>('input[name="swf_token"]');
        if (input) input.value = json.data.token;
        this.nonce = json.data.nonce;
      }
    } catch {
      /* the embedded token stays in place */
    }
  }

  // ---------- submit ----------

  private async submit(allowRetry: boolean): Promise<void> {
    if (this.busy) return;
    const pending = this.form.querySelector<HTMLInputElement>('input[data-swf-media-busy]:not(:disabled)');
    if (pending) { this.showErrors(pending.dataset.swfBusyMessage ?? this.config.i18n.networkError, {}); return; }
    this.busy = true;
    const button = this.form.querySelector<HTMLButtonElement>('.swf-submit');
    const label = button?.textContent ?? '';
    if (button) {
      button.disabled = true;
      button.textContent = this.config.i18n.sending;
    }
    this.form.setAttribute('aria-busy', 'true');

    let retry: 'token' | 'wait' | null = null;
    try {
      const body = new FormData(this.form);
      body.set('swf_source', window.location.href);
      if (this.config.preview) body.set('swf_preview', '1');
      const headers: Record<string, string> = { Accept: 'application/json' };
      if (this.nonce) headers['X-WP-Nonce'] = this.nonce;
      const response = await fetch(this.config.rest, {
        method: 'POST',
        body,
        headers,
        credentials: 'same-origin',
      });
      const result = (await response.json()) as SubmitResponse;
      if (result.status === 'success') {
        this.success(result);
        return;
      }
      if (result.status === 'invalid') {
        this.showErrors(result.message, result.errors ?? {});
      } else if (allowRetry && (result.code === 'expired' || result.code === 'invalid_token')) {
        retry = 'token';
      } else if (allowRetry && result.code === 'too_fast') {
        retry = 'wait';
      } else {
        this.showErrors(result.message || this.config.i18n.networkError, {});
      }
    } catch {
      this.showErrors(this.config.i18n.networkError, {});
    } finally {
      if (retry === null) this.captcha.reset();
      this.busy = false;
      this.form.removeAttribute('aria-busy');
      if (button) {
        button.disabled = false;
        button.textContent = label;
      }
    }
    if (retry === 'token') {
      await this.refreshToken();
      await this.submit(false);
    } else if (retry === 'wait') {
      await new Promise((resolve) => setTimeout(resolve, 2500));
      await this.submit(false);
    }
  }

  private success(result: SubmitResponse): void {
    if (!this.config.preview) this.captcha.destroy();
    if (!this.config.preview || result.redirect) this.timePickers.destroy();
    this.mediaInputs.clear();
    if (result.redirect) {
      this.selects.destroy();
      this.mediaInputs.destroy();
      this.imagePreviews.clear();
      window.location.assign(result.redirect);
      return;
    }
    const wrapper = this.form.parentElement ?? this.form;
    const confirmation = document.createElement('div');
    confirmation.className = 'swf swf-confirmation';
    confirmation.setAttribute('role', 'status');
    confirmation.tabIndex = -1;
    // Server side wp_kses_post() output of the configured confirmation message.
    confirmation.innerHTML = result.message;
    if (this.config.preview) {
      this.mediaInputs.sync();
      this.showErrors('', {});
      const summary = this.form.querySelector<HTMLElement>('.swf-summary');
      if (summary) {
        summary.hidden = false;
        summary.classList.add('swf-summary--ok');
        const title = summary.querySelector('.swf-summary__title');
        if (title) title.textContent = confirmation.textContent ?? '';
        summary.focus();
      }
      return;
    }
    this.imagePreviews.clear();
    this.mediaInputs.destroy();
    this.selects.destroy();
    wrapper.replaceWith(confirmation);
    confirmation.focus();
  }

  // ---------- errors ----------

  /** Finds the element of a server path (`rep[1].name`); row numbers follow the DOM order. */
  private byPath(path: string): HTMLElement | null {
    let scope: ParentNode = this.form;
    const parts = path.split('.');
    for (let i = 0; i < parts.length; i++) {
      const part = parts[i] ?? '';
      const match = /^(.+)\[(\d+)\]$/.exec(part);
      if (match && i < parts.length - 1) {
        const row = this.rows(this.element(scope, match[1] ?? ''))[Number(match[2])];
        if (!row) return null;
        scope = row;
        continue;
      }
      return this.element(scope, part);
    }
    return null;
  }

  private clearError(wrapper: HTMLElement): void {
    wrapper.classList.remove('swf-field--error');
    const message = wrapper.querySelector<HTMLElement>(':scope > .swf-error-message');
    if (message) {
      message.hidden = true;
      message.textContent = '';
    }
    wrapper.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
  }

  private showErrors(title: string, errors: Record<string, string>): void {
    this.form.querySelectorAll<HTMLElement>('.swf-field--error').forEach((el) => this.clearError(el));
    const summary = this.form.querySelector<HTMLElement>('.swf-summary');
    const list = summary?.querySelector('ul');
    list?.replaceChildren();
    summary?.classList.remove('swf-summary--ok');

    for (const [path, message] of Object.entries(errors)) {
      const wrapper = this.byPath(path);
      const slot = wrapper?.querySelector<HTMLElement>(':scope > .swf-error-message');
      if (wrapper && slot) {
        wrapper.classList.add('swf-field--error');
        slot.textContent = message;
        slot.hidden = false;
        wrapper
          .querySelectorAll(':scope input, :scope select, :scope textarea')
          .forEach((el) => el.setAttribute('aria-invalid', 'true'));
      }
      if (list) {
        const item = document.createElement('li');
        const link = document.createElement('a');
        const label = wrapper?.querySelector(':scope > .swf-label, :scope > legend')?.textContent?.replace(/\*.*$/, '').trim();
        link.textContent = label ? `${label}: ${message}` : message;
        link.href = slot?.id ? `#${slot.id}` : '#';
        link.addEventListener('click', (e) => {
          e.preventDefault();
          this.selects.focus(wrapper?.querySelector<HTMLElement>('input:not([type="hidden"]):not([hidden]), select, textarea'));
        });
        item.appendChild(link);
        list.appendChild(item);
      }
    }
    if (!summary) return;
    const titleEl = summary.querySelector('.swf-summary__title');
    if (titleEl) titleEl.textContent = Object.keys(errors).length > 0 ? this.config.i18n.summary : title;
    summary.hidden = title === '' && Object.keys(errors).length === 0;
    if (!summary.hidden) summary.focus();
    this.timePickers.sync();
    this.selects.sync();
  }
}

function init(): void {
  document.querySelectorAll<HTMLFormElement>('form.swf-form[data-swf-form]').forEach((form) => {
    if (form.dataset.swfReady) return;
    form.dataset.swfReady = '1';
    new SwForm(form);
  });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
