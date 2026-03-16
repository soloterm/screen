<?php

declare(strict_types=1);

namespace SoloTerm\Screen\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SoloTerm\Screen\Tests\Support\ComparesVisually;
use SoloTerm\Screen\Tests\Support\TerminalEnvironment;
use SoloTerm\Screen\Tests\Support\TerminalFixture;
use SoloTerm\Screen\Tests\Support\VisualFixtureStore;
use SoloTerm\Screen\Tests\Support\VisualTestConfig;

class ComparesVisuallyHarnessTest extends TestCase
{
    #[Test]
    public function screenshot_mode_prefers_visual_comparison_even_when_a_matching_fixture_exists(): void
    {
        $config = $this->makeConfig(VisualTestConfig::MODE_ENABLED);
        $fixture = new TerminalFixture('checksum', 80, 24, 'fixture-output');
        $harness = new ComparesVisuallyHarness($config, $fixture, fixturesInSync: true, fixtureMatches: true);

        $harness->runAssertTerminalMatch('abc');

        $this->assertSame(['with-output', 'visual'], $harness->calls);
    }

    #[Test]
    public function missing_mode_prefers_visual_comparison_when_terminal_fixtures_are_out_of_sync(): void
    {
        $config = $this->makeConfig(VisualTestConfig::MODE_RECORD_MISSING);
        $fixture = new TerminalFixture('checksum', 80, 24, 'fixture-output');
        $harness = new ComparesVisuallyHarness($config, $fixture, fixturesInSync: false, fixtureMatches: true);

        $harness->runAssertTerminalMatch('abc');

        $this->assertSame(['with-output', 'visual'], $harness->calls);
    }

    #[Test]
    public function normal_mode_uses_the_fixture_fast_path_when_the_fixture_matches(): void
    {
        $config = $this->makeConfig(VisualTestConfig::MODE_DISABLED);
        $fixture = new TerminalFixture('checksum', 80, 24, 'fixture-output');
        $harness = new ComparesVisuallyHarness($config, $fixture, fixturesInSync: true, fixtureMatches: true);

        $harness->runAssertTerminalMatch('abc');

        $this->assertSame([], $harness->calls);
    }

    private function makeConfig(int $mode): VisualTestConfig
    {
        return new VisualTestConfig(
            terminal: 'ghostty',
            requiredLines: 32,
            requiredColumns: 180,
            fixturesRoot: 'tests/Fixtures',
            screenshotsRoot: 'tests/Screenshots',
            mode: $mode,
        );
    }
}

final class ComparesVisuallyHarness extends TestCase
{
    use ComparesVisually;

    public array $calls = [];

    private readonly HarnessFixtureStore $store;

    private readonly HarnessTerminalEnvironment $environment;

    public function __construct(
        private readonly VisualTestConfig $config,
        ?TerminalFixture $fixture,
        bool $fixturesInSync,
        private readonly bool $fixtureMatches,
    ) {
        parent::__construct('runTest');

        $this->store = new HarnessFixtureStore($config, $fixture, $fixturesInSync);
        $this->environment = new HarnessTerminalEnvironment($config, $this->calls);
    }

    public function runTest(): void
    {
    }

    public function runAssertTerminalMatch(array|string $content, bool $iterate = false): void
    {
        $this->assertTerminalMatch($content, $iterate);
    }

    protected function baseTestIdentifier(): array
    {
        return ['Unit/Harness', 'fixture_test'];
    }

    protected function visualConfig(): VisualTestConfig
    {
        return $this->config;
    }

    protected function terminalEnv(): TerminalEnvironment
    {
        return $this->environment;
    }

    protected function fixtureStore(): VisualFixtureStore
    {
        return $this->store;
    }

    protected function fixtureMatchesCurrentOutput(TerminalFixture $fixture, array $content): bool
    {
        return $this->fixtureMatches;
    }

    protected function assertVisualMatch(array $content): void
    {
        $this->calls[] = 'visual';
    }

    protected function assertFixtureMatch(array $content): void
    {
        $this->calls[] = 'fixture';
    }
}

final class HarnessFixtureStore extends VisualFixtureStore
{
    public function __construct(
        VisualTestConfig $config,
        private readonly ?TerminalFixture $fixture,
        private readonly bool $fixturesInSync,
    ) {
        parent::__construct($config);
    }

    public function loadTerminalFixture(string $fixturePath, array $content): ?TerminalFixture
    {
        return $this->fixture;
    }

    public function fixturesAreInSync(string $relativePath, string $function): bool
    {
        return $this->fixturesInSync;
    }

    public function terminalFixturesAreInSyncForContent(string $relativePath, string $baseFunction, array $content): bool
    {
        return $this->fixturesInSync;
    }
}

final class HarnessTerminalEnvironment extends TerminalEnvironment
{
    public function __construct(VisualTestConfig $config, private array &$calls)
    {
        parent::__construct($config);
    }

    public function withOutput(callable $callback): mixed
    {
        $this->calls[] = 'with-output';

        return $callback();
    }
}
