/** Same bounded operations/rounding as Core\Calculation\Arithmetic; shared fixtures are authoritative. */
export function calculate(operation: string, values: number[], decimals = 2): string | null {
  if (!values.length || !['sum', 'product', 'difference', 'quotient'].includes(operation)) return null;
  let result = values[0]!;
  for (const value of values.slice(1)) {
    if (operation === 'quotient' && value === 0) return null;
    result = operation === 'sum' ? result + value : operation === 'product' ? result * value : operation === 'difference' ? result - value : result / value;
  }
  if (!Number.isFinite(result) || Math.abs(result) > 1e12) return null;
  const places = Math.max(0, Math.min(8, Math.trunc(decimals)));
  const scale = 10 ** places;
  const [base, exponent = '0'] = String(Math.abs(result)).split('e');
  const rounded = Math.sign(result) * Math.round(Number(`${base}e${Number(exponent) + places}`)) / scale;
  return (Object.is(rounded, -0) ? 0 : rounded).toFixed(places);
}
