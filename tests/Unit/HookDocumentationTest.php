<?php

declare(strict_types=1);

namespace SWForms\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HookDocumentationTest extends TestCase
{
    public function testEveryPublicHookHasADocumentedContractAndIntroductionVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $documentation = (string) file_get_contents($root . '/docs/hooks.md');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/WordPress'));
        $hooks = [];
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                preg_match_all("/(?:do_action|apply_filters)\\(\\s*'(swf_[a-z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $matches);
                $hooks = array_merge($hooks, $matches[1]);
            }
        }
        foreach (array_unique($hooks) as $hook) {
            self::assertMatchesRegularExpression('/^\| `' . preg_quote($hook, '/') . '` \|.+\| 0\.\d+\.\d+ \|$/m', $documentation, $hook);
        }
        foreach (explode("\n", $documentation) as $row) {
            if (str_starts_with($row, '| `swf_')) {
                self::assertCount(6, explode('|', $row), 'Hook table must have four complete columns.');
            }
        }
    }
}
