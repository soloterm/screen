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
        return md5(json_encode($content));
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

        return "{$this->config->fixturesRoot}/{$relativePath}/{$function}.json";
    }

    public function renderFixturePath(string $relativePath, string $function): string
    {
        $terminal = $this->config->terminal;

        if ($terminal) {
            return "{$this->config->fixturesRoot}/Renders/{$terminal}/{$relativePath}/{$function}.json";
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

        if ($data['checksum'] !== $this->checksumFor($content)) {
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
}
