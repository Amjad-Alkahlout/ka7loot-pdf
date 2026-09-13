<?php

namespace App\Services\System;

use RuntimeException;
use Symfony\Component\Process\Process;

class SubprocessExecutorService
{
    /**
     * Run an external command safely using an array of arguments (preventing shell injection).
     *
     * @param array<string> $command
     * @param int|null $timeoutSeconds
     * @param string|null $workingDirectory
     * @return array{output: string, error_output: string, exit_code: int}
     * @throws RuntimeException
     */
    public function execute(
        array $command,
        ?int $timeoutSeconds = null,
        ?string $workingDirectory = null
    ): array {
        $timeout = $timeoutSeconds ?? config('fileprocessor.timeouts.cli_process_seconds', 60);

        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeout);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new RuntimeException("Subprocess execution failed: {$e->getMessage()}", 0, $e);
        }

        if (!$process->isSuccessful()) {
            $binary = basename($command[0] ?? 'binary');
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new RuntimeException("Command [{$binary}] failed with exit code {$process->getExitCode()}: {$err}");
        }

        return [
            'output'       => $process->getOutput(),
            'error_output' => $process->getErrorOutput(),
            'exit_code'    => (int) $process->getExitCode(),
        ];
    }
}
