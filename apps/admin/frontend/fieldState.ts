/**
 * Presentation state of field wrappers for the design variants:
 * `swf-field--filled` (floating labels stay up) and `swf-field--valid` (success tick).
 * CSS also reacts to `:focus-within`, `:placeholder-shown` and `:autofill`; the class covers
 * selects and values the browser restores without events.
 */

const CONTROLS = 'input:not([type="hidden"]), select, textarea';

/** Whether any enabled control of a field wrapper carries a value. */
export function hasValue(wrapper: Element): boolean {
  return Array.from(wrapper.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(CONTROLS)).some((control) => {
    if (control.disabled) return false;
    if (control instanceof HTMLInputElement && (control.type === 'checkbox' || control.type === 'radio')) return control.checked;
    if (control instanceof HTMLInputElement && control.type === 'file') return (control.files?.length ?? 0) > 0;
    if (control instanceof HTMLSelectElement && control.multiple) return control.selectedOptions.length > 0;
    return control.value.trim() !== '';
  });
}

/** Synchronises the filled class of floatable fields inside `root` (or of `root` itself). */
export function syncFilled(root: Element | Document): void {
  const fields = root instanceof Element && root.matches('.swf-field--floatable') ? [root] : Array.from(root.querySelectorAll('.swf-field--floatable'));
  for (const field of fields) field.classList.toggle('swf-field--filled', hasValue(field));
}

/**
 * After a validation of `scope` (server response or demo check, errors already shown), marks
 * the visible, enabled leaf fields that have a value and no error.
 */
export function markValid(scope: ParentNode): void {
  scope.querySelectorAll<HTMLElement>('.swf-field[data-swf-path]').forEach((field) => {
    const valid = !field.classList.contains('swf-field--error')
      && !field.closest('[hidden]')
      && field.getAttribute('aria-disabled') !== 'true'
      && !field.querySelector('.swf-field[data-swf-path]')
      && hasValue(field);
    field.classList.toggle('swf-field--valid', valid);
  });
}

/** A changed value is no longer known to be valid. */
export function clearValid(field: Element | null | undefined): void {
  field?.classList.remove('swf-field--valid');
}
