import { describe, expect, it } from 'vitest';
import fixtures from '../../../tests/fixtures/phone-values.json';
import { phoneValue } from '../frontend/inputValues';

describe('phone collection matches server fixtures', () => {
  for (const fixture of fixtures) {
    it(JSON.stringify(fixture), () => {
      expect(phoneValue(fixture.value, fixture.country)).toBe(fixture.expected);
    });
  }
});
