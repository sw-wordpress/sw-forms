import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Static contract of the variant stylesheet (jsdom has no layout engine; geometry, motion and
 * overlap are verified in a real browser by scripts/e2e/designs.mjs).
 */
const css = readFileSync(resolve(import.meta.dirname, '../frontend/designs.css'), 'utf8');
const base = readFileSync(resolve(import.meta.dirname, '../frontend/form.css'), 'utf8');
const VARIANTS = ['floating', 'minimal', 'dark-focus', 'guided-card', 'animated-float', 'surprise-motion'];
/** Declarations of every rule whose selector mentions `needle`. */
const rules = (needle: string) => [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].filter((m) => m[1]!.includes(needle)).map((m) => m[2]!);
const lengthMs = (value: string) => (value.endsWith('ms') ? parseFloat(value) : parseFloat(value) * 1000);

describe('design variant stylesheet', () => {
  it('defines its own tokens for every variant', () => {
    for (const variant of VARIANTS) {
      expect(css, variant).toMatch(new RegExp(`\\.swf\\.swf--${variant} \\{[^}]*--swf-`));
    }
    expect(base).toMatch(/--swf-ease: cubic-bezier\(0\.2, 0\.8, 0\.2, 1\)/);
    expect(base).toMatch(/--swf-button-bg: var\(--swf-accent\)/);
  });

  it('animates floating labels with transform only, 180-240 ms and the specified easing', () => {
    const label = rules('.swf-field--floatable > .swf-label').join('');
    expect(label).toMatch(/transform: translateY\(calc\(var\(--swf-f\) \* var\(--swf-label-shift\)\)\) scale\(calc\(1 - var\(--swf-f\) \* 0\.25\)\)/);
    expect(label).toMatch(/transition:\s*transform var\(--swf-duration\) var\(--swf-ease\)/);
    expect(label).not.toMatch(/transition:[^;]*(top|height|font-size|padding)/);
    const duration = /--swf-duration: (\d+ms)/.exec(base)![1]!;
    expect(lengthMs(duration)).toBeGreaterThanOrEqual(180);
    expect(lengthMs(duration)).toBeLessThanOrEqual(240);
    // 16px resting label scaled by 0.75 gives 12px.
    expect(label).toMatch(/font-size: 1rem/);
  });

  it('floats on focus, value, autofill and native date displays; returns only when empty', () => {
    const floated = /\.swf-field--floatable:is\(([\s\S]*?)\) \{\s*--swf-f: 1;/.exec(css)![1]!;
    for (const state of [':focus-within', '.swf-field--filled', ':not(:placeholder-shown)', "[type='date']"]) {
      expect(floated).toContain(state);
    }
    expect(css).toMatch(/:has\(> :autofill\) \{\s*--swf-f: 1;/);
    expect(css).toMatch(/:has\(> :-webkit-autofill\) \{\s*--swf-f: 1;/);
  });

  it('places the animated-float label on the border with a field-coloured notch', () => {
    const variant = rules('.swf.swf--animated-float').join('');
    const top = parseFloat(/--swf-label-top: ([\d.]+)rem/.exec(variant)![1]!);
    const shift = parseFloat(/--swf-label-shift: (-[\d.]+)rem/.exec(variant)![1]!);
    // Label centre (top + half of the 1.5rem line) moves exactly onto the top border.
    expect(top + 0.75 + shift).toBe(0);
    expect(rules('.swf--animated-float .swf-field--floatable > .swf-label').join('')).toMatch(/linear-gradient\(to bottom, transparent calc\(50% - 2px\), var\(--swf-bg\)/);
  });

  it('keeps surprise motion within its timing and only plays on interaction', () => {
    expect(rules('.swf.swf--surprise-motion').join('')).toMatch(/--swf-duration: 240ms/);
    expect(css).toMatch(/background-size: 0% 2px/);
    expect(css).toMatch(/:focus \{\s*background-size: 100% 2px/);
    expect(css).toMatch(/translateX\(4px\)/);
    expect(css).toMatch(/:active:not\(:disabled\) \{\s*transform: scale\(0\.98\)/);
    expect(css).not.toMatch(/animation:|@keyframes|infinite/);
    expect(rules('.swf-field--valid > label.swf-label::after').join('')).toMatch(/opacity: 1/);
  });

  it('switches off decorative motion for reduced motion', () => {
    expect(base).toMatch(/prefers-reduced-motion: reduce\) \{\s*\.swf \*,\s*\.swf \*::before,\s*\.swf \*::after \{\s*transition: none !important;/);
    const reduced = css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)'));
    expect(reduced).toMatch(/::before \{\s*display: none;/);
    expect(reduced).toMatch(/transform: none !important/);
  });

  it('supports light, dark and system schemes and a forced dark variant', () => {
    expect(css).toMatch(/\.swf\.swf-scheme--dark \{\s*color-scheme: dark;/);
    expect(css).toMatch(/\.swf\.swf-scheme--auto \{\s*color-scheme: light dark;/);
    expect(rules('.swf.swf--dark-focus').join('')).toMatch(/color-scheme: dark/);
    expect(css).toMatch(/--swf-bg: light-dark\(/);
  });
});
