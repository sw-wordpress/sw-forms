<?php

/** A green coverage job must include executed adapter code, not just the portable core. */

declare(strict_types=1);

$report = new DOMDocument();
if (!$report->load(dirname(__DIR__, 2) . '/coverage/clover.xml', LIBXML_NONET)) {
    throw new RuntimeException('Missing or invalid merged coverage report.');
}
$coverage = [];
$statements = 0;
$executed = 0;
foreach ($report->getElementsByTagName('file') as $file) {
    $lines = 0;
    $hits = 0;
    foreach ($file->getElementsByTagName('line') as $line) {
        if ($line->getAttribute('type') === 'stmt') {
            ++$lines;
            $hits += (int) $line->getAttribute('count') > 0 ? 1 : 0;
        }
    }
    $coverage[$file->getAttribute('name')] = $lines > 0 ? 100 * $hits / $lines : 100;
    $statements += $lines;
    $executed += $hits;
}
$required = [
    'src/WordPress/Fields/MatrixField.php' => 95,
    'src/WordPress/Fields/SignatureField.php' => 95,
    'src/WordPress/Fields/RecordingField.php' => 95,
    'src/WordPress/Fields/AudioField.php' => 100,
    'src/WordPress/Fields/VideoField.php' => 100,
    'src/WordPress/Fields/ImageField.php' => 95,
    'src/WordPress/Fields/FileField.php' => 95,
    'src/WordPress/Uploads/IncomingFile.php' => 95,
    'src/WordPress/Uploads/UploadStorage.php' => 95,
    'src/WordPress/Submission/SubmissionService.php' => 85,
];
foreach ($required as $name => $minimum) {
    if (!isset($coverage[$name]) || $coverage[$name] < $minimum) {
        throw new RuntimeException(sprintf('%s: %.2f%% covered; at least %d%% required. Check that real HTTP coverage was merged.', $name, $coverage[$name] ?? 0, $minimum));
    }
    printf("%s: %.2f%% lines\n", $name, $coverage[$name]);
}
$total = $statements > 0 ? 100 * $executed / $statements : 0;
if ($total < 80) {
    throw new RuntimeException(sprintf('Full PHP source coverage %.2f%% is below 80%%.', $total));
}
printf("Full PHP source: %d/%d lines (%.2f%%).\n", $executed, $statements, $total);
