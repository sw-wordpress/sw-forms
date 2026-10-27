import { describe, expect, it } from 'vitest';
import { testExecutionXml } from '../tools/sonar-report.mjs';

describe('Sonar test-execution import', () => {
  it('maps test files to portable paths and preserves individual case names', () => {
    const xml = testExecutionXml({ testResults: [{ name: '/checkout/test.ts', assertionResults: [{ fullName: 'upload accepts files', status: 'passed', duration: 1.6 }] }] }, () => 'apps/admin/tests/test.ts');
    const parsed = new DOMParser().parseFromString(xml, 'application/xml');
    expect(parsed.querySelector('parsererror')).toBeNull();
    expect(parsed.querySelector('file')?.getAttribute('path')).toBe('apps/admin/tests/test.ts');
    expect(parsed.querySelector('testCase')?.getAttribute('name')).toBe('upload accepts files');
    expect(parsed.querySelector('testCase')?.getAttribute('duration')).toBe('2');
    expect(parsed.querySelector('skipped, failure')).toBeNull();
  });
  it('distinguishes failures and skipped tests without interpreting their text as XML', () => {
    const xml = testExecutionXml({ testResults: [{ name: 'file', assertionResults: [{ fullName: 'quotes " & <tags>', status: 'failed', failureMessages: ['expected <file> & "safe"'] }, { fullName: 'pending', status: 'pending' }] }] }, () => 'tests/file.ts');
    const parsed = new DOMParser().parseFromString(xml, 'application/xml');
    expect(parsed.querySelector('parsererror')).toBeNull();
    expect(parsed.querySelector('failure')?.textContent).toBe('expected <file> & "safe"');
    expect(parsed.querySelector('testCase')?.getAttribute('name')).toBe('quotes " & <tags>');
    expect(parsed.querySelectorAll('skipped')).toHaveLength(1);
    expect(parsed.querySelector('testCase')?.getAttribute('duration')).toBe('0');
  });
});
