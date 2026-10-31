import { describe, expect, it } from 'vitest';
import { checkLineCoverage } from '../tools/coverage-gate.mjs';

const summary = (covered: number, total: number) => ({ total: { lines: { covered, total, pct: Math.round((10000 * covered) / total) / 100 } } });

describe('frontend line-coverage gate', () => {
  it('rejects exactly 80 percent and accepts anything above it', () => {
    expect(() => checkLineCoverage(summary(8000, 10000))).toThrow('8000/10000 (80.00%) must be more than 80%');
    expect(checkLineCoverage(summary(8001, 10000))).toBe('Frontend line coverage: 8001/10000 lines (80.01%).');
  });
  it('uses line counts rather than the rounded percentage', () => {
    // 80.0004 % is rounded to pct 80 by Istanbul but is still more than 80 %.
    expect(() => checkLineCoverage(summary(200001, 250000))).not.toThrow();
    expect(() => checkLineCoverage(summary(79999, 100000))).toThrow('must be more than 80%');
  });
  it('fails on missing or empty summaries', () => {
    expect(() => checkLineCoverage(undefined)).toThrow('No frontend line coverage');
    expect(() => checkLineCoverage(summary(0, 0))).toThrow('No frontend line coverage');
    expect(() => checkLineCoverage({ total: { lines: { covered: 'x', total: 5 } } })).toThrow('No frontend line coverage');
  });
});
