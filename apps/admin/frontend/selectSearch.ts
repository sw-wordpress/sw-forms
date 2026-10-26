/** Native select enhancement: preserve selections and work without JavaScript. */
export function filterSelect(search: HTMLInputElement): void {
  const field = search.closest('[data-swf-id]');
  const select = field?.querySelector<HTMLSelectElement>('select');
  if (!select) return;
  const query = search.value.trim().toLocaleLowerCase();
  let matches = 0;
  for (const option of Array.from(select.options)) {
    const match = option.label.toLocaleLowerCase().includes(query);
    if (match && option.value !== '') matches++;
    // Selected options must remain available to deselect, even if they do not match.
    option.hidden = query !== '' && !match && !option.selected;
  }
  const status = field?.querySelector<HTMLElement>('.swf-search-status');
  if (status) status.textContent = query !== '' && matches === 0 ? search.dataset.swfEmptyMessage ?? '' : '';
}

export function initSelectSearch(root: ParentNode): void {
  root.querySelectorAll<HTMLInputElement>('.swf-select-search').forEach((search) => {
    search.hidden = false;
  });
}
