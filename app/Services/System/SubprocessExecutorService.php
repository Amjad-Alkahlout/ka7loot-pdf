<?php

namespace App\Services\System;

use App\Services\File\FileStorageManager;
use RuntimeException;
use Symfony\Component\Process\Process;

class SubprocessExecutorService
{
    public function __construct(
        protected ?FileStorageManager $storage = null
    ) {}

    /**
     * Run an external command safely using an array of arguments (preventing shell injection).
     *
     * @param array<string> $command
     * @param int|null $timeoutSeconds
     * @param string|null $workingDirectory
     * @param array<string, string|null>|null $environment
     * @return array{output: string, error_output: string, exit_code: int}
     * @throws RuntimeException
     */
    public function execute(
        array $command,
        ?int $timeoutSeconds = null,
        ?string $workingDirectory = null,
        ?array $environment = null
    ): array {
        $timeout = $timeoutSeconds ?? config('fileprocessor.timeouts.cli_process_seconds', 60);

        // Resolve and guarantee safe writable TEMP/TMP and essential system variables
        $env = $this->resolveEnvironment($environment, $workingDirectory);

        $process = new Process($command, $workingDirectory, $env);
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

    /**
     * Resolve environment variables, ensuring valid system variables and writable TEMP/TMP paths.
     *
     * @param array<string, string|null>|null $environment
     * @param string|null $workingDirectory
     * @return array<string, string|null>
     */
    protected function resolveEnvironment(?array $environment, ?string $workingDirectory): array
    {
        $env = $environment ?? [];

        // Guarantee essential OS system variables so runtimes like Node.js, OpenSSL, Ghostscript can initialize
        if (PHP_OS_FAMILY === 'Windows') {
            $winKeys = [
                'SystemRoot',
                'windir',
                'SystemDrive',
                'PATH',
                'PATHEXT',
                'ComSpec',
                'USERPROFILE',
                'LOCALAPPDATA',
                'APPDATA',
                'HOMEDRIVE',
                'HOMEPATH',
                'ALLUSERSPROFILE',
                'ProgramData',
                'ProgramFiles',
                'ProgramFiles(x86)',
            ];
            foreach ($winKeys as $key) {
                if (!isset($env[$key])) {
                    $val = getenv($key) ?: ($_SERVER[$key] ?? null);
                    if ($val !== null && $val !== '') {
                        $env[$key] = (string) $val;
                    }
                }
            }

            // Safe fallback for SystemRoot if somehow missing from ambient env
            if (empty($env['SystemRoot'])) {
                $env['SystemRoot'] = is_dir('C:\\Windows') ? 'C:\\Windows' : 'C:\\WINDOWS';
            }
            if (empty($env['windir'])) {
                $env['windir'] = $env['SystemRoot'];
            }
        } else {
            $posixKeys = ['PATH', 'HOME', 'USER', 'SHELL'];
            foreach ($posixKeys as $key) {
                if (!isset($env[$key])) {
                    $val = getenv($key) ?: ($_SERVER[$key] ?? null);
                    if ($val !== null && $val !== '') {
                        $env[$key] = (string) $val;
                    }
                }
            }
        }

        // Check if TEMP/TMP are already defined in passed env and point to a writable directory
        $hasExplicitWritableTemp = !empty($env['TEMP']) && is_dir($env['TEMP']) && is_writable($env['TEMP']);

        if (!$hasExplicitWritableTemp) {
            // Check if ambient system TEMP/TMP is valid and writable
            $sysTemp = getenv('TEMP') ?: (getenv('TMP') ?: ($_SERVER['TEMP'] ?? ($_SERVER['TMP'] ?? null)));
            $isSysTempWritable = $sysTemp && is_dir($sysTemp) && is_writable($sysTemp);

            if (!$isSysTempWritable) {
                // Controlled fallback within the application's secure storage architecture
                $safeTemp = null;
                if ($workingDirectory && is_dir($workingDirectory) && is_writable($workingDirectory)) {
                    $safeTemp = $workingDirectory;
                } elseif ($this->storage) {
                    $safeTemp = $this->storage->getSubprocessTempDirectory();
                } elseif (function_exists('app') && app()->bound(FileStorageManager::class)) {
                    $safeTemp = app(FileStorageManager::class)->getSubprocessTempDirectory();
                } elseif (function_exists('storage_path')) {
                    $safeTemp = storage_path('app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'secure_temp' . DIRECTORY_SEPARATOR . 'proc_tmp');
                    if (!is_dir($safeTemp)) {
                        @mkdir($safeTemp, 0755, true);
                    }
                }

                if ($safeTemp && is_dir($safeTemp) && is_writable($safeTemp)) {
                    $env['TEMP'] = $safeTemp;
                    $env['TMP'] = $safeTemp;
                    $env['TMPDIR'] = $safeTemp;
                }
            } else {
                $env['TEMP'] = $sysTemp;
                $env['TMP'] = $sysTemp;
            }
        }

        return $env;
    }
}
