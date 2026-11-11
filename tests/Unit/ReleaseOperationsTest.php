<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleaseOperationsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/swf-operations-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/plugin/includes/update', 0700, true);
        copy(dirname(__DIR__, 2) . '/includes/update/trusted-keys.php', $this->directory . '/plugin/includes/update/trusted-keys.php');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    private function runCommand(array $command, ?array $environment = null): int
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process);
    }

    public function testDocumentedKeyCommandsAreSafeAndCopyable(): void
    {
        $root = dirname(__DIR__, 2);
        $manual = file_get_contents($root . '/docs/GITHUB-UPDATES-BETRIEB.md');
        preg_match_all('/```bash\n(.*?)\n```/s', $manual, $matches);
        self::assertCount(3, $matches[1]);
        $blocks = array_map(fn ($block) => str_replace([
            '$HOME/.local/share/sw-forms-release-keys-2026-11-next',
            '/var/www/html/wordpress_dev/wp-content/plugins/sw-forms',
        ], [$this->directory . '/keys', $this->directory . '/plugin'], $block), $matches[1]);
        // Replace only read-only diff; no temporary Git repository is needed.
        $blocks[1] = preg_replace('/  git -C .*? diff -- includes\/update\/trusted-keys.php/', '  true', $blocks[1]);
        self::assertSame(0, $this->runCommand(['bash', '-c', $blocks[0]]));
        self::assertSame(0700, fileperms($this->directory . '/keys') & 0777);
        foreach (['signing-key.base64', 'public-keys.php', 'key-id.txt'] as $file) {
            self::assertSame(0600, fileperms($this->directory . '/keys/' . $file) & 0777);
        }
        $secretHash = hash_file('sha256', $this->directory . '/keys/signing-key.base64');
        self::assertNotSame(0, $this->runCommand(['zsh', '-c', $blocks[0]]));
        self::assertSame($secretHash, hash_file('sha256', $this->directory . '/keys/signing-key.base64'));
        self::assertSame(0, $this->runCommand(['bash', '-c', $blocks[1]]));
        self::assertSame(0, $this->runCommand(['zsh', '-c', $blocks[1]]));
        $keys = require $this->directory . '/plugin/includes/update/trusted-keys.php';
        $existing = require $root . '/includes/update/trusted-keys.php';
        foreach ($existing as $id => $public) {
            self::assertSame($public, $keys[$id]);
        }
        self::assertCount(count($existing) + 1, $keys);
        file_put_contents($this->directory . '/keys/public-keys.php', '<?php return ' . var_export(['sw-forms-2026-11-next' => base64_encode(random_bytes(32))], true) . ';');
        self::assertNotSame(0, $this->runCommand(['bash', '-c', $blocks[1]]));
        self::assertSame($keys, require $this->directory . '/plugin/includes/update/trusted-keys.php');
        // A fake gh consumes the private key via stdin, checking shape without logging it.
        mkdir($this->directory . '/cli', 0700);
        $stub = <<<'SH'
#!/usr/bin/env bash
set -eu
if [ "$1 $2" = 'secret set' ]; then
  read -r key
  test ${#key} -eq 88
  test "$3" = SW_PLUGIN_RELEASE_SIGNING_KEY
fi
SH;
        file_put_contents($this->directory . '/cli/gh', $stub);
        chmod($this->directory . '/cli/gh', 0700);
        $environment = ['PATH' => $this->directory . '/cli:' . getenv('PATH')];
        self::assertSame(0, $this->runCommand(['bash', '-c', $blocks[2]], $environment));
        self::assertSame(0, $this->runCommand(['zsh', '-c', $blocks[2]], $environment));
    }

    public function testSignerFailsWithoutSecretAndPreservesKeyringOnConflict(): void
    {
        $signer = dirname(__DIR__, 2) . '/bin/release-manifest.php';
        $target = $this->directory . '/plugin/includes/update/trusted-keys.php';
        self::assertSame(1, $this->runCommand([PHP_BINARY, $signer, 'public-key', $target], []));
        $pair = sodium_crypto_sign_keypair();
        $environment = ['SW_PLUGIN_RELEASE_SIGNING_KEY' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'SW_PLUGIN_RELEASE_KEY_ID' => 'test'];
        self::assertSame(0, $this->runCommand([PHP_BINARY, $signer, 'public-key', $target], $environment));
        $before = file_get_contents($target);
        self::assertSame(0, $this->runCommand([PHP_BINARY, $signer, 'public-key', $target], $environment));
        $environment['SW_PLUGIN_RELEASE_SIGNING_KEY'] = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        self::assertSame(1, $this->runCommand([PHP_BINARY, $signer, 'public-key', $target], $environment));
        self::assertSame($before, file_get_contents($target));
    }
    public function testForbiddenContextsNeverReadUpdateStateOrRequestHttp(): void
    {
        $script = <<<'PHP'
class WP_Error { public function __construct(...$args) {} }
function __($text, $domain) { return $text; }
function is_admin() { return $GLOBALS['context'] !== 'frontend'; }
function is_multisite() { return false; }
function wp_doing_cron() { return $GLOBALS['context'] === 'cron'; }
function current_user_can($cap) { return true; }
function wp_is_file_mod_allowed($context) { return true; }
$GLOBALS['context'] = $argv[1];
if ($argv[1] === 'rest') define('REST_REQUEST', true);
if ($argv[1] === 'cli') define('WP_CLI', true);
require $argv[2];
// No HTTP/state stubs: touching either would fail this isolated subprocess.
if (!(SWF_Update_Service::check(true) instanceof WP_Error)) exit(1);
if (!(SWF_Update_Service::download('https://github.com/sw-wordpress/sw-forms/releases/download/v9.0.0/sw-forms-9.0.0.zip', ['slug'=>'sw-forms','version'=>'1.0.0']) instanceof WP_Error)) exit(2);
PHP;
        foreach (['frontend', 'cron', 'rest', 'cli'] as $context) {
            self::assertSame(0, $this->runCommand([PHP_BINARY, '-r', $script, $context, dirname(__DIR__, 2) . '/includes/update/class-sw-forms-update-service.php']));
        }
    }
}
