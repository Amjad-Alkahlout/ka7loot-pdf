<?php

namespace App\Livewire\Tools;

use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\ToolUsage;
use App\Services\File\DailyFileQuotaService;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfSigningService;
use App\Services\Pdf\PdfSplitService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Throwable;

class SignPdfComponent extends BaseToolComponent
{
    use WithFileUploads;

    public ?string $toolType = ToolType::SIGN_PDF->value;

    /**
     * Uploaded PDF file.
     */
    public $upload;

    /**
     * Optional uploaded signature image file (PNG/JPG/WebP).
     */
    public $signatureUpload;

    public string $filename = '';

    public int $fileSizeBytes = 0;

    public string $fileSizeFormatted = '';

    public int $pageCount = 0;

    public int $currentPage = 1;

    /**
     * Session-isolated job UUID for signature asset storage.
     */
    #[Locked]
    public ?string $jobId = null;

    /**
     * Active signature creation tab: 'draw', 'upload', 'type'
     */
    public string $activeSignatureType = 'draw';

    public string $typedName = '';

    public string $typedStyle = 'classic';

    /**
     * Placements on document pages.
     * Contains only lightweight metadata (coordinates, rotation, type, relative file path or text).
     * Zero Base64 strings are stored in this array.
     *
     * @var array<int, array{
     *     id: string,
     *     page: int,
     *     x_ratio: float,
     *     y_ratio: float,
     *     width_ratio: float,
     *     height_ratio: float,
     *     rotation: int,
     *     signature_type: 'draw'|'upload'|'type',
     *     signature_path?: string|null,
     *     signature_filename?: string|null,
     *     signature_text?: string|null,
     *     signature_style?: string|null
     * }>
     */
    public array $placements = [];

    public ?string $selectedPlacementId = null;

