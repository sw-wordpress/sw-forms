import { describe, expect, it } from 'vitest';
import { phpTrim } from '../frontend/phpTrim';

describe('phpTrim', () => {
  it('strips the PHP trim() character set from both ends only', () => {
    expect(phpTrim(' \t\r\n\0\vab c\v\0\n\r\t ')).toBe('ab c');
    expect(phpTrim('   ')).toBe('');
    expect(phpTrim('')).toBe('');
  });
  it('stays fast on long whitespace runs', () => {
    const start = Date.now();
    phpTrim(`${' '.repeat(200000)}x${' '.repeat(200000)}y`);
    expect(Date.now() - start).toBeLessThan(500);
  });
});
