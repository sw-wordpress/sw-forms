<?php

/** Combine PHPUnit suites and normalize file attributes for a separate scanner checkout. */

declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/';
$merged = new DOMDocument('1.0', 'UTF-8');
$merged->formatOutput = true;
$suites = $merged->appendChild($merged->createElement('testsuites'));
$reports = array_slice($argv, 1);
$output = $root . 'coverage/junit.xml';
if (($reports[0] ?? '') === '--output') {
    $output = $reports[1] ?? throw new InvalidArgumentException('Missing output filename.');
    $reports = array_slice($reports, 2);
}
if ($reports === []) {
    throw new InvalidArgumentException('Provide PHPUnit JUnit reports.');
}
foreach ($reports as $path) {
    $report = new DOMDocument();
    if (!$report->load($path, LIBXML_NONET)) {
        throw new RuntimeException('Invalid JUnit report: ' . $path);
    }
    foreach ((new DOMXPath($report))->query('//*[@file]') as $node) {
        $file = realpath($node->getAttribute('file'));
        if ($file === false || !str_starts_with($file, $root)) {
            throw new RuntimeException('Test result escaped project.');
        }
        $node->setAttribute('file', substr($file, strlen($root)));
    }
    foreach ($report->documentElement->childNodes as $suite) {
        if ($suite instanceof DOMElement && $suite->tagName === 'testsuite') {
            $suites->appendChild($merged->importNode($suite, true));
        }
    }
}
if ($merged->getElementsByTagName('testcase')->length === 0) {
    throw new RuntimeException('No PHPUnit test suites to import.');
}
if ($merged->save($output) === false) {
    throw new RuntimeException('Cannot save merged JUnit report.');
}
echo "Saved portable PHPUnit test execution to coverage/junit.xml\n";