    public function updatedUpload(): void
    {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
        ]);

        if ($this->upload) {
            $realPath = $this->upload->getRealPath();
            $this->filename = $this->upload->getClientOriginalName();
            $this->fileSizeBytes = (int) $this->upload->getSize();
            $this->fileSizeFormatted = $this->formatFileSize($this->fileSizeBytes);

            try {
                $pdfSplit = app(PdfSplitService::class);
                $this->pageCount = max(1, $pdfSplit->getPageCount($realPath));
            } catch (Throwable $e) {
                Log::warning('SignPdfComponent: Failed to get page count: '.$e->getMessage());
                $this->pageCount = 1;
            }

            $this->currentPage = 1;

            if (! $this->jobId) {
                $this->jobId = Str::uuid()->toString();
            }

            if (! session()->has('preview_session_id')) {
                session()->put('preview_session_id', session()->getId());
            }

            $this->ensureJobDirectory();
            $this->jobStatus = 'uploaded';
        }
    }

    /**
     * Ensure the session-isolated signature storage directory exists.
     */
    protected function ensureJobDirectory(): string
    {
        if (! $this->jobId) {
            $this->jobId = Str::uuid()->toString();
        }

        $storage = app(FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($this->jobId);
        $sigDir = $jobDir.DIRECTORY_SEPARATOR.'signatures';

        if (! is_dir($sigDir)) {
            @mkdir($sigDir, 0755, true);
        }

        return $sigDir;
    }

    /** Resolve only signature files generated inside this component's signature directory. */
    protected function resolveOwnedSignaturePath(?string $path): ?string
    {
        if (! $this->jobId || ! is_string($path)
            || ! preg_match('/^signatures\\/sig_(?:up_)?[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\\.png$/i', $path)) {
            return null;
        }

        $storage = app(FileStorageManager::class);
        $signatureDirectory = realpath($storage->getJobDirectory($this->jobId).DIRECTORY_SEPARATOR.'signatures');
        $signaturePath = realpath($storage->getJobDirectory($this->jobId).DIRECTORY_SEPARATOR.$path);

        if (! $signatureDirectory || ! $signaturePath || ! is_file($signaturePath)
            || dirname($signaturePath) !== $signatureDirectory) {
            return null;
        }

        return $signaturePath;
    }

    /**
     * Generate secure, signed temporary preview URL for an uploaded PDF page at 150 DPI.
     */
    public function getPagePreviewUrl(int $page, int $dpi = 150): string
    {
        if (! $this->upload) {
            return '';
        }

        $sessionId = (string) (session()->get('preview_session_id') ?: session()->getId());
        session()->put('preview_session_id', $sessionId);

        return URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            [
                'filename' => $this->upload->getFilename(),
                'page' => $page,
                'dpi' => $dpi,
                'session_id' => $sessionId,
            ]
        );
    }

    /**
     * Generate secure, signed temporary preview URL for a placed signature image.
     */
    public function getSignaturePreviewUrl(array $placement): string
    {
        if (($placement['signature_type'] ?? '') === 'type') {
            return '';
        }

        $filename = $placement['signature_filename'] ?? basename($placement['signature_path'] ?? '');
        if (! $filename || ! $this->jobId) {
            return '';
        }

        $sessionId = (string) (session()->get('preview_session_id') ?: session()->getId());
        session()->put('preview_session_id', $sessionId);

        return URL::temporarySignedRoute(
            'tools.signature-preview',
            now()->addHour(),
            [
                'filename' => $filename,
                'job_id' => $this->jobId,
                'session_id' => $sessionId,
            ]
        );
    }

    public function goToPage(int $page): void
    {
        if ($page < 1 || $page > $this->pageCount) {
            return;
        }

        $this->currentPage = $page;
    }

    public function setSignatureType(string $type): void
    {
        if (in_array($type, ['draw', 'upload', 'type'], true)) {
            $this->activeSignatureType = $type;
        }
    }

    public function setTypedStyle(string $style): void
    {
        if (array_key_exists($style, PdfSigningService::TYPED_STYLES)) {
            $this->typedStyle = $style;
        }
    }

    public function getAvailableStyles(): array
    {
        return PdfSigningService::TYPED_STYLES;
    }

    /**
     * Save drawn signature directly to session-isolated disk storage without storing Base64 in state.
     */
    public function setDrawData(string $base64Data): void
    {
        if (! preg_match('/^data:image\/png;base64,(.+)$/is', $base64Data, $m)) {
            return;
        }

        $binary = base64_decode($m[1], true);
        if ($binary === false || strlen($binary) === 0) {
            return;
        }

        $im = @imagecreatefromstring($binary);
        if (! $im) {
            return;
        }

        imagealphablending($im, false);
        imagesavealpha($im, true);

        $sigDir = $this->ensureJobDirectory();
        $sigId = Str::uuid()->toString();
        $filename = "sig_{$sigId}.png";
        $targetPath = $sigDir.DIRECTORY_SEPARATOR.$filename;

        imagepng($im, $targetPath);
        imagedestroy($im);

        $this->addPlacement([
            'page' => $this->currentPage,
            'signature_type' => 'draw',
            'signature_path' => "signatures/{$filename}",
            'signature_filename' => $filename,
            'x_ratio' => 0.35,
            'y_ratio' => 0.70,
            'width_ratio' => 0.30,
            'height_ratio' => 0.12,
            'rotation' => 0,
        ]);
    }

    /**
     * Normalize uploaded signature directly to session-isolated disk storage without storing Base64 in state.
     */
    public function updatedSignatureUpload(): void
    {
        $this->validate([
            'signatureUpload' => 'required|image|mimes:png,jpg,jpeg,webp|max:5120',
        ]);

        if ($this->signatureUpload) {
            try {
                $sigDir = $this->ensureJobDirectory();
                $sigId = Str::uuid()->toString();
                $filename = "sig_up_{$sigId}.png";
                $targetPath = $sigDir.DIRECTORY_SEPARATOR.$filename;

                $signingService = app(PdfSigningService::class);
                $signingService->normalizeSignatureImage($this->signatureUpload->getRealPath(), $targetPath);

                // Reset upload property so file reference does not linger in state
                $this->signatureUpload = null;

                $this->addPlacement([
                    'page' => $this->currentPage,
                    'signature_type' => 'upload',
                    'signature_path' => "signatures/{$filename}",
                    'signature_filename' => $filename,
                    'x_ratio' => 0.35,
                    'y_ratio' => 0.70,
                    'width_ratio' => 0.30,
                    'height_ratio' => 0.12,
                    'rotation' => 0,
                ]);
            } catch (Throwable $e) {
                $this->errorMessage = $this->reportToolFailure($e);
            }
        }
    }

    public function updatedTypedName(): void
    {
        // Name changes are bound via wire:model and rendered with CSS fonts without server Base64 generation
    }

    /**
     * Apply typed text signature with selected font style.
     */
    public function applyTypedSignature(): void
    {
        $clean = trim($this->typedName);
        if ($clean === '') {
            return;
        }

        $this->addPlacement([
            'page' => $this->currentPage,
            'signature_type' => 'type',
            'signature_text' => $clean,
            'signature_style' => $this->typedStyle,
            'x_ratio' => 0.35,
            'y_ratio' => 0.70,
            'width_ratio' => 0.30,
            'height_ratio' => 0.12,
            'rotation' => 0,
        ]);
    }

    /**
     * Add a signature placement to the document.
     */
    public function addPlacement(array $params): void
    {
        $id = Str::uuid()->toString();
        $this->placements[] = [
            'id' => $id,
            'page' => (int) ($params['page'] ?? $this->currentPage),
            'x_ratio' => (float) ($params['x_ratio'] ?? 0.35),
            'y_ratio' => (float) ($params['y_ratio'] ?? 0.70),
            'width_ratio' => (float) ($params['width_ratio'] ?? 0.30),
            'height_ratio' => (float) ($params['height_ratio'] ?? 0.12),
            'rotation' => (int) ($params['rotation'] ?? 0),
            'signature_type' => (string) ($params['signature_type'] ?? $this->activeSignatureType),
            'signature_path' => isset($params['signature_path']) ? (string) $params['signature_path'] : null,
            'signature_filename' => isset($params['signature_filename']) ? (string) $params['signature_filename'] : null,
            'signature_text' => isset($params['signature_text']) ? (string) $params['signature_text'] : null,
            'signature_style' => isset($params['signature_style']) ? (string) $params['signature_style'] : $this->typedStyle,
        ];

        $this->selectedPlacementId = $id;
    }

    public function updatePlacement(
        string $id,
        float $xRatio,
        float $yRatio,
        float $wRatio,
        float $hRatio,
        int $rotation = 0
    ): void {
        foreach ($this->placements as &$p) {
            if ($p['id'] === $id) {
                $p['x_ratio'] = max(0.0, min(1.0 - $wRatio, $xRatio));
                $p['y_ratio'] = max(0.0, min(1.0 - $hRatio, $yRatio));
                $p['width_ratio'] = max(0.05, min(0.95, $wRatio));
                $p['height_ratio'] = max(0.02, min(0.95, $hRatio));
                $p['rotation'] = (($rotation % 360) + 360) % 360;
                break;
            }
        }
        unset($p);
    }

    public function rotatePlacement(string $id, int $degrees = 90): void
    {
        foreach ($this->placements as &$p) {
            if ($p['id'] === $id) {
                $p['rotation'] = ((($p['rotation'] ?? 0) + $degrees) % 360 + 360) % 360;
                break;
            }
        }
        unset($p);
    }

    public function removePlacement(string $id): void
    {
        $removed = null;
        foreach ($this->placements as $p) {
            if ($p['id'] === $id) {
                $removed = $p;
                break;
            }
        }

        $this->placements = array_values(array_filter($this->placements, fn ($p) => $p['id'] !== $id));

        if ($this->selectedPlacementId === $id) {
            $this->selectedPlacementId = ! empty($this->placements) ? $this->placements[0]['id'] : null;
        }

        // Clean up signature file if no longer referenced
        if ($removed && ! empty($removed['signature_path']) && $this->jobId) {
            $path = $removed['signature_path'];
            $stillReferenced = false;
            foreach ($this->placements as $p) {
                if (($p['signature_path'] ?? null) === $path) {
                    $stillReferenced = true;
                    break;
                }
            }
            if (! $stillReferenced) {
                $fullPath = $this->resolveOwnedSignaturePath($path);
                if ($fullPath) {
                    @unlink($fullPath);
                }
            }
        }
    }

    public function clearPlacements(): void
    {
        if ($this->jobId) {
            $storage = app(FileStorageManager::class);
            $sigDir = $storage->getJobDirectory($this->jobId).DIRECTORY_SEPARATOR.'signatures';
            if (is_dir($sigDir)) {
                $files = glob($sigDir.DIRECTORY_SEPARATOR.'*');
                if ($files) {
                    foreach ($files as $f) {
                        if (is_file($f)) {
                            @unlink($f);
                        }
                    }
                }
            }
        }

        $this->placements = [];
        $this->selectedPlacementId = null;
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        PdfSigningService $signingService,
        DailyFileQuotaService $quota
    ): void {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
        ]);

        if (empty($this->placements)) {
            $this->addError('placements', __('tools.sign_pdf.error_at_least_one_signature'));
            $this->errorMessage = __('tools.sign_pdf.error_at_least_one_signature');

            return;
        }
        try {
            $quota->assertAvailable();
        } catch (Throwable $e) {
            $this->addError('upload', $e->getMessage());
            $this->jobStatus = 'uploaded';

            return;
        }

        $this->errorMessage = null;
        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        $startTime = hrtime(true);

        try {
            $jobId = $this->jobId ?: Str::uuid()->toString();
            $jobDir = $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $storageResult = $storage->storeUploadedFile($this->upload, $jobId, $sanitized['safe_filename']);
            $sourcePath = $storageResult['absolute_path'];

            $outputFilename = 'signed_'.$sanitized['safe_filename'];
            $outputPath = $jobDir.DIRECTORY_SEPARATOR.$outputFilename;

            // Resolve signature assets only from this job's generated signature files.
            $resolvedPlacements = array_map(function ($p) {
                if (! empty($p['signature_path'])) {
                    $signaturePath = $this->resolveOwnedSignaturePath($p['signature_path']);
                    if (! $signaturePath) {
                        throw new \InvalidArgumentException('Signature image is not available in this signing session.');
                    }
                    $p['signature_path'] = $signaturePath;
                }

                return $p;
            }, $this->placements);

            // Execute signing
            $signingService->sign($sourcePath, $outputPath, $resolvedPlacements);

            $elapsedMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $processedSizeBytes = filesize($outputPath);

            $job = FileJob::create([
                'id' => $jobId,
                'tool_type' => ToolType::SIGN_PDF,
                'status' => JobStatus::COMPLETED,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file' => $sanitized['safe_filename'],
                    'output_filename' => $outputFilename,
                    'clean_name' => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'ip' => request()->ip(),
                ],
                'expires_at' => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
                'processing_started_at' => now(),
                'completed_at' => now(),
            ]);

            ProcessedFile::create([
                'id' => Str::uuid()->toString(),
                'file_job_id' => $job->id,
                'original_name' => 'signed_'.$sanitized['original_name'],
                'stored_filename' => $outputFilename,
                'mime_type' => 'application/pdf',
                'original_size_bytes' => $sanitized['size_bytes'],
                'processed_size_bytes' => $processedSizeBytes,
                'download_token' => Str::random(48),
            ]);

            ToolUsage::log(
                toolType: ToolType::SIGN_PDF->value,
                ip: request()->ip() ?? '127.0.0.1',
                successful: true,
                bytes: $processedSizeBytes,
                durationMs: $elapsedMs,
                fileJobId: $job->id
            );

            $this->fileJobId = $job->id;
            $this->jobStatus = 'completed';
        } catch (Throwable $e) {
            Log::error('SignPdfComponent: processing failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $safeMessage = $e->getMessage() === 'Signature image is not available in this signing session.'
                ? $e->getMessage()
                : null;
            $this->errorMessage = $this->reportToolFailure($e, $safeMessage);
            $this->jobStatus = 'failed';
        }
    }

    public function resetTool(): void
    {
        if ($this->jobId) {
            try {
                $storage = app(FileStorageManager::class);
                $storage->deleteJobDirectory($this->jobId);
            } catch (Throwable $e) {
                // Ignore cleanup errors on reset
            }
        }

        $this->reset([
            'upload',
            'signatureUpload',
            'filename',
            'fileSizeBytes',
            'fileSizeFormatted',
            'pageCount',
            'currentPage',
            'jobId',
            'placements',
            'selectedPlacementId',
            'typedName',
            'errorMessage',
            'statusMessage',
        ]);
        $this->resetState();
    }

    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        return round($bytes / 1024, 1).' KB';
    }

    public function render()
    {
        return view('livewire.tools.sign-pdf-component')
            ->layout('layouts.app', [
                'title' => __('tools.sign_pdf.title'),
                'description' => __('tools.sign_pdf.desc'),
            ]);
    }
}
