<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Support;

use Exception;
use SimpleXMLElement;

final class LastFailedTestStore
{
    public function __construct(
        private readonly string $storePath,
    ) {}

    /**
     * @return list<string>
     */
    public function recordFromJunit(string $junitPath): array
    {
        $tests = $this->extractFailedTestsFromJunit($junitPath);
        $this->ensureDirectoryExists();

        file_put_contents($this->storePath, json_encode([
            'recorded_at' => date(DATE_ATOM),
            'tests' => $tests,
        ], JSON_PRETTY_PRINT));

        return $tests;
    }

    /**
     * @return list<string>
     */
    public function load(): array
    {
        if (!is_file($this->storePath)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->storePath), true);

        if (!is_array($data) || !isset($data['tests']) || !is_array($data['tests'])) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($test): ?string => is_string($test) && $test !== '' ? $test : null, $data['tests'])
        ));
    }

    public function buildPhpunitFilter(): ?string
    {
        $tests = $this->load();

        if ($tests === []) {
            return null;
        }

        $patterns = array_map(
            static fn(string $test): string => preg_quote($test, '/'),
            $tests,
        );

        return '/^(?:' . implode('|', $patterns) . ')$/';
    }

    /**
     * @return list<string>
     */
    public function extractFailedTestsFromJunit(string $junitPath): array
    {
        if (!is_file($junitPath)) {
            return [];
        }

        $xml = simplexml_load_file($junitPath);

        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }

        $tests = [];

        foreach ($xml->xpath('//testcase') ?: [] as $testcase) {
            if (!$testcase instanceof SimpleXMLElement) {
                continue;
            }

            if (!isset($testcase['class'], $testcase['name'])) {
                continue;
            }

            if (!isset($testcase->failure) && !isset($testcase->error)) {
                continue;
            }

            $tests[] = (string) $testcase['class'] . '::' . (string) $testcase['name'];
        }

        $tests = array_values(array_unique($tests));
        sort($tests);

        return $tests;
    }

    private function ensureDirectoryExists(): void
    {
        $dir = dirname($this->storePath);

        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new Exception("Could not create directory {$dir}");
        }
    }
}
