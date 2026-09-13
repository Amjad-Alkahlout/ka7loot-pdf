<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class SystemBinaryDetector
{
    protected ?string $ghostscriptPath = null;
    protected ?string $popplerPath = null;
    protected ?string $ghostscriptVersion = null;
    protected ?string $popplerVersion = null;

    protected bool $probed = false;

    /**
     * Inspect system environment and resolve binaries.
     */
    public function probe(bool $forceRefresh = false): void
    {
        if ($this->probed && !$forceRefresh) {
            return;
        }

        $cacheKey = 'system_binary_detector_capabilities';

        if (!$forceRefresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            $this->ghostscriptPath = $cached['gs_path'] ?? null;
            $this->ghostscriptVersion = $cached['gs_version'] ?? null;
            $this->popplerPath = $cached['poppler_path'] ?? null;
            $this->popplerVersion = $cached['poppler_version'] ?? null;
            $this->probed = true;
            return;
        }

        // 1. Detect Ghostscript
        $this->detectGhostscript();

        // 2. Detect Poppler (pdftoppm)
        $this->detectPoppler();

        Cache::put($cacheKey, [
            'gs_path' => $this->ghostscriptPath,
            'gs_version' => $this->ghostscriptVersion,
            'poppler_path' => $this->popplerPath,
            'poppler_version' => $this->popplerVersion,
        ], now()->addHours(6));

        $this->probed = true;
    }

    public function hasGhostscript(): bool
    {
        $this->probe();
        return $this->ghostscriptPath !== null;
    }

    public function getGhostscriptPath(): ?string
    {
        $this->probe();
        return $this->ghostscriptPath;
    }

    public function getGhostscriptVersion(): ?string
    {
        $this->probe();
        return $this->ghostscriptVersion;
    }

    public function hasPoppler(): bool
    {
        $this->probe();
        return $this->popplerPath !== null;
    }

    public function getPopplerPath(): ?string
    {
        $this->probe();
        return $this->popplerPath;
    }

    public function getPopplerVersion(): ?string
    {
        $this->probe();
        return $this->popplerVersion;
    }

    public function canRasterizePdf(): bool
    {
        return $this->hasPoppler() || $this->hasGhostscript();
    }

    public function canDeepCompressPdf(): bool
    {
        return $this->hasGhostscript();
    }

    public function canManipulatePdf(): bool
    {
        // Pure PHP via FPDI is always available
        return class_exists(\setasign\Fpdi\Fpdi::class);
    }

    public function canProcessImages(): bool
    {
        // Pure PHP via GD extension
        return extension_loaded('gd');
    }

    /**
     * Locate Ghostscript executable.
     */
    protected function detectGhostscript(): void
    {
        $configured = config('fileprocessor.binaries.ghostscript');
        if ($configured && file_exists($configured)) {
            $this->ghostscriptPath = $configured;
            $this->ghostscriptVersion = $this->queryVersion([$configured, '--version']);
            return;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['gswin64c.exe', 'gswin64c', 'gswin32c.exe', 'gswin32c', 'gs.exe', 'gs']
            : ['gs'];

        // Known default install locations on Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $programFiles = [
                getenv('ProgramFiles') ?: 'C:\Program Files',
                getenv('ProgramFiles(x86)') ?: 'C:\Program Files (x86)',
                'C:\tools\ghostscript',
            ];
            foreach ($programFiles as $base) {
                $gsFolder = $base . '\gs';
                if (is_dir($gsFolder)) {
                    foreach (scandir($gsFolder) as $sub) {
                        if ($sub === '.' || $sub === '..') {
                            continue;
                        }
                        $binPath = $gsFolder . DIRECTORY_SEPARATOR . $sub . '\bin\gswin64c.exe';
                        if (file_exists($binPath)) {
                            $candidates[] = $binPath;
                        }
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            $path = $this->findExecutable($candidate);
            if ($path) {
                $this->ghostscriptPath = $path;
                $this->ghostscriptVersion = $this->queryVersion([$path, '--version']);
                return;
            }
        }
    }

    /**
     * Locate Poppler (pdftoppm) executable.
     */
    protected function detectPoppler(): void
    {
        $configured = config('fileprocessor.binaries.poppler');
        if ($configured && file_exists($configured)) {
            $this->popplerPath = $configured;
            $this->popplerVersion = $this->queryVersion([$configured, '-v']);
            return;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['pdftoppm.exe', 'pdftoppm']
            : ['pdftoppm'];

        // Known default install locations on Windows (Chocolatey, Scoop, manual)
        if (PHP_OS_FAMILY === 'Windows') {
            $chocoPoppler = 'C:\ProgramData\chocolatey\lib\poppler\tools';
            if (is_dir($chocoPoppler)) {
                $candidates[] = $chocoPoppler . '\pdftoppm.exe';
            }
            $chocoPopplerBin = 'C:\ProgramData\chocolatey\bin\pdftoppm.exe';
            if (file_exists($chocoPopplerBin)) {
                $candidates[] = $chocoPopplerBin;
            }
        }

        foreach ($candidates as $candidate) {
            $path = $this->findExecutable($candidate);
            if ($path) {
                $this->popplerPath = $path;
                $this->popplerVersion = $this->queryVersion([$path, '-v']);
                return;
            }
        }
    }

    /**
     * Probe system PATH or absolute path for executable.
     */
    protected function findExecutable(string $name): ?string
    {
        if (file_exists($name) && is_file($name)) {
            return realpath($name);
        }

        $whichCmd = PHP_OS_FAMILY === 'Windows' ? 'where.exe' : 'which';

        try {
            $process = new Process([$whichCmd, $name]);
            $process->setTimeout(3);
            $process->run();

            if ($process->isSuccessful()) {
                $lines = explode("\n", trim($process->getOutput()));
                $first = trim($lines[0]);
                if (file_exists($first)) {
                    return realpath($first);
                }
            }
        } catch (\Throwable $e) {
            // Binary not found on path
        }

        return null;
    }

    /**
     * Run binary to extract version string.
     *
     * @param array<string> $cmd
     */
    protected function queryVersion(array $cmd): ?string
    {
        try {
            $process = new Process($cmd);
            $process->setTimeout(3);
            $process->run();

            $output = trim($process->getOutput() ?: $process->getErrorOutput());
            if (!empty($output)) {
                // Return first line, limited to 60 characters
                $lines = explode("\n", $output);
                return substr(trim($lines[0]), 0, 60);
            }
        } catch (\Throwable $e) {
            // Version probe failed
        }

        return null;
    }
}
