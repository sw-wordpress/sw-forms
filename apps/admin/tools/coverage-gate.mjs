/**
 * Strict overall line-coverage gate: more than 80 % is required, exactly 80 % fails.
 * Vitest thresholds treat their value as an inclusive minimum and compare rounded
 * percentages, so the check uses Istanbul's integer line counts instead.
 */
export const MINIMUM_LINE_COVERAGE = 80;
export function checkLineCoverage(summary) {
  const lines = summary?.total?.lines;
  const total = Number(lines?.total);
  const covered = Number(lines?.covered);
  if (!Number.isInteger(total) || !Number.isInteger(covered) || total < 1) throw new Error('No frontend line coverage to check.');
  const percent = ((100 * covered) / total).toFixed(2);
  if (covered * 100 <= total * MINIMUM_LINE_COVERAGE) {
    throw new Error(`Frontend line coverage ${covered}/${total} (${percent}%) must be more than ${MINIMUM_LINE_COVERAGE}%.`);
  }
  return `Frontend line coverage: ${covered}/${total} lines (${percent}%).`;
}
