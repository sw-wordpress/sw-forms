import { describe, expect, it } from 'vitest';
import de from '@/i18n/de';
import en from '@/i18n/en';

function keys(value: unknown, prefix = ''): string[] {
  if (value === null || typeof value !== 'object') return [prefix];
  return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k));
}

describe('i18n', () => {
  it('German and English have the same keys', () => {
    expect(keys(de).sort()).toEqual(keys(en).sort());
  });

  it('placeholders match between languages', () => {
    const placeholders = (text: unknown) => (String(text).match(/\{[a-z]+\}/gi) ?? []).sort();
    const flat = (o: unknown, p = ''): [string, unknown][] =>
      o && typeof o === 'object' ? Object.entries(o as Record<string, unknown>).flatMap(([k, v]) => flat(v, p ? `${p}.${k}` : k)) : [[p, o]];
    const deMap = new Map(flat(de));
    for (const [key, value] of flat(en)) {
      expect(placeholders(deMap.get(key)), key).toEqual(placeholders(value));
    }
  });
});
