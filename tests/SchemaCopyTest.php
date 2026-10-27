<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/** The SDK's copy of the payload contract must be services/' (the Errors module's), file for file, when it is there. */
final class SchemaCopyTest extends TestCase
{
    public function test_the_schemas_are_the_services(): void
    {
        $source = dirname(__DIR__, 3).'/services/schemas/errors';

        if (! is_dir($source)) {
            $this->markTestSkipped('services/ is not two folders up from this SDK.');
        }

        $mine = $this->files(dirname(__DIR__).'/schemas');
        $theirs = $this->files($source);

        $this->assertSame(array_keys($theirs), array_keys($mine), 'The same files');

        foreach ($theirs as $path => $hash) {
            $this->assertSame($hash, $mine[$path], "{$path} differs from services/schemas/errors: copy it again.");
        }
    }

    /** @return array<string, string> relative path => sha1 */
    private function files(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = sha1_file($file->getPathname());
        }

        ksort($files);

        return $files;
    }
}
