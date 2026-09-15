<?php

namespace App\Services\Pdf;

use App\Services\File\FileStorageManager;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PdfProtectionService
{
    public function __construct(
        protected SystemBinaryDetector $detector,
        protected SubprocessExecutorService $executor,
        protected ?FileStorageManager $storage = null
    ) {}

    /**
     * Compute 32-bit signed permissions bitmask for PDF 1.7 standard.
     */
    public function computePermissions(
        bool $allowPrinting = true,
        bool $allowCopying = true,
        bool $allowModifying = false
    ): int {
        // PDF 1.7 standard 32-bit permissions flags:
        // Reserved bits: bits 1 & 2 must be 0; bits 7, 8, and 13-32 must be 1.
        // Base mask in 32-bit signed representation: -3904 (0xFFFFF0C0)
        $mask = -3904;

        if ($allowPrinting) {
            // Bit 3 (4): print, Bit 12 (2048): high quality print
            $mask |= 4 | 2048;
        }

        if ($allowModifying) {
            // Bit 4 (8): modify, Bit 6 (32): annotate, Bit 9 (256): form fill, Bit 11 (1024): assemble
            $mask |= 8 | 32 | 256 | 1024;
        }

        if ($allowCopying) {
            // Bit 5 (16): copy/extract, Bit 10 (512): accessibility
            $mask |= 16 | 512;
        }

        return $mask;
    }

    /**
     * Encrypt a PDF file using Ghostscript.
     *
     * @param string $sourcePath Path to unencrypted source PDF
     * @param string $outputPath Target path for encrypted PDF
     * @param string $userPassword User (open) password required to view the document
     * @param string|null $ownerPassword Optional owner/master password
     * @param array{allow_printing?: bool, allow_copying?: bool, allow_modifying?: bool} $permissions
     * @return array{engine: string, output_size: int}
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function protect(
        string $sourcePath,
        string $outputPath,
        string $userPassword,
        ?string $ownerPassword = null,
        array $permissions = []
    ): array {
        if (!file_exists($sourcePath) || filesize($sourcePath) === 0) {
            throw new InvalidArgumentException("Source PDF file does not exist or is empty.");
        }

        if (realpath($sourcePath) && realpath($outputPath) && realpath($sourcePath) === realpath($outputPath)) {
            throw new InvalidArgumentException("Source and output paths cannot be identical.");
        }

        if (trim($userPassword) === '') {
            throw new InvalidArgumentException("A user password must be provided to protect the PDF.");
        }

        $gsBinary = $this->detector->getGhostscriptPath();
        if (!$gsBinary) {
            throw new RuntimeException("Ghostscript executable is not available on this system.");
        }

        // Ensure target output directory exists and is strictly writable
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            if (!@mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
                throw new RuntimeException("Output directory cannot be created: {$outputDir}");
            }
        }

        if (!is_writable($outputDir)) {
            throw new RuntimeException("Output directory is not writable: {$outputDir}");
        }

        // Establish controlled temporary directory for Ghostscript scratch files
        // Isolating to the job storage directory ensures full writability and automated lifecycle cleanup
        $gsTempDir = $outputDir . DIRECTORY_SEPARATOR . 'gs_tmp_' . bin2hex(random_bytes(4));
        if (!is_dir($gsTempDir)) {
            @mkdir($gsTempDir, 0755, true);
        }

        // If no owner password is provided, generate a cryptographically secure random one
        // so the user password acts as the open password.
        $effectiveOwnerPassword = (!empty($ownerPassword))
            ? $ownerPassword
            : bin2hex(random_bytes(16));

        $allowPrinting = $permissions['allow_printing'] ?? true;
        $allowCopying = $permissions['allow_copying'] ?? true;
        $allowModifying = $permissions['allow_modifying'] ?? false;

        $permissionsInt = $this->computePermissions($allowPrinting, $allowCopying, $allowModifying);

        $cmd = [
            $gsBinary,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.7',
            '-dNOPAUSE',
            '-dBATCH',
            '-dQUIET',
            '-sOwnerPassword=' . $effectiveOwnerPassword,
            '-sUserPassword=' . $userPassword,
            '-dEncryptionR=3',
            '-dKeyLength=128',
            '-dPermissions=' . $permissionsInt,
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
            // Clean up partial output if any was created
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
            // Throw sanitized exception message without revealing password arguments or internal paths
            throw new RuntimeException("PDF encryption failed during processing: " . $this->sanitizeErrorMessage($e->getMessage()), 0, $e);
        } finally {
            // Always cleanly remove temporary Ghostscript scratch directory
            $this->cleanupDirectory($gsTempDir);
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException("Encryption completed but output file is missing or empty.");
        }

        return [
            'engine'      => 'ghostscript',
            'output_size' => filesize($outputPath),
        ];
    }

    /**
     * Remove any accidental password leak or internal paths from error message.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        $sanitized = preg_replace('/-s(Owner|User|PDF)Password=[^\s]+/i', '-s$1Password=********', $message);

        // Redact full absolute system paths to prevent filesystem exposure to users
        $storageRoot = $this->storage ? $this->storage->getRootPath() : '';
        if ($storageRoot) {
            $sanitized = str_ireplace($storageRoot, '[storage]', $sanitized);
        }
        if (function_exists('base_path')) {
            $sanitized = str_ireplace(base_path(), '[app]', $sanitized);
        }

        // Generic Windows/Unix path redaction
        $sanitized = preg_replace('/[A-Za-z]:\\\\[^:\n\r\t"]+/', '[path]', $sanitized);
        $sanitized = preg_replace('/\/(?:home|var|tmp|app|storage)\/[^\s:\n\r\t"]+/', '[path]', $sanitized);

        return $sanitized ?: 'Internal processing error';
    }

    /**
     * Recursively clean up temporary directory and all scratch files inside it.
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
