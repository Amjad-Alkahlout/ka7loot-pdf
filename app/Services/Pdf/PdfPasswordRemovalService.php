<?php

namespace App\Services\Pdf;

use App\Services\File\FileStorageManager;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class PdfPasswordRemovalService
{
    public function __construct(
        protected SystemBinaryDetector $detector,
        protected SubprocessExecutorService $executor,
        protected ?FileStorageManager $storage = null
    ) {}

    /**
     * Determine if a PDF is password protected (encrypted).
     */
    public function isEncrypted(string $pdfPath): bool
    {
        if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
            throw new InvalidArgumentException("PDF file does not exist or is empty.");
        }

        $pdfinfo = $this->detector->getPdfinfoPath();
        if ($pdfinfo) {
            $process = new Process([$pdfinfo, $pdfPath]);
            $process->setTimeout(10);
            $process->run();

            // Exit code 1 with "Incorrect password" means it is encrypted
            if ($process->getExitCode() === 1) {
                $err = $process->getErrorOutput();
                if (stripos($err, 'password') !== false) {
                    return true;
                }
            }

            $out = $process->getOutput();
            if (preg_match('/Encrypted:\s+yes/i', $out)) {
                return true;
            }

            return false;
        }

        // Fallback: Check Ghostscript probe
        $gsBinary = $this->detector->getGhostscriptPath();
        if ($gsBinary) {
            $cmd = [
                $gsBinary,
                '-dNODISPLAY',
                '-dNOPAUSE',
                '-dBATCH',
                '-dQUIET',
                $pdfPath,
            ];
            $process = new Process($cmd);
            $process->setTimeout(10);
            $process->run();

            $output = $process->getOutput() . ' ' . $process->getErrorOutput();
            if (stripos($output, 'password') !== false || stripos($output, 'Cannot decrypt') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Authenticate and verify the user's password against the encrypted PDF.
     */
    public function verifyPassword(string $pdfPath, string $password): bool
    {
        if (!file_exists($pdfPath) || filesize($pdfPath) === 0) {
            throw new InvalidArgumentException("PDF file does not exist or is empty.");
        }

        $pdfinfo = $this->detector->getPdfinfoPath();
        if ($pdfinfo) {
            $process = new Process([$pdfinfo, '-upw', $password, $pdfPath]);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful()) {
                return true;
            }

            $err = $process->getErrorOutput();
            if (stripos($err, 'Incorrect password') !== false) {
                return false;
            }
        }

        // Fallback or secondary check using Ghostscript
        $gsBinary = $this->detector->getGhostscriptPath();
        if ($gsBinary) {
            $probeDir = $this->storage
                ? $this->storage->getSubprocessTempDirectory()
                : dirname($pdfPath);

            $testOutput = $probeDir . DIRECTORY_SEPARATOR . 'gs_probe_' . bin2hex(random_bytes(4)) . '.pdf';
            $cmd = [
                $gsBinary,
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.7',
                '-dNOPAUSE',
                '-dBATCH',
                '-dFirstPage=1',
                '-dLastPage=1',
                '-sPDFPassword=' . $password,
                '-sOutputFile=' . $testOutput,
                $pdfPath,
            ];

            $env = [
                'TEMP'   => $probeDir,
                'TMP'    => $probeDir,
                'TMPDIR' => $probeDir,
            ];

            $process = new Process($cmd, $probeDir, $env);
            $process->setTimeout(15);
            $process->run();

            $output = $process->getOutput() . ' ' . $process->getErrorOutput();
            $success = $process->isSuccessful()
                && stripos($output, 'Password did not work') === false
                && stripos($output, 'Cannot decrypt') === false
                && file_exists($testOutput)
                && filesize($testOutput) > 0;

            if (file_exists($testOutput)) {
                @unlink($testOutput);
            }

            return $success;
        }

        return false;
    }

    /**
     * Remove password protection from an encrypted PDF and generate an unprotected copy.
     *
     * @param string $sourcePath Path to encrypted source PDF
     * @param string $outputPath Path for unprotected output PDF
     * @param string $password User password to unlock
     * @return array{engine: string, output_size: int}
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function removePassword(string $sourcePath, string $outputPath, string $password): array
    {
        if (!file_exists($sourcePath) || filesize($sourcePath) === 0) {
            throw new InvalidArgumentException("Source PDF file does not exist or is empty.");
        }

        if (realpath($sourcePath) && realpath($outputPath) && realpath($sourcePath) === realpath($outputPath)) {
            throw new InvalidArgumentException("Source and output paths cannot be identical.");
        }

        if (trim($password) === '') {
            throw new InvalidArgumentException("Password cannot be empty.");
        }

        // 1. Strictly verify that the password works before proceeding
        if (!$this->verifyPassword($sourcePath, $password)) {
            throw new RuntimeException("Invalid password. Unable to unlock the PDF document.");
        }

        $gsBinary = $this->detector->getGhostscriptPath();
        if (!$gsBinary) {
            throw new RuntimeException("Ghostscript executable is not available on this system.");
        }

        // Ensure output directory exists and is writable
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            if (!@mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                throw new RuntimeException("Output directory cannot be created: {$outputDir}");
            }
        }

        if (!is_writable($outputDir)) {
            throw new RuntimeException("Output directory is not writable: {$outputDir}");
        }

        // Dedicated temporary directory for scratch files
        $gsTempDir = $outputDir . DIRECTORY_SEPARATOR . 'gs_unlock_tmp_' . bin2hex(random_bytes(4));
        if (!is_dir($gsTempDir)) {
            @mkdir($gsTempDir, 0755, true);
        }

        // 2. Generate decrypted output
        $cmd = [
            $gsBinary,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.7',
            '-dNOPAUSE',
            '-dBATCH',
            '-dQUIET',
            '-sPDFPassword=' . $password,
            '-sOutputFile=' . $outputPath,
            $sourcePath,
        ];

        $env = [
            'TEMP'   => $gsTempDir,
            'TMP'    => $gsTempDir,
            'TMPDIR' => $gsTempDir,
        ];

        try {
            $this->executor->execute(
                command: $cmd,
                workingDirectory: $outputDir,
                environment: $env
            );
        } catch (Throwable $e) {
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            throw new RuntimeException("Failed to remove PDF password: " . $this->sanitizeErrorMessage($e->getMessage()), 0, $e);
        } finally {
            $this->cleanupDirectory($gsTempDir);
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException("Decryption completed but output file is missing or empty.");
        }

        return [
            'engine'      => 'ghostscript',
            'output_size' => filesize($outputPath),
        ];
    }

    /**
     * Clean passwords and filesystem paths from error messages.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        $sanitized = preg_replace('/-s(Owner|User|PDF)Password=[^\s]+/i', '-s$1Password=********', $message);

        $storageRoot = $this->storage ? $this->storage->getRootPath() : '';
        if ($storageRoot) {
            $sanitized = str_ireplace($storageRoot, '[storage]', $sanitized);
        }
        if (function_exists('base_path')) {
            $sanitized = str_ireplace(base_path(), '[app]', $sanitized);
        }

        $sanitized = preg_replace('/[A-Za-z]:\\\\[^:\n\r\t"]+/', '[path]', $sanitized);
        $sanitized = preg_replace('/\/(?:home|var|tmp|app|storage)\/[^\s:\n\r\t"]+/', '[path]', $sanitized);

        return $sanitized ?: 'Internal decryption error';
    }

    /**
     * Clean up temporary directory and all scratch files inside it.
     */
    protected function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        @rmdir($dir);
    }
}
