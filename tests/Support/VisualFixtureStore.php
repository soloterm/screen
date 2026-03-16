<?php

declare(strict_types=1);

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Support;

use Exception;

class VisualFixtureStore
{
    public function __construct(
        private readonly VisualTestConfig $config,
    ) {}

    public function checksumFor(array $content): string
    {
        $context = hash_init('md5');

        foreach ($content as $chunk) {
            $chunk = (string) $chunk;
            hash_update($context, pack('N', strlen($chunk)));
            hash_update($context, $chunk);
        }

        return hash_final($context);
    }

    public function terminalFixturePath(string $relativePath, string $function): string
    {
        $terminal = $this->config->terminal;

        if ($terminal && ($this->config->screenshotTestingEnabled() || $this->config->recordMissingFixtures())) {
            return "{$this->config->fixturesRoot}/{$terminal}/{$relativePath}/{$function}.json";
        }

        if ($terminal) {
            $terminalPath = "{$this->config->fixturesRoot}/{$terminal}/{$relativePath}/{$function}.json";
            if (file_exists($terminalPath)) {
                return $terminalPath;
            }
        }

        // In CI (no terminal), prefer iTerm fixtures since we assert iTerm and Ghostty are identical
        $itermPath = "{$this->config->fixturesRoot}/iterm/{$relativePath}/{$function}.json";
        if (file_exists($itermPath)) {
            return $itermPath;
        }

        return "{$this->config->fixturesRoot}/{$relativePath}/{$function}.json";
    }

    public function stableTerminalFixtureFunction(string $baseFunction, array $content): string
    {
        return "{$baseFunction}__" . substr($this->checksumFor($content), 0, 12);
    }

    /**
     * @return array{path: string, fixture: TerminalFixture}|null
     */
    public function findMatchingTerminalFixture(string $relativePath, string $baseFunction, array $content): ?array
    {
        foreach ($this->terminalFixtureCandidates($relativePath, $baseFunction, $content) as $fixturePath) {
            $fixture = $this->loadTerminalFixture($fixturePath, $content);

            if ($fixture !== null) {
                return ['path' => $fixturePath, 'fixture' => $fixture];
            }
        }

        return null;
    }

    public function renderFixturePath(string $relativePath, string $function): string
    {
        $terminal = $this->config->terminal;

        if ($terminal) {
            return "{$this->config->fixturesRoot}/Renders/{$terminal}/{$relativePath}/{$function}.json";
        }

        // In CI (no terminal), prefer iTerm fixtures since we assert iTerm and Ghostty are identical
        $itermPath = "{$this->config->fixturesRoot}/Renders/iterm/{$relativePath}/{$function}.json";
        if (file_exists($itermPath)) {
            return $itermPath;
        }

        return "{$this->config->fixturesRoot}/Renders/{$relativePath}/{$function}.json";
    }

    public function screenshotBasePath(string $relativePath, string $function): string
    {
        $terminal = $this->config->terminalDisplayName();

        return "{$this->config->screenshotsRoot}/{$terminal}/{$relativePath}/{$function}";
    }

    public function loadTerminalFixture(string $fixturePath, array $content): ?TerminalFixture
    {
        if (!file_exists($fixturePath)) {
            return null;
        }

        $data = json_decode(file_get_contents($fixturePath), true);

        if (!is_array($data) || !isset($data['checksum'], $data['width'], $data['height'], $data['output'])) {
            return null;
        }

        if (!$this->checksumMatches($data['checksum'], $content)) {
            return null;
        }

        return TerminalFixture::fromArray($data);
    }

    public function saveTerminalFixture(string $fixturePath, TerminalFixture $fixture): void
    {
        $this->ensureDirectoriesExist($fixturePath);

        file_put_contents($fixturePath, json_encode($fixture->toArray()));
    }

    public function loadRenderFixture(string $fixturePath): ?RenderFixture
    {
        if (!file_exists($fixturePath)) {
            return null;
        }

        $data = json_decode(file_get_contents($fixturePath), true);

        return RenderFixture::fromArray($data);
    }

    public function saveRenderFixture(string $fixturePath, string $output): void
    {
        $this->ensureDirectoriesExist($fixturePath);

        file_put_contents($fixturePath, json_encode([
            'output' => $output,
        ], JSON_PRETTY_PRINT));
    }

    public function fixturesAreInSync(string $relativePath, string $function): bool
    {
        $itermPath = "{$this->config->fixturesRoot}/iterm/{$relativePath}/{$function}.json";
        $ghosttyPath = "{$this->config->fixturesRoot}/ghostty/{$relativePath}/{$function}.json";

        if (!file_exists($itermPath) || !file_exists($ghosttyPath)) {
            return false;
        }

        return file_get_contents($itermPath) === file_get_contents($ghosttyPath);
    }

