<?php

declare(strict_types=1);

/**
 * @author Aaron Francis <aaron@tryhardstudios.com>
 *
 * @link https://aaronfrancis.com
 * @link https://x.com/aarondfrancis
 */

namespace SoloTerm\Screen\Tests\Support;

class InteractiveFixturePrompter
{
    public function __construct(
        private readonly TerminalEnvironment $env,
        private readonly VisualFixtureStore $store,
    ) {}

    public function promptAndSaveRenderFixture(string $output, string $fixturePath): bool
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════════════════════════════\n";
        echo "  No fixture exists for this test. We will show the rendered output.\n";
        echo "  After reviewing, press any key to continue.\n";
        echo "═══════════════════════════════════════════════════════════════════════════════\n";
        echo "  Press any key to show the output...\n";

        $this->env->waitForKeypress();

        $this->env->clearAndPrepare();

        echo $output;

        $this->env->waitForKeypress();

        $this->env->restoreTerminal();

        echo "\n";
        echo 'Does the output look correct? [Y/n] ';

        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);

        $confirmed = $input === '' || strtolower($input) === 'y';

        if ($confirmed) {
            $this->store->saveRenderFixture($fixturePath, $output);
            echo "Fixture saved to: {$fixturePath}\n";

            return true;
        }

        return false;
    }
}
