<?php

/** Run every standalone WordPress integration check, then the PHPUnit HTTP suite. */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$withCoverage = in_array('--coverage', $argv, true);
$directory = $root . '/coverage/wordpress';
if ($withCoverage) {
    if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
        throw new RuntimeException('Cannot create WordPress coverage directory.');
    }
    // Remove only reports from this runner, so repeated runs cannot import stale coverage.
    foreach (glob($directory . '/*.xml') ?: [] as $report) {
        unlink($report);
    }
    putenv('SWF_COVERAGE_DIR=' . $directory);
}
$scripts = ['wp-smoke.php', 'wp-field-types.php', 'wp-choice-fields.php', 'wp-input-options.php', 'wp-scalar-fields.php', 'wp-temporal-fields.php', 'wp-contact-fields.php', 'wp-file-uploads.php'];
foreach ($scripts as $script) {
    $command = [PHP_BINARY];
    if ($withCoverage) {
        array_push($command, '-d', 'auto_prepend_file=' . __DIR__ . '/bootstrap.php');
    }
    $command[] = $root . '/tests/' . $script;
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => STDERR], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start ' . $script);
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    echo $output;
    $marker = $script === 'wp-smoke.php' ? 'cleanup done' : 'PASS:';
    if ($status !== 0 || !str_contains((string) $output, $marker)) {
        throw new RuntimeException($script . ' did not complete successfully.');
    }
}
$command = [PHP_BINARY, $root . '/vendor/bin/phpunit', '-c', $root . '/phpunit-wordpress.xml.dist'];
if ($withCoverage) {
    array_push($command, '--coverage-clover', $directory . '/phpunit.xml', '--log-junit', $root . '/coverage/wordpress-junit.xml');
}
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start WordPress PHPUnit suite.');
}
exit(proc_close($process));
