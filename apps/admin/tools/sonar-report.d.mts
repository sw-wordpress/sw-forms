export interface TestResults {
  testResults: {
    name: string;
    assertionResults: { fullName: string; status: string; duration?: number; failureMessages?: string[] }[];
  }[];
}
export function testExecutionXml(results: TestResults, sourcePath: (source: string) => string): string;
