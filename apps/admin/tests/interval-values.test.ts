import { describe, expect, it } from 'vitest';
import fixtures from '../../../tests/fixtures/interval-values.json';
import { parseInterval } from '../frontend/intervalValues';

describe('shared interval value contract', () => {
  for (const fixture of fixtures) {
    it(JSON.stringify(fixture), () => expect(parseInterval(fixture.raw)).toEqual(fixture.expected));
  }
});
