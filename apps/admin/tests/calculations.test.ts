import { describe, expect, it } from 'vitest';
import cases from '../../../tests/fixtures/calculations.json';
import { calculate } from '../shared/arithmetic';
describe('shared server/browser calculations', () => {
  for (const fixture of cases) it(JSON.stringify(fixture), () => {
    expect(calculate(fixture.operation, fixture.values, fixture.decimals)).toBe(fixture.expected);
  });
});
