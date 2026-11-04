const TRIM_CHARS = ' \t\r\n\0\v';

/** Same character set as PHP's trim(); linear time, unlike an anchored `+` regex. */
export function phpTrim(value: string): string {
  let start = 0;
  let end = value.length;
  while (start < end && TRIM_CHARS.includes(value.charAt(start))) start++;
  while (end > start && TRIM_CHARS.includes(value.charAt(end - 1))) end--;
  return value.slice(start, end);
}
