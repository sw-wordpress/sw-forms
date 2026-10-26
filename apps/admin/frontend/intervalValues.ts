/** Mirrors Core/Validation/TemporalInterval::parse; nonempty values are ordered [from, to] lists. */
export function parseInterval(raw: unknown): string[] {
  if (raw === null || raw === undefined || raw === '') return [];
  if (typeof raw !== 'object') return ['', '!'];
  const data = raw as Record<string, unknown>;
  const parts = [data.from ?? data[0] ?? '', data.to ?? data[1] ?? ''];
  if (parts.some((part) => typeof part !== 'string')) return ['', '!'];
  const values = parts.map((part) => Array.from((part as string).replace(/^[ \t\r\n\0\v]+|[ \t\r\n\0\v]+$/g, '')).slice(0, 200).join(''));
  return values[0] === '' && values[1] === '' ? [] : values;
}
