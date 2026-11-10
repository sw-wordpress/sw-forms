<?php
/** Validate prospective release artifacts with the very same runtime verifiers. CLI only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || defined('ABSPATH')) {
    exit(1);
}
// The verifier only needs the WordPress error value object, no running WordPress.
final class WP_Error
{
    public function __construct(public string $code, public string $message) {}
    public function get_error_message(): string { return $this->message; }
}
require dirname(__DIR__) . '/includes/update/class-sw-forms-update-manifest.php';
require dirname(__DIR__) . '/includes/update/class-sw-forms-update-package.php';
try {
    if (count($argv) !== 4) {
        throw new RuntimeException('Usage: verify-release.php <release-json> <artifact-directory> <public-keyring>');
    }
    $release = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
    // The final artifacts are verified before publishing the draft.
    $release['draft'] = false;
    $directory = $argv[2];
    $keys = require $argv[3];
    $manifest = SWF_Update_Manifest::verify(file_get_contents($directory . '/sw-plugin-release.json'), file_get_contents($directory . '/sw-plugin-release.json.sig'), $keys, $release);
    if ($manifest instanceof WP_Error) {
        throw new RuntimeException($manifest->message);
    }
    foreach ($manifest['packages'] as $package) {
        $file = $directory . '/' . $package['filename'];
        $result = SWF_Update_Package::verify($file, $package);
        if ($result instanceof WP_Error) {
            throw new RuntimeException($result->message);
        }
        $zip = new ZipArchive();
        $zip->open($file);
        foreach (['vendor-prefixed/autoload.php', 'libraries/action-scheduler/action-scheduler.php', 'assets/admin/app.js', 'assets/admin/app.css', 'assets/frontend/form.js', 'assets/frontend/form.css', 'assets/js/sw-forms-updates.js', 'includes/update/class-sw-forms-update-provider.php'] as $required) {
            if (false === $zip->locateName($package['slug'] . '/' . $required)) {
                throw new RuntimeException('Incomplete release: ' . $required);
            }
        }
        $publicKeys = $zip->getFromName($package['slug'] . '/includes/update/trusted-keys.php');
        $envelope = json_decode(file_get_contents($directory . '/sw-plugin-release.json.sig'), true, 16, JSON_THROW_ON_ERROR);
        // Do not execute package PHP. A literal export of the build key must be present.
        if (!is_string($publicKeys) || !str_contains($publicKeys, var_export($envelope['key_id'], true)) || !str_contains($publicKeys, var_export($keys[$envelope['key_id']], true))) {
            throw new RuntimeException('Build public key missing from package.');
        }
        $zip->close();
    }
    echo "Verified release manifest, signature, package structure and completeness.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
