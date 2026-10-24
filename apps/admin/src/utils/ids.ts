let counter = 0;
/** Unique DOM id for label/aria wiring. */
export function uid(prefix = 'swf'): string {
  counter += 1;
  return `${prefix}-${counter}`;
}
