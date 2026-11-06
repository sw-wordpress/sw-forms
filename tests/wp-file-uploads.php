<?php

/** Upload transport, validation boundaries, metadata and cleanup without trusting local paths. */

declare(strict_types=1);

define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
require(getenv('WP_ROOT') ?: dirname(__DIR__, 4)) . '/wp-load.php';
// Assertions below use source-language error messages.
switch_to_locale('en_US');

use SWForms\WordPress\Fields\FieldRegistry;
use SWForms\WordPress\Fields\FileField;
use SWForms\WordPress\Uploads\IncomingFile;
use SWForms\WordPress\Uploads\UploadStorage;
use SWForms\WordPress\Forms\FormService;
use SWForms\WordPress\Submission\SubmissionService;
use SWForms\WordPress\Entries\EntryPresenter;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    ++$checks;
};
$type = FieldRegistry::instance()->get('file');
$check($type instanceof FileField, 'Upload type registered');
$check($type->default_value([]) === [], 'No initial or retained file');
$s = $type->normalize_settings(['extensions' => '.PDF;PHP,svg,PNG,txt,pdf', 'maxFiles' => 999, 'maxSize' => 0]);
$check($s['extensions'] === 'pdf,png,txt' && $s['maxFiles'] === 20 && $s['maxSize'] === 1, 'Normalize allowlist and caps');
$check($type->normalize_settings(['extensions' => 'php,svg'])['extensions'] === '', 'Unsupported list fails closed');
$element = ['id' => 'file', 'type' => 'file', 'key' => 'file', 'label' => 'Upload', 'required' => true, 'settings' => $type->default_settings()];
$check($type->collect(['file' => ['/etc/passwd', ['tmp_name' => '/etc/passwd', 'name' => 'a.txt']]], $element) === [], 'Text cannot forge file objects');
$check($type->collect(['file' => new IncomingFile('', '', UPLOAD_ERR_NO_FILE)], $element) === [], 'No-file marker remains empty');
$raw = IncomingFile::merge(['rows' => [7 => ['title' => 'Keep']]], ['name' => ['rows' => [7 => ['file' => ['<b>.txt']]]], 'tmp_name' => ['rows' => [7 => ['file' => ['/tmp/no-upload']]]], 'error' => ['rows' => [7 => ['file' => [UPLOAD_ERR_OK]]]]]);
$check($raw['rows'][7]['title'] === 'Keep' && $raw['rows'][7]['file'][0] instanceof IncomingFile, 'Transposed nested sparse PHP files preserve scope');
$check($type->collect($raw['rows'][7], $element) === ['<b>.txt'], 'Condition value is the plain name list');
$check($type->validate_uploads($raw['rows'][7], $element)[0]['code'] === 'file_upload', 'Reject local files not received as HTTP uploads');
$check($type->validate(['a','b'], $element)[0]['code'] === 'max_files', 'Single selection enforced server side');
$element['settings']['multiple'] = true;
$element['settings']['maxFiles'] = 2;
$check($type->validate(['a','b'], $element) === [] && $type->validate(['a','b','c'], $element)[0]['code'] === 'max_files', 'Multiple count limit');
foreach ([UPLOAD_ERR_INI_SIZE => 'file_size', UPLOAD_ERR_FORM_SIZE => 'file_size', UPLOAD_ERR_PARTIAL => 'file_upload', UPLOAD_ERR_NO_TMP_DIR => 'file_upload', UPLOAD_ERR_CANT_WRITE => 'file_upload', UPLOAD_ERR_EXTENSION => 'file_upload'] as $code => $expected) {
    $check($type->validate_uploads(['file' => [new IncomingFile('test.txt', '', $code)]], $element)[0]['code'] === $expected, 'PHP upload error ' . $code);
}
$check($type->max_size($element) <= wp_max_upload_size(), 'Host size restrictions respected');
$check(IncomingFile::clean_name(" C:\\fakepath\\<b>\x00.txt ") === '<b>.txt', 'Plain basename and control removal');
$check(IncomingFile::clean_name("\u{00a0}name.txt\u{00a0}") === "\u{00a0}name.txt\u{00a0}", 'Non-ASCII filename spaces match the browser');
$nodes = (new EntryPresenter(FieldRegistry::instance()))->present([$element], ['file' => [['name' => '<b>.txt', 'url' => 'https://example.test/a.txt', 'size' => 2, 'type' => 'text/plain']]]);
$check(str_contains(EntryPresenter::to_html($nodes), '&lt;b&gt;.txt</a>'), 'HTML links escape file labels');
$bad = (new EntryPresenter(FieldRegistry::instance()))->present([$element], ['file' => [['name' => 'bad', 'url' => 'javascript:alert(1)']]]);
$check($bad[0]['files'] === [], 'Unsafe link schemes omitted');
$service = FormService::create_default();
$id = $service->create('Upload integration');
try {
    $doc = ['fields' => [$element], 'settings' => ['notifications' => [], 'storeEntries' => true]];
    $save = $service->save($id, 'Upload integration', $doc);
    $check($save['errors'] === [], 'Upload schema saves');
    $check(isset(swf_submit($id, ['file' => 'https://example.test/fake.txt'])->errors['file']), 'Forged raw upload fails required');
    $doc['fields'][0]['logic'] = ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'show', 'op' => 'notempty']]];
    $doc['fields'][] = ['id' => 'show', 'type' => 'checkbox', 'key' => 'show', 'settings' => ['mode' => 'single']];
    $service->save($id, 'Upload integration', $doc);
    $check(swf_submit($id, ['file' => [new IncomingFile('bad.txt', '/etc/passwd', UPLOAD_ERR_OK)]])->status === 'success', 'Hidden files skipped including validation');
    $html = swf_render_form($id);
    $check(str_contains($html, 'enctype="multipart/form-data"') && str_contains($html, 'name="swf[file][]"') && !str_contains($html, 'value="/etc/passwd"'), 'Multipart field with no retained local path');
    $doc['settings']['storeEntries'] = false;
    $service->save($id, 'Upload integration', $doc);
    $result = swf_submit($id, ['show' => '1', 'file' => [new IncomingFile('x.txt', '/etc/passwd', UPLOAD_ERR_OK)]]);
    $check(isset($result->errors['file']) && str_contains($result->errors['file'], 'entry storage'), 'Reject uploads without an owning entry');
    $previous = $_SERVER['CONTENT_LENGTH'] ?? null;
    $_SERVER['CONTENT_LENGTH'] = (string) (wp_convert_hr_to_bytes(ini_get('post_max_size')) + 1);
    $check(SubmissionService::create_default()->submit($id, [], ['trusted' => true])->code === 'upload_size', 'Oversized POST never succeeds with discarded fields');
    if (null === $previous) {
        unset($_SERVER['CONTENT_LENGTH']);
    } else {
        $_SERVER['CONTENT_LENGTH'] = $previous;
    }
    $testdir = sys_get_temp_dir() . '/swf-test-' . bin2hex(random_bytes(6));
    $directory = static function (array $dir) use ($testdir): array {
        $dir['basedir'] = $testdir;
        $dir['path'] = $testdir;
        return $dir;
    };
    add_filter('upload_dir', $directory);
    $uploads = wp_upload_dir();
    wp_mkdir_p($uploads['basedir'] . '/swforms');
    $owned = 'swf-' . bin2hex(random_bytes(16)) . '.txt';
    $keep = 'unowned-' . bin2hex(random_bytes(6)) . '.txt';
    $dir = $uploads['basedir'] . '/swforms/';
    file_put_contents($dir . $owned, 'owned');
    file_put_contents($dir . $keep, 'keep');
    UploadStorage::delete(['../' . $keep, $keep, $owned]);
    $check(!file_exists($dir . $owned) && file_exists($dir . $keep), 'Cleanup deletes generated basenames only');
    unlink($dir . $keep);
    $entries = new \SWForms\WordPress\Entries\EntryRepository();
    file_put_contents($dir . $owned, 'owned');
    $entryId = $entries->insert($id, 1, [], ['uploads' => [$owned]], [], '', 0);
    $entries->update($entryId, ['status' => 'trash']);
    $check(file_exists($dir . $owned), 'Trash keeps entry uploads');
    $entries->delete($entryId);
    $check(!file_exists($dir . $owned), 'Permanent entry deletion uses manifest independent of public data');
    file_put_contents($dir . $owned, 'owned');
    $entryId = $entries->insert($id, 1, [], ['uploads' => [$owned]], [], '', 0);
    global $wpdb;
    $wpdb->update(\SWForms\WordPress\Database\Schema::table('entries'), ['created_at' => gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS)], ['id' => $entryId]);
    $check($entries->purge_older_than($id, 1) === 1 && !file_exists($dir . $owned), 'Retention removes owned files');
    file_put_contents($dir . $owned, 'owned');
    $entries->insert($id, 1, [], ['uploads' => [$owned]], [], '', 0);
    $service->repository()->delete($id);
    $check(!file_exists($dir . $owned), 'Deleting a form removes entry uploads');
    remove_filter('upload_dir', $directory);
    rmdir($dir);
    rmdir($testdir);
} finally {
    $service->repository()->delete($id);
}
echo "PASS: $checks upload integration checks.\n";
