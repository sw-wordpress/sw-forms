/** LCOV paths must be relative to the repository, rather than the admin package. */
import console from 'node:console';
import { readFileSync, writeFileSync, realpathSync } from 'node:fs';
import { dirname, isAbsolute, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const root = resolve(packageRoot, '../..');
const report = resolve(packageRoot, 'coverage/lcov.info');
let files = 0;
const normalized = readFileSync(report, 'utf8').replace(/^SF:(.+)$/gm, (_, source) => {
  const path = realpathSync(isAbsolute(source) ? source : resolve(packageRoot, source));
  const name = relative(root, path).replaceAll('\\', '/');
  if (name.startsWith('../') || !name.startsWith('apps/admin/')) throw new Error(`Coverage escaped application: ${source}`);
  files++;
  return `SF:${name}`;
});
if (files === 0 || !/^DA:\d+,[1-9]\d*$/m.test(normalized)) throw new Error('No executed frontend source lines in LCOV.');
writeFileSync(report, normalized);
console.log(`Normalized ${files} frontend source paths for SonarQube.`);

const results = JSON.parse(readFileSync(resolve(packageRoot, 'coverage/tests.json'), 'utf8'));
if (results.numPassedTests < 1 || results.numFailedTests > 0) throw new Error('No successful test execution to import.');
const { testExecutionXml } = await import('./sonar-report.mjs');
const execution = testExecutionXml(results, (source) => {
  const name = relative(root, realpathSync(source)).replaceAll('\\', '/');
  if (!name.startsWith('apps/admin/tests/')) throw new Error(`Test result escaped test sources: ${source}`);
  return name;
});
writeFileSync(resolve(packageRoot, 'coverage/test-execution.xml'), execution);
console.log(`Exported ${results.numPassedTests} executed frontend tests for SonarQube.`);
