import TomSelect from 'tom-select/base';
import removeButton from 'tom-select/plugins/remove_button/plugin.js';
import 'tom-select/dist/css/tom-select.default.css';

TomSelect.define('remove_button', removeButton);

/** Locally bundled Tom Select; original selects remain the value and multipart contract. */
export class SelectSearch {
  private readonly controls = new Map<HTMLSelectElement, TomSelect>();

  constructor(private readonly form: HTMLFormElement) {}

  sync(): void {
    for (const [select, control] of this.controls) {
      if (!this.form.contains(select)) { control.destroy(); this.controls.delete(select); }
    }
    this.form.querySelectorAll<HTMLSelectElement>('select[data-swf-search]').forEach((select) => {
      let control = this.controls.get(select);
      if (!control) {
        control = new TomSelect(select, {
          create: false,
          maxOptions: null,
          plugins: select.multiple ? { remove_button: { title: select.dataset.swfRemoveLabel ?? '' } } : [],
          render: { no_results: (_data: unknown, escape: (text: string) => string) => `<div class="no-results" role="status">${escape(select.dataset.swfEmptyMessage ?? '')}</div>` },
        });
        this.controls.set(select, control);
      }
      const values = Array.from(select.selectedOptions).map((option) => option.value).filter((value) => value !== '');
      const items = control.items.filter((value) => value !== '');
      if (values.length !== items.length || values.some((value, index) => value !== items[index])) control.setValue(values, true);
      if (select.disabled !== control.isDisabled) {
        if (select.disabled) control.disable(); else control.enable();
      }
      for (const name of ['aria-describedby', 'aria-invalid', 'aria-required']) {
        const value = select.getAttribute(name);
        if (value === null) control.control_input.removeAttribute(name); else control.control_input.setAttribute(name, value);
      }
    });
  }

  reset(): void {
    for (const control of this.controls.values()) { control.sync(); control.setTextboxValue(''); control.close(); }
  }

  focus(element: HTMLElement | null | undefined): void {
    const control = element instanceof HTMLSelectElement ? this.controls.get(element) : undefined;
    if (control) control.focus(); else element?.focus();
  }

  destroy(): void { for (const control of this.controls.values()) control.destroy(); this.controls.clear(); }
}
