<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Tests\Support\LastFailedTestStore;

class LastFailedTestStoreTest extends TestCase
{
    private string $storePath;

    private string $junitPath;

    protected function setUp(): void
    {
        $this->storePath = tempnam(sys_get_temp_dir(), 'soloterm-last-failed-store-') ?: '';
        $this->junitPath = tempnam(sys_get_temp_dir(), 'soloterm-last-failed-junit-') ?: '';
    }

    protected function tearDown(): void
    {
        if ($this->storePath !== '' && is_file($this->storePath)) {
            @unlink($this->storePath);
        }

        if ($this->junitPath !== '' && is_file($this->junitPath)) {
            @unlink($this->junitPath);
        }
    }

    #[Test]
    public function it_records_only_failures_and_errors_from_junit(): void
    {
        file_put_contents($this->junitPath, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="suite">
    <testcase class="Foo\BarTest" name="passes" />
    <testcase class="Foo\BarTest" name="fails">
      <failure />
    </testcase>
    <testcase class="Foo\BazTest" name="errors">
      <error />
    </testcase>
  </testsuite>
</testsuites>
XML);

        $store = new LastFailedTestStore($this->storePath);
        $recorded = $store->recordFromJunit($this->junitPath);

        $this->assertSame([
            'Foo\BarTest::fails',
            'Foo\BazTest::errors',
        ], $recorded);
        $this->assertSame($recorded, $store->load());
    }

    #[Test]
    public function it_builds_an_exact_phpunit_filter_for_the_recorded_failures(): void
    {
        file_put_contents($this->storePath, json_encode([
            'tests' => [
                'Foo\BarTest::fails',
                'Foo\BazTest::errors',
            ],
        ]));

        $store = new LastFailedTestStore($this->storePath);

        $this->assertSame(
            '/^(?:Foo\\\\BarTest\:\:fails|Foo\\\\BazTest\:\:errors)$/',
            $store->buildPhpunitFilter()
        );
    }

    #[Test]
    public function it_returns_null_when_no_failed_tests_are_recorded(): void
    {
        file_put_contents($this->storePath, json_encode(['tests' => []]));

        $store = new LastFailedTestStore($this->storePath);

        $this->assertNull($store->buildPhpunitFilter());
    }
}
