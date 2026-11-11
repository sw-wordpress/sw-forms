<?php

/** Test already built real release ZIPs with a disposable key; never changes the source keyring. */

declare(strict_types=1);

$build = $argv[1] ?? throw new InvalidArgumentException('Expected disposable build checkout');
$dist = $argv[2] ?? throw new InvalidArgumentException('Expected disposable artifact directory');
$root = dirname(__DIR__);
if (!str_starts_with(realpath($build) ?: '', '/tmp/')) {
    throw new RuntimeException('Build fixture must be a disposable checkout under /tmp.');
}
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$keys = ['disposable-build' => base64_encode(sodium_crypto_sign_publickey($pair))];
file_put_contents($build . '/includes/update/trusted-keys.php', '<?php return ' . var_export($keys, true) . ';');
$run = static function (array $args, ?array $environment = null, int $expected = 0): void {
    $process = proc_open($args, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $environment);
    if (!is_resource($process) || proc_close($process) !== $expected) {
        throw new RuntimeException('Build validation command failed.');
    }
};
$run(['bash', $build . '/bin/build-zip.sh', $dist]);
$files = glob($dist . '/sw-forms-*.zip');
if (count($files) !== 1) {
    throw new RuntimeException('Exactly one plugin ZIP required.');
}
$file = $files[0];
$version = substr(basename($file), strlen('sw-forms-'), -4);
$release = ['id' => 9001, 'tag_name' => 'v' . $version, 'draft' => true, 'prerelease' => false, 'assets' => [['id' => 9002, 'name' => basename($file), 'size' => filesize($file)]]];
file_put_contents($dist . '/release.json', json_encode($release, JSON_THROW_ON_ERROR));
$command = [PHP_BINARY, $root . '/bin/release-manifest.php', 'sign', $dist . '/release.json', $dist, str_repeat('a', 40)];
$run($command, [], 1);
$run($command, ['SW_PLUGIN_RELEASE_SIGNING_KEY' => base64_encode($secret), 'SW_PLUGIN_RELEASE_KEY_ID' => 'disposable-build']);
$run([PHP_BINARY, $root . '/bin/verify-release.php', $dist . '/release.json', $dist, $build . '/includes/update/trusted-keys.php']);
$zip = new ZipArchive();
$zip->open($file);
for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = $zip->getNameIndex($index);
    if (preg_match('~^sw-forms/(?:\\.|tests/|vendor/|apps/|bin/|coverage/|node_modules/)~', $name)) {
        throw new RuntimeException('Unexpected development or hidden files in release.');
    }
    if (!str_ends_with($name, '/') && str_contains($zip->getFromIndex($index), base64_encode($secret))) {
        throw new RuntimeException('Private signing material in release.');
    }
}
$zip->close();
sodium_memzero($secret);
sodium_memzero($pair);
echo "PASS: real release ZIP signed and verified; missing secret blocked; package complete and clean.\n";

$run(['bash', $build . '/bin/build-zip.sh', $dist . '/store', 'wordpress-org']);
$store = new ZipArchive();
$store->open($dist . '/store/' . basename($file));
$main = $store->getFromName('sw-forms/sw-forms.php');
if (str_contains($main, 'Update URI:') || str_contains($main, 'SWF_Update_Provider') || false !== $store->locateName('sw-forms/includes/update/source.php') || false !== $store->locateName('sw-forms/assets/js/sw-forms-updates.js')) {
    throw new RuntimeException('WordPress.org build still contains the GitHub provider.');
}
$store->close();
if (!str_contains(file_get_contents($build . '/sw-forms.php'), 'Update URI:')) {
    throw new RuntimeException('Store build modified the source checkout.');
}
echo "PASS: WordPress.org package omits external provider/Update URI; source unchanged.\n";