    public function terminalFixturesAreInSyncForContent(string $relativePath, string $baseFunction, array $content): bool
    {
        $iterm = $this->findMatchingTerminalFixtureForTerminal('iterm', $relativePath, $baseFunction, $content);
        $ghostty = $this->findMatchingTerminalFixtureForTerminal('ghostty', $relativePath, $baseFunction, $content);

        if ($iterm === null || $ghostty === null) {
            return false;
        }

        return file_get_contents($iterm['path']) === file_get_contents($ghostty['path']);
    }

    public function renderFixturesAreInSync(string $relativePath, string $function): bool
    {
        $itermPath = "{$this->config->fixturesRoot}/Renders/iterm/{$relativePath}/{$function}.json";
        $ghosttyPath = "{$this->config->fixturesRoot}/Renders/ghostty/{$relativePath}/{$function}.json";

        if (!file_exists($itermPath) || !file_exists($ghosttyPath)) {
            return false;
        }

        return file_get_contents($itermPath) === file_get_contents($ghosttyPath);
    }

    private function ensureDirectoriesExist(string $path): void
    {
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new Exception("Could not create directory {$dir}");
        }
    }

    /**
     * @return list<string>
     */
    private function terminalFixtureCandidates(string $relativePath, string $baseFunction, array $content): array
    {
        $paths = [];

        foreach ($this->candidateTerminals() as $terminal) {
            $paths[] = $this->terminalFixturePathForTerminal(
                $terminal,
                $relativePath,
                $this->stableTerminalFixtureFunction($baseFunction, $content)
            );

            foreach ($this->legacyTerminalFixturePaths($terminal, $relativePath, $baseFunction) as $legacyPath) {
                $paths[] = $legacyPath;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array{path: string, fixture: TerminalFixture}|null
     */
    private function findMatchingTerminalFixtureForTerminal(
        string $terminal,
        string $relativePath,
        string $baseFunction,
        array $content
    ): ?array {
        $paths = [
            $this->terminalFixturePathForTerminal(
                $terminal,
                $relativePath,
                $this->stableTerminalFixtureFunction($baseFunction, $content)
            ),
            ...$this->legacyTerminalFixturePaths($terminal, $relativePath, $baseFunction),
        ];

        foreach (array_values(array_unique($paths)) as $fixturePath) {
            $fixture = $this->loadTerminalFixture($fixturePath, $content);

            if ($fixture !== null) {
                return ['path' => $fixturePath, 'fixture' => $fixture];
            }
        }

        return null;
    }

    /**
     * @return list<?string>
     */
    private function candidateTerminals(): array
    {
        $candidates = [];

        if ($this->config->terminal !== null) {
            $candidates[] = $this->config->terminal;
        }

        $candidates[] = 'iterm';
        $candidates[] = null;

        $seen = [];
        $normalized = [];

        foreach ($candidates as $candidate) {
            $key = $candidate ?? '__root__';

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $candidate;
        }

        return $normalized;
    }

    private function terminalFixturePathForTerminal(?string $terminal, string $relativePath, string $function): string
    {
        if ($terminal === null) {
            return "{$this->config->fixturesRoot}/{$relativePath}/{$function}.json";
        }

        return "{$this->config->fixturesRoot}/{$terminal}/{$relativePath}/{$function}.json";
    }

    /**
     * @return list<string>
     */
    private function legacyTerminalFixturePaths(?string $terminal, string $relativePath, string $baseFunction): array
    {
        $dir = $terminal === null
            ? "{$this->config->fixturesRoot}/{$relativePath}"
            : "{$this->config->fixturesRoot}/{$terminal}/{$relativePath}";

        if (!is_dir($dir)) {
            return [];
        }

        $paths = glob($dir . '/' . $baseFunction . '_*.json');

        if ($paths === false) {
            return [];
        }

        sort($paths);

        return array_values($paths);
    }

    private function checksumMatches(string $checksum, array $content): bool
    {
        if (hash_equals($checksum, $this->checksumFor($content))) {
            return true;
        }

        $legacy = $this->legacyChecksumFor($content);

        return $legacy !== null && hash_equals($checksum, $legacy);
    }

    private function legacyChecksumFor(array $content): ?string
    {
        $json = json_encode($content);

        if ($json === false) {
            return null;
        }

        return md5($json);
    }
}
