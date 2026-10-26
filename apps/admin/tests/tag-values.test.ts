import { describe, expect, it } from 'vitest';
import fixtures from '../../../tests/fixtures/tag-values.json';
import { parseTags } from '../frontend/tagValues';

describe('shared tag parser', () => {
  for (const fixture of fixtures) {
    it(JSON.stringify(fixture), () => expect(parseTags(fixture.raw)).toEqual(fixture.expected));
  }
  it('retains one more value or character than allowed, so overflow is rejected', () => {
    expect(parseTags(Array.from({ length: 200 }, (_, index) => String(index)))).toHaveLength(101);
    expect(Array.from(parseTags('😀'.repeat(300))[0]!)).toHaveLength(201);
  });
});
