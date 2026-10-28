import { describe, expect, it } from 'vitest';
import { fileNames } from '../frontend/fileValues';

describe('upload condition values', () => {
  it('reads plain names and preserves literal markup for escaped output', () => {
    const files = [new File(['a'], ' Consent.PDF '), new File(['a'], '<b>.txt'), new File(['a'], 'a\u0000b.txt'), new File(['a'], '\u00a0name.txt\u00a0')];
    expect(fileNames(files as unknown as FileList)).toEqual(['Consent.PDF', '<b>.txt', 'ab.txt', '\u00a0name.txt\u00a0']);
  });
  it('represents an unanswered upload as an empty list', () => {
    expect(fileNames(null)).toEqual([]);
  });
  it.each(['', ' ', '     '])('handles empty or space-only names: %j', (name) => {
    expect(fileNames([new File(['a'], name)] as unknown as FileList)).toEqual(['']);
  });
  it('preserves long internal runs of spaces while trimming the edges', () => {
    const name = `a${' '.repeat(100_000)}b.txt`;
    expect(fileNames([new File(['a'], `  ${name}  `)] as unknown as FileList)).toEqual([name]);
  });
  it('removes controls before trimming and retains Unicode spaces', () => {
    const files = [new File(['a'], ' \t name.txt\0 '), new File(['a'], ' \u00a0name.txt\u00a0 ')];
    expect(fileNames(files as unknown as FileList)).toEqual(['name.txt', '\u00a0name.txt\u00a0']);
  });
});
