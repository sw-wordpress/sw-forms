<?php

/** Coverage for real WordPress CLI/HTTP requests, including requests that call exit(). */

declare(strict_types=1);

$swfCoverageDirectory = getenv('SWF_COVERAGE_DIR');
if ($swfCoverageDirectory === false || $swfCoverageDirectory === '') {
    return;
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$swfCoverageFilter = new \SebastianBergmann\CodeCoverage\Filter();
foreach (['src', 'includes'] as $swfCoverageSource) {
    $swfCoverageIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/' . $swfCoverageSource, FilesystemIterator::SKIP_DOTS));
    foreach ($swfCoverageIterator as $swfCoverageFile) {
        if ($swfCoverageFile->getExtension() === 'php') {
            $swfCoverageFilter->includeFile($swfCoverageFile->getPathname());
        }
    }
}
$swfCoverageFilter->includeFile(dirname(__DIR__, 2) . '/sw-forms.php');
$swfCoverageFilter->includeFile(dirname(__DIR__, 2) . '/uninstall.php');
$swfCoverage = new \SebastianBergmann\CodeCoverage\CodeCoverage((new \SebastianBergmann\CodeCoverage\Driver\Selector())->forLineCoverage($swfCoverageFilter), $swfCoverageFilter);
$swfCoverage->start('wordpress-' . bin2hex(random_bytes(8)));
register_shutdown_function(static function () use ($swfCoverage, $swfCoverageDirectory): void {
    $swfCoverage->stop();
    $report = $swfCoverageDirectory . '/request-' . bin2hex(random_bytes(8)) . '.xml';
    (new \SebastianBergmann\CodeCoverage\Report\Clover())->process($swfCoverage, $report);
});
