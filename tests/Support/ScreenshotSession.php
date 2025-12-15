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

class ScreenshotSession
{
    private array $debugLog = [];

    private ?string $captureToolPath = null;

    public function __construct(
        private readonly VisualTestConfig $config,
        private readonly TerminalEnvironment $env,
        private readonly string $screenshotBasePath,
    ) {}

    public function compare(array $terminalContent, string $emulatedOutput): ScreenshotResult
    {
        $terminalPath = $this->screenshotPath('terminal');
        $emulatedPath = $this->screenshotPath('emulated');
        $diffPath = $this->screenshotPath('diff');

        $this->ensureDirectoriesExist($terminalPath);
        $this->ensureCaptureToolExists();

        for ($attempt = 1; $attempt <= $this->config->maxAttempts; $attempt++) {
            $this->debugLog[] = "Attempt {$attempt}/{$this->config->maxAttempts}";

            try {
                $this->captureTerminalScreenshot($terminalPath, $terminalContent);
                $this->captureEmulatedScreenshot($emulatedPath, $emulatedOutput);

                $matched = $this->compareImages($terminalPath, $emulatedPath, $diffPath);

                if ($matched) {
                    return new ScreenshotResult(
                        matched: true,
                        terminalPath: $terminalPath,
                        emulatedPath: $emulatedPath,
                        diffPath: $diffPath,
                    );
                }

                $this->debugLog[] = "Attempt {$attempt} failed: images differ";

            } catch (Exception $e) {
                $this->debugLog[] = "Attempt {$attempt} exception: " . $e->getMessage();

                if ($attempt === $this->config->maxAttempts) {
                    throw $e;
                }
            }

            if ($attempt < $this->config->maxAttempts) {
                usleep($this->config->settleMs * 1000);
            }
        }

        return new ScreenshotResult(
            matched: false,
            terminalPath: $terminalPath,
            emulatedPath: $emulatedPath,
            diffPath: $diffPath,
            debugLog: implode("\n", $this->debugLog),
        );
    }

    private function captureTerminalScreenshot(string $filename, array $content): void
    {
        $this->env->clearAndPrepare();

        foreach ($content as $c) {
            echo $c;
            usleep(10_000); // 10ms for screen to update
        }

        // Small delay to ensure content is rendered
        usleep(50_000);

        $this->captureWindowScreenshot($filename);

        $this->env->restoreTerminal();
    }

    private function captureEmulatedScreenshot(string $filename, string $output): void
    {
        $this->env->clearAndPrepare();

        echo $output;
        usleep(10_000);

        // Small delay to ensure content is rendered
        usleep(50_000);

        $this->captureWindowScreenshot($filename);

        $this->env->restoreTerminal();
    }

    private function captureWindowScreenshot(string $filename): void
    {
        $terminal = $this->config->terminal;
        $cropTop = $this->config->titleBarHeight();

        $command = sprintf(
            '%s capture-terminal --terminal %s --output %s --crop-top %d 2>&1',
            escapeshellarg($this->captureToolPath),
            escapeshellarg($terminal),
            escapeshellarg($filename),
            $cropTop
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $result = implode("\n", $output);

        if ($exitCode !== 0) {
            throw new Exception("Screenshot capture failed (exit {$exitCode}): {$result}");
        }

        if (!str_starts_with($result, 'OK')) {
            throw new Exception("Screenshot capture returned unexpected output: {$result}");
        }

        $this->debugLog[] = "Captured: {$result}";
    }

    private function compareImages(string $terminalPath, string $emulatedPath, string $diffPath): bool
    {
        if (shell_exec('which compare') === null) {
            throw new Exception('The `compare` tool (ImageMagick) is not installed or not in PATH.');
        }

        $output = [];
        $exitCode = 0;
        exec(
            sprintf(
                'compare -metric AE %s %s %s 2>&1',
                escapeshellarg($terminalPath),
                escapeshellarg($emulatedPath),
                escapeshellarg($diffPath)
            ),
            $output,
            $exitCode
        );

        $result = trim(implode("\n", $output));
        $this->debugLog[] = "ImageMagick compare result: '{$result}', exit code: {$exitCode}";

        return $result === '0';
    }

    private function screenshotPath(string $suffix): string
    {
        return "{$this->screenshotBasePath}_{$suffix}.png";
    }

    private function ensureDirectoriesExist(string $path): void
    {
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new Exception("Could not create directory {$dir}");
        }
    }

    private function ensureCaptureToolExists(): void
    {
        $this->captureToolPath = __DIR__ . '/bin/capture-window';

        if (!file_exists($this->captureToolPath)) {
            // Try to compile from source
            $sourcePath = $this->captureToolPath . '.swift';

            if (!file_exists($sourcePath)) {
                throw new Exception(
                    "Screenshot capture tool not found at {$this->captureToolPath} " .
                    "and source not found at {$sourcePath}"
                );
            }

            $this->debugLog[] = 'Compiling capture tool from source...';

            $output = [];
            $exitCode = 0;
            exec(
                sprintf(
                    'swiftc -O -o %s %s 2>&1',
                    escapeshellarg($this->captureToolPath),
                    escapeshellarg($sourcePath)
                ),
                $output,
                $exitCode
            );

            if ($exitCode !== 0) {
                throw new Exception(
                    'Failed to compile capture tool: ' . implode("\n", $output)
                );
            }
        }

        if (!is_executable($this->captureToolPath)) {
            throw new Exception("Capture tool at {$this->captureToolPath} is not executable");
        }
    }
}
