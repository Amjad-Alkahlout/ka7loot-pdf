<?php

namespace App\Services\Pdf;

use App\Exceptions\UnsupportedPdfFontException;
use App\Services\File\FileStorageManager;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class PdfiumTextEditorService
{
    protected string $runnerScript;
    protected string $pdfiumLibraryPath;
    protected string $fallbackFontPath;

    public function __construct(
        protected SubprocessExecutorService $executor,
        protected FileStorageManager $storage,
        protected SystemBinaryDetector $detector
    ) {
        $this->runnerScript = base_path('bin/pdfium_editor.cjs');
        $this->fallbackFontPath = resource_path('fonts/Amiri-Regular.ttf');
        $this->pdfiumLibraryPath = $this->resolvePdfiumLibraryPath();
    }

    /**
     * Check if PDFium and Node runtime are available on the current host.
     */
    public function isAvailable(): bool
    {
        return $this->detector->hasNode()
            && file_exists($this->runnerScript)
            && file_exists($this->pdfiumLibraryPath);
    }

    /**
     * Get engine version and capability metadata.
     */
    public function getVersionMetadata(): array
    {
        $versionFile = base_path('bin/pdfium/VERSION');
        $rawVersion = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : 'MAJOR=155';

        return [
            'engine'            => 'Google Chromium PDFium',
            'version'           => $rawVersion,
            'license'           => 'Apache-2.0',
            'library_path'      => $this->pdfiumLibraryPath,
            'node_version'      => $this->detector->getNodeVersion(),
            'platform'          => PHP_OS_FAMILY,
            'fallback_font'     => file_exists($this->fallbackFontPath) ? basename($this->fallbackFontPath) : null,
            'supports_arabic'   => true,
            'supports_in_place' => true,
        ];
    }

    /**
     * Inspect a PDF document and return structured metadata and text objects.
     *
     * @param string $pdfPath
     * @param string|null $password
     * @return array
     * @throws InvalidArgumentException|RuntimeException
     */
    public function inspectDocument(string $pdfPath, ?string $password = null): array
    {
        $this->validateInputFile($pdfPath);

        $nodePath = $this->getNodePath();
        $command = [
            $nodePath,
            $this->runnerScript,
            '--action=inspect',
            '--input=' . $pdfPath,
        ];

        if ($password !== null && $password !== '') {
            $command[] = '--password=' . $password;
        }

        $result = $this->executor->execute($command, 30, base_path(), [
            'PDFIUM_PATH' => $this->pdfiumLibraryPath,
        ]);

        $parsed = json_decode(trim($result['output']), true);
        if (!is_array($parsed) || !($parsed['success'] ?? false)) {
            $err = $parsed['error'] ?? ($result['error_output'] ?: 'Failed to inspect document');
            if (($parsed['is_encrypted'] ?? false) || ($parsed['error_code'] ?? 0) === 4) {
                throw new RuntimeException("Document is password protected: {$err}");
            }
            throw new RuntimeException("PDFium inspection failed: {$err}");
        }

        return $parsed;
    }

    /**
     * List text objects for a specific page.
     *
     * @param string $pdfPath
     * @param int $pageIndex (0-based)
     * @param string|null $password
     * @return array
     */
    public function listTextObjects(string $pdfPath, int $pageIndex = 0, ?string $password = null): array
    {
        $inspection = $this->inspectDocument($pdfPath, $password);
        foreach ($inspection['pages'] ?? [] as $page) {
            if ($page['page_index'] === $pageIndex) {
                return $page['text_objects'] ?? [];
            }
        }

        return [];
    }

    /**
     * List logical text groups for a specific page.
     *
     * @param string $pdfPath
     * @param int $pageIndex (0-based)
     * @param string|null $password
     * @return array
     */
    public function listTextGroups(string $pdfPath, int $pageIndex = 0, ?string $password = null): array
    {
        $inspection = $this->inspectDocument($pdfPath, $password);
        foreach ($inspection['pages'] ?? [] as $page) {
            if ($page['page_index'] === $pageIndex) {
                return $page['text_groups'] ?? [];
            }
        }

        return [];
    }

    /**
     * Search for text across all pages in the PDF document.
     *
     * @param string $pdfPath
     * @param string $query
     * @param bool $caseSensitive
     * @param string|null $password
     * @return array
     */
    public function findText(string $pdfPath, string $query, bool $caseSensitive = false, ?string $password = null): array
    {
        if (trim($query) === '') {
            return [];
        }

        $inspection = $this->inspectDocument($pdfPath, $password);
        $matches = [];

        foreach ($inspection['pages'] ?? [] as $page) {
            $pIdx = $page['page_index'];

            // Match against logical text groups first
            foreach ($page['text_groups'] ?? [] as $grp) {
                $haystack = $grp['text'] ?? '';
                $matched = $caseSensitive
                    ? (strpos($haystack, $query) !== false)
                    : (stripos($haystack, $query) !== false);

                if ($matched) {
                    $matches[] = [
                        'page_index'       => $pIdx,
                        'group_id'         => $grp['group_id'],
                        'child_object_ids' => $grp['child_object_ids'] ?? [],
                        'object_id'        => $grp['child_object_ids'][0] ?? $grp['group_id'],
                        'text'             => $grp['text'],
                        'bbox'             => $grp['bounding_box'] ?? [],
                        'font_size'        => $grp['font_size'] ?? 12.0,
                        'font_name'        => $grp['font_family'] ?? 'Arial',
                    ];
                }
            }

            // If no group match, search raw text objects
            if (empty($matches)) {
                foreach ($page['text_objects'] ?? [] as $obj) {
                    $haystack = $obj['text'];
                    $needle = $query;
                    $matched = $caseSensitive
                        ? (strpos($haystack, $needle) !== false)
                        : (stripos($haystack, $needle) !== false);

                    if ($matched) {
                        $matches[] = [
                            'page_index'  => $pIdx,
                            'object_id'   => $obj['id'],
                            'object_index'=> $obj['index'],
                            'text'        => $obj['text'],
                            'bbox'        => $obj['bbox'],
                            'font_size'   => $obj['font_size'],
                            'font_name'   => $obj['font_name'],
                        ];
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Execute in-place text replacement on the PDF.
     *
     * @param string $pdfPath Input PDF path
     * @param array $replacements Array of replacement specs
     * @param array $options ['job_id', 'password', 'fit_strategy', 'fallback_font_path', 'output_filename']
     * @return array ['success' => true, 'output_path' => string, 'modified_count' => int, 'warnings' => array]
     * @throws InvalidArgumentException|UnsupportedPdfFontException|RuntimeException
     */
    public function replaceText(string $pdfPath, array $replacements, array $options = []): array
    {
        $this->validateInputFile($pdfPath);

        if (empty($replacements)) {
            throw new InvalidArgumentException('At least one replacement specification is required.');
        }

        // Perform safety inspection first
        $password = $options['password'] ?? null;
        $inspection = $this->inspectDocument($pdfPath, $password);

        if ($inspection['is_scanned'] ?? false) {
            throw new RuntimeException('Cannot edit text: This PDF is an image-only scan with no native text objects.');
        }

        // Map pages by page_index
        $pagesByIndex = [];
        foreach ($inspection['pages'] ?? [] as $page) {
            $pagesByIndex[$page['page_index']] = $page;
        }

        // Safe Edit Precondition: Ensure all target pages are native_editable
        foreach ($replacements as $rep) {
            $pIdx = (int) ($rep['page_index'] ?? 0);
            $pageData = $pagesByIndex[$pIdx] ?? null;
            if (!$pageData) {
                continue;
            }

            if (!($pageData['native_editable'] ?? true) || ($pageData['has_type3_fonts'] ?? false)) {
                $pageNum = $pIdx + 1;
                $reason = $pageData['editable_reason'] ?? 'Contains unsupported Type 3 embedded fonts.';
                throw new UnsupportedPdfFontException(
                    "Cannot perform native text edit on page {$pageNum}: {$reason}",
                    $pageNum,
                    'Type3'
                );
            }
        }

        $jobId = $options['job_id'] ?? Str::uuid()->toString();
        $jobDir = $this->storage->getJobDirectory($jobId);
        $recipeFile = $jobDir . DIRECTORY_SEPARATOR . 'recipe_' . Str::random(8) . '.json';
        $outputPath = $this->storage->generateOutputPath($jobId, 'pdf', 'edited');

        $recipe = [
            'password'           => $password,
            'fallback_font_path' => $options['fallback_font_path'] ?? $this->fallbackFontPath,
            'replacements'       => $replacements,
        ];

        file_put_contents($recipeFile, json_encode($recipe, JSON_UNESCAPED_UNICODE));

        $nodePath = $this->getNodePath();
        $command = [
            $nodePath,
            $this->runnerScript,
            '--action=edit',
            '--input=' . $pdfPath,
            '--recipe=' . $recipeFile,
            '--output=' . $outputPath,
        ];

        try {
            $result = $this->executor->execute($command, 60, base_path(), [
                'PDFIUM_PATH' => $this->pdfiumLibraryPath,
            ]);
        } catch (\Throwable $e) {
            @unlink($recipeFile);
            @unlink($outputPath);
            throw new RuntimeException("PDFium edit execution failed: {$e->getMessage()}", 0, $e);
        } finally {
            @unlink($recipeFile);
        }

        $parsed = json_decode(trim($result['output']), true);
        if (!is_array($parsed) || !($parsed['success'] ?? false)) {
            @unlink($outputPath);
            $err = $parsed['error'] ?? ($result['error_output'] ?: 'Failed to execute text replacement');
            if (($parsed['error_code'] ?? '') === 'UNSUPPORTED_TYPE3_FONTS') {
                throw new UnsupportedPdfFontException("PDFium text replacement rejected: {$err}");
            }
            throw new RuntimeException("PDFium text replacement failed: {$err}");
        }

        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            throw new RuntimeException('PDFium reported success but the output PDF file is missing or empty.');
        }

        return [
            'success'        => true,
            'job_id'         => $jobId,
            'output_path'    => $outputPath,
            'modified_count' => (int) ($parsed['modified_count'] ?? 0),
            'output_size'    => (int) ($parsed['output_size'] ?? filesize($outputPath)),
            'warnings'       => $parsed['warnings'] ?? [],
            'is_signed'      => $inspection['is_signed'] ?? false,
        ];
    }

    /**
     * Resolve the platform-specific PDFium library file.
     */
    protected function resolvePdfiumLibraryPath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return base_path('bin/pdfium/win-x64/pdfium.dll');
        }

        return base_path('bin/pdfium/linux-x64/lib/libpdfium.so');
    }

    /**
     * Get verified Node.js binary path.
     */
    protected function getNodePath(): string
    {
        $node = $this->detector->getNodePath();
        if (!$node) {
            throw new RuntimeException('Node.js runtime is not available on this host.');
        }

        return $node;
    }

    /**
     * Validate input file existence and safety.
     */
    protected function validateInputFile(string $path): void
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Input PDF file not found or not readable: {$path}");
        }

        if (filesize($path) === 0) {
            throw new InvalidArgumentException("Input PDF file is empty: {$path}");
        }
    }
}
