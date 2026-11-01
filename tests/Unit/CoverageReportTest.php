<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CoverageReportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/swf-clover-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function report(string $name, string $source, string $lines): string
    {
        $file = $this->directory . '/' . $name . '.xml';
        file_put_contents($file, '<coverage><project><file name="' . htmlspecialchars($source, ENT_QUOTES | ENT_XML1) . '">' . $lines . '</file></project></coverage>');
        return $file;
    }

    /** @param list<string> $reports
     * @return array{int, string}
     */
    private function runMerge(array $reports, string $reporter = 'merge.php'): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/coverage/' . $reporter, '--output', $this->directory . '/merged.xml', ...$reports], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output . $error];
    }

    public function testOverlappingRunsRetainHitsAndPortableSourcePaths(): void
    {
        $source = dirname(__DIR__, 2) . '/src/Core/Form/Tree.php';
        $first = $this->report('first', $source, '<line num="10" type="stmt" count="2"/><line num="11" type="stmt" count="0"/>');
        $second = $this->report('second', $source, '<line num="10" type="stmt" count="0"/><line num="11" type="stmt" count="3"/>');
        [$status, $output] = $this->runMerge([$first, $second]);
        self::assertSame(0, $status, $output);
        $xml = new \DOMDocument();
        self::assertTrue($xml->load($this->directory . '/merged.xml'));
        self::assertSame('src/Core/Form/Tree.php', $xml->getElementsByTagName('file')[0]->getAttribute('name'));
        self::assertSame('2', $xml->getElementsByTagName('line')[0]->getAttribute('count'));
        self::assertSame('3', $xml->getElementsByTagName('line')[1]->getAttribute('count'));
        self::assertSame('2', $xml->getElementsByTagName('metrics')[0]->getAttribute('coveredstatements'));
    }

    public function testEmptyOrUnexecutedReportsDoNotProduceGreenCoverage(): void
    {
        [$status] = $this->runMerge([]);
        self::assertNotSame(0, $status);
        $report = $this->report('unexecuted', dirname(__DIR__, 2) . '/src/Core/Form/Tree.php', '<line num="10" type="stmt" count="0"/>');
        [$status, $output] = $this->runMerge([$report]);
        self::assertNotSame(0, $status);
        self::assertStringContainsString('No executed production lines', $output);
    }

    public function testSourcesOutsideTheCheckoutAreRejected(): void
    {
        $outside = $this->directory . '/outside.php';
        file_put_contents($outside, '<?php echo 1;');
        [$status, $output] = $this->runMerge([$this->report('escaped', $outside, '<line num="1" type="stmt" count="1"/>')]);
        self::assertNotSame(0, $status);
        self::assertStringContainsString('Coverage escaped project', $output);
    }
    public function testJUnitImportsPreserveOutcomesAndNormalizeTestPaths(): void
    {
        $file = htmlspecialchars(__FILE__, ENT_QUOTES | ENT_XML1);
        $first = $this->directory . '/first.xml';
        $second = $this->directory . '/second.xml';
        file_put_contents($first, '<testsuites><testsuite name="unit"><testcase file="' . $file . '" name="failed"><failure message="bad">expected &lt;file&gt;</failure></testcase></testsuite></testsuites>');
        file_put_contents($second, '<testsuites><testsuite name="wordpress"><testcase file="' . $file . '" name="pending"><skipped/></testcase></testsuite></testsuites>');
        [$status, $output] = $this->runMerge([$first, $second], 'junit.php');
        self::assertSame(0, $status, $output);
        $xml = new \DOMDocument();
        self::assertTrue($xml->load($this->directory . '/merged.xml'));
        self::assertCount(2, $xml->getElementsByTagName('testcase'));
        self::assertSame('tests/Unit/CoverageReportTest.php', $xml->getElementsByTagName('testcase')[0]->getAttribute('file'));
        self::assertSame('expected <file>', $xml->getElementsByTagName('failure')[0]->textContent);
        self::assertCount(1, $xml->getElementsByTagName('skipped'));
    }

    public function testJUnitRejectsEmptySuitesAndForeignTestFiles(): void
    {
        $empty = $this->directory . '/empty.xml';
        file_put_contents($empty, '<testsuites><testsuite name="empty"/></testsuites>');
        [$status] = $this->runMerge([$empty], 'junit.php');
        self::assertNotSame(0, $status);
        $foreign = $this->directory . '/foreign.xml';
        file_put_contents($foreign, '<testsuites><testsuite><testcase file="' . $empty . '" name="bad"/></testsuite></testsuites>');
        [$status, $output] = $this->runMerge([$foreign], 'junit.php');
        self::assertNotSame(0, $status);
        self::assertStringContainsString('Test result escaped project', $output);
    }

    /** Clover report with every per-file guard met and the given overall executed/total lines. */
    private function checkReport(int $executed, int $statements): string
    {
        $required = [
            'Fields/MatrixField', 'Fields/SignatureField', 'Fields/RecordingField', 'Fields/AudioField',
            'Fields/VideoField', 'Fields/ImageField', 'Fields/FileField', 'Uploads/IncomingFile',
            'Uploads/UploadStorage', 'Submission/SubmissionService', 'Frontend/TemplatePreview', 'Forms/Templates',
        ];
        $files = '';
        foreach ($required as $name) {
            $files .= '<file name="src/WordPress/' . $name . '.php"><line num="1" type="stmt" count="1"/></file>';
        }
        $executed -= count($required);
        $statements -= count($required);
        $lines = '';
        for ($line = 1; $line <= $statements; ++$line) {
            $lines .= '<line num="' . $line . '" type="stmt" count="' . ($line <= $executed ? 1 : 0) . '"/>';
        }
        $file = $this->directory . '/check-' . $executed . '-' . $statements . '.xml';
        file_put_contents($file, '<coverage><project>' . $files . '<file name="src/Rest.php">' . $lines . '</file></project></coverage>');
        return $file;
    }

    /** @return array{int, string} */
    private function runCheck(string $report): array
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/coverage/check.php', $report], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    public function testFullCoverageGateRequiresMoreThanEightyPercent(): void
    {
        [$status, $output] = $this->runCheck($this->checkReport(8000, 10000));
        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('8000/10000 (80.00%) must be more than 80%', $output);

        [$status, $output] = $this->runCheck($this->checkReport(8001, 10000));
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('Full PHP source: 8001/10000 lines (80.01%).', $output);
    }

    /** The catalog classes need real coverage as well, otherwise the gallery would ship untested. */
    public function testCoverageGateRequiresTheStarterTemplateClassesToBeCovered(): void
    {
        $file = static function (string $name, int $covered, int $total): string {
            $lines = '';
            for ($line = 1; $line <= $total; ++$line) {
                $lines .= '<line num="' . $line . '" type="stmt" count="' . ($line <= $covered ? 1 : 0) . '"/>';
            }
            return '<file name="src/' . $name . '.php">' . $lines . '</file>';
        };
        $report = '<coverage><project>';
        foreach (
            [
                'WordPress/Fields/MatrixField', 'WordPress/Fields/SignatureField', 'WordPress/Fields/RecordingField',
                'WordPress/Fields/AudioField', 'WordPress/Fields/VideoField', 'WordPress/Fields/ImageField',
                'WordPress/Fields/FileField', 'WordPress/Uploads/IncomingFile', 'WordPress/Uploads/UploadStorage',
                'WordPress/Submission/SubmissionService', 'WordPress/Forms/Templates',
            ] as $name
        ) {
            $report .= $file($name, 20, 20);
        }
        $report .= $file('WordPress/Frontend/TemplatePreview', 1, 20);
        $report .= '</project></coverage>';

        $path = $this->directory . '/check-template-catalog.xml';
        file_put_contents($path, $report);
        [$status, $output] = $this->runCheck($path);
        self::assertNotSame(0, $status, $output);
        self::assertStringContainsString('src/WordPress/Frontend/TemplatePreview.php: 5.00% covered; at least 95% required.', $output);
    }

    public function testFullCoverageGateRejectsEmptyReports(): void
    {
        $file = $this->directory . '/empty-check.xml';
        file_put_contents($file, '<coverage><project/></coverage>');
        [$status] = $this->runCheck($file);
        self::assertNotSame(0, $status);
    }
}
