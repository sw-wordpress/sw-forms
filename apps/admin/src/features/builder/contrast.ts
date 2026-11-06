/** WCAG 2.2 contrast ratio of two `#rrggbb` colours (same formula as Core\Design\Color). */
export function contrast(first: string, second: string): number {
  const luminance = (hex: string): number => {
    const [r = 0, g = 0, b = 0] = [1, 3, 5].map((i) => {
      const value = Number.parseInt(hex.slice(i, i + 2), 16) / 255;
      return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const a = luminance(first);
  const b = luminance(second);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
