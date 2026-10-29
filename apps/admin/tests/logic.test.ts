import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { Evaluator, matches } from '@shared/logic';
import { Tree } from '@shared/tree';
import { BUILT_IN_CATALOG } from '@shared/types';
import type { ElementNode, Values } from '@shared/types';

/** Shared fixtures, also run by PHPUnit (tests/Unit/LogicFixturesTest.php). */
const DIR = resolve(__dirname, '../../../tests/fixtures/logic');
const load = (file: string) => JSON.parse(readFileSync(resolve(DIR, file), 'utf8'));

describe('operators (fixtures/operators.json)', () => {
  const data = load('operators.json') as {
    cases: { op: string; value: unknown; target: string; expected: boolean }[];
  };
  it.each(data.cases.map((c, i) => [i, c] as const))('case %i', (_i, c) => {
    expect(matches(c.op, c.value, c.target)).toBe(c.expected);
  });
});

describe('visibility fixtures', () => {
  for (const file of ['basic.json', 'cascade.json', 'containers.json', 'repeater.json', 'contact-groups.json', 'files.json', 'media.json', 'matrix.json']) {
    const data = load(file) as {
      fields: ElementNode[];
      cases: { name: string; values: Values; expected: Record<string, boolean> }[];
    };
    for (const c of data.cases) {
      it(`${file}: ${c.name}`, () => {
        const result = new Evaluator(new Tree(data.fields, BUILT_IN_CATALOG)).evaluate(c.values);
        for (const [path, visible] of Object.entries(c.expected)) {
          expect(result.has(path), `path ${path} missing`).toBe(true);
          expect(result.get(path), `path ${path}`).toBe(visible);
        }
      });
    }
  }
});
