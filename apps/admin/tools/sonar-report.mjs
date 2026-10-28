/** Convert Vitest's public JSON results to Sonar's generic test-execution format. */
const escapeXml = (value) => String(value).replace(/[<>&"']/g, (character) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&apos;' })[character]);
export function testExecutionXml(results, sourcePath) {
  const files = results.testResults.map((file) => {
    const cases = file.assertionResults.map((test) => {
      const duration = Math.max(0, Math.round(test.duration ?? 0));
      let outcome = '';
      if (test.status === 'failed') outcome = `<failure message="Test failed">${escapeXml((test.failureMessages ?? []).join('\n'))}</failure>`;
      else if (test.status !== 'passed') outcome = '<skipped message="Test not executed"/>';
      return `<testCase name="${escapeXml(test.fullName)}" duration="${duration}">${outcome}</testCase>`;
    }).join('');
    return `<file path="${escapeXml(sourcePath(file.name))}">${cases}</file>`;
  }).join('');
  return `<?xml version="1.0" encoding="UTF-8"?><testExecutions version="1">${files}</testExecutions>\n`;
}
