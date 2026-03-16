<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Tests\Support\VisualFixtureStore;
use SoloTerm\Screen\Tests\Support\VisualTestConfig;

class VisualFixtureStoreTest extends TestCase
{
    #[Test]
    public function stable_terminal_fixture_function_is_content_derived(): void
    {
        $store = $this->makeStore();

        $this->assertSame(
            'test_method__dd1799297218',
            $store->stableTerminalFixtureFunction('test_method', ['abc'])
        );
    }

    #[Test]
    public function checksum_for_is_byte_safe_for_invalid_utf8_content(): void
    {
        $store = $this->makeStore();

        $invalid = $store->checksumFor(["A\xFFB"]);
        $replacement = $store->checksumFor(["A?B"]);

        $this->assertNotSame($invalid, $replacement);
        $this->assertSame($invalid, $store->checksumFor(["A\xFFB"]));
    }

    #[Test]
    public function load_terminal_fixture_accepts_the_new_byte_safe_checksum_format(): void
    {
        $store = $this->makeStore();
        $path = $this->tempFixturePath();
        $content = ["A\xFFB"];

        file_put_contents($path, json_encode([
            'checksum' => $store->checksumFor($content),
            'width' => 80,
            'height' => 24,
            'output' => 'fixture-output',
        ]));

        $fixture = $store->loadTerminalFixture($path, $content);

        $this->assertNotNull($fixture);
        $this->assertSame('fixture-output', $fixture->output);
    }

    #[Test]
    public function find_matching_terminal_fixture_falls_back_to_legacy_numbered_files(): void
    {
        $root = $this->tempDirectory();
        $store = $this->makeStore($root);
        $content = ['abc'];
        $dir = $root . '/ghostty/Unit/TestCase';

        mkdir($dir, 0777, true);
        file_put_contents($dir . '/sample_test_2.json', json_encode([
            'checksum' => md5(json_encode($content)),
            'width' => 80,
            'height' => 24,
            'output' => 'fixture-output',
        ]));

        $match = $store->findMatchingTerminalFixture('Unit/TestCase', 'sample_test', $content);

        $this->assertNotNull($match);
        $this->assertSame($dir . '/sample_test_2.json', $match['path']);
        $this->assertSame('fixture-output', $match['fixture']->output);
    }

    #[Test]
    public function load_terminal_fixture_accepts_legacy_json_checksums_for_backwards_compatibility(): void
    {
        $store = $this->makeStore();
        $path = $this->tempFixturePath();
        $content = ['abc'];

        file_put_contents($path, json_encode([
            'checksum' => md5(json_encode($content)),
            'width' => 80,
            'height' => 24,
            'output' => 'fixture-output',
        ]));

        $fixture = $store->loadTerminalFixture($path, $content);

        $this->assertNotNull($fixture);
        $this->assertSame('fixture-output', $fixture->output);
    }

    private function makeStore(?string $fixturesRoot = null): VisualFixtureStore
    {
        return new VisualFixtureStore(
            new VisualTestConfig(
                terminal: 'ghostty',
                requiredLines: 32,
                requiredColumns: 180,
                fixturesRoot: $fixturesRoot ?? 'tests/Fixtures',
                screenshotsRoot: 'tests/Screenshots',
                mode: VisualTestConfig::MODE_DISABLED,
            )
        );
    }

    private function tempFixturePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-store-');

        $this->assertNotFalse($path);
        $this->assertIsString($path);

        $this->addToAssertionCount(1);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    private function tempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/fixture-store-' . bin2hex(random_bytes(6));

        mkdir($path, 0777, true);

        register_shutdown_function(static function () use ($path): void {
            if (!is_dir($path)) {
                return;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }

            @rmdir($path);
        });

        return $path;
    }
}
