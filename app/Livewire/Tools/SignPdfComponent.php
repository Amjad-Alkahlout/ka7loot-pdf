<?php

namespace App\Livewire\Tools;

use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\ToolUsage;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfSigningService;
use App\Services\Pdf\PdfSplitService;
use App\Services\Pdf\PdfThumbnailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
     * Map of page number to preview base64 data URI (150 DPI crisp quality).
     * @var array<int, string>
     */
    public array $pagePreviews = [];

    /**
     * Active signature creation tab: 'draw', 'upload', 'type'
     */
    public string $activeSignatureType = 'draw';

    public string $typedName = '';
    public string $typedStyle = 'classic';
    public ?string $activeSignaturePreview = null;

    /**
     * Placements on document pages.
     * @var array<int, array{
     *     id: string,
     *     page: int,
     *     x_ratio: float,
     *     y_ratio: float,
     *     width_ratio: float,
     *     height_ratio: float,
     *     rotation: int,
     *     signature_data: string,
     *     signature_type: string,
     *     signature_style?: string
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
                Log::warning("SignPdfComponent: Failed to get page count: " . $e->getMessage());
                $this->pageCount = 1;
            }

            $this->currentPage = 1;
            // Generate crisp 150 DPI preview for the initial page
            $this->loadPagePreview(1);

            $this->jobStatus = 'uploaded';
        }
    }

    public function goToPage(int $page): void
    {
        if ($page < 1 || $page > $this->pageCount) {
            return;
        }

        $this->currentPage = $page;
        $this->loadPagePreview($page);

        // Preload next page in background for seamless navigation
        if ($page < $this->pageCount && !isset($this->pagePreviews[$page + 1])) {
            $this->loadPagePreview($page + 1);
        }
    }

    public function loadPagePreview(int $page): void
    {
        if (isset($this->pagePreviews[$page])) {
            return;
        }

        if (!$this->upload) {
            return;
        }

        try {
            $thumbService = app(PdfThumbnailService::class);
            // Render crisp preview at 150 DPI for crystal clear text and accurate signature placement
            $preview = $thumbService->generatePagePreview($this->upload->getRealPath(), $page, 150);
            if ($preview) {
                $this->pagePreviews[$page] = $preview;
            }
        } catch (Throwable $e) {
            Log::warning("SignPdfComponent: Could not generate preview for page {$page}: " . $e->getMessage());
        }
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
            if (trim($this->typedName) !== '') {
                $this->updatedTypedName();
            }
        }
    }

    public function getAvailableStyles(): array
    {
        return PdfSigningService::TYPED_STYLES;
    }

    public function setDrawData(string $base64Data): void
    {
        $this->activeSignaturePreview = $base64Data;
        $this->addPlacement([
            'page'           => $this->currentPage,
            'signature_data' => $base64Data,
            'signature_type' => 'draw',
            'x_ratio'        => 0.35,
            'y_ratio'        => 0.70,
            'width_ratio'    => 0.30,
            'height_ratio'   => 0.12,
            'rotation'       => 0,
        ]);
    }

    public function updatedSignatureUpload(): void
    {
        $this->validate([
            'signatureUpload' => 'required|image|mimes:png,jpg,jpeg,webp|max:5120',
        ]);

        if ($this->signatureUpload) {
            try {
                $signingService = app(PdfSigningService::class);
                $tempDest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sig_up_' . uniqid('', true) . '.png';
                $signingService->normalizeSignatureImage($this->signatureUpload->getRealPath(), $tempDest);

                $content = file_get_contents($tempDest);
                @unlink($tempDest);

                if ($content !== false) {
                    $base64 = 'data:image/png;base64,' . base64_encode($content);
                    $this->activeSignaturePreview = $base64;
                    $this->addPlacement([
                        'page'           => $this->currentPage,
                        'signature_data' => $base64,
                        'signature_type' => 'upload',
                        'x_ratio'        => 0.35,
                        'y_ratio'        => 0.70,
                        'width_ratio'    => 0.30,
                        'height_ratio'   => 0.12,
                        'rotation'       => 0,
                    ]);
                }
            } catch (Throwable $e) {
                $this->errorMessage = $e->getMessage();
            }
        }
    }

    public function updatedTypedName(): void
    {
        $clean = trim($this->typedName);
        if ($clean === '') {
            $this->activeSignaturePreview = null;
            return;
        }

        try {
            $signingService = app(PdfSigningService::class);
            $this->activeSignaturePreview = $signingService->generateTypedSignatureDataUri(
                $clean,
                $this->typedStyle,
                36
            );
        } catch (Throwable $e) {
            Log::warning("SignPdfComponent: typed signature generation failed: " . $e->getMessage());
        }
    }

    public function applyTypedSignature(): void
    {
        $this->updatedTypedName();
        if ($this->activeSignaturePreview) {
            $this->addPlacement([
                'page'            => $this->currentPage,
                'signature_data'  => $this->activeSignaturePreview,
                'signature_type'  => 'type',
                'signature_style' => $this->typedStyle,
                'x_ratio'         => 0.35,
                'y_ratio'         => 0.70,
                'width_ratio'     => 0.30,
                'height_ratio'    => 0.12,
                'rotation'        => 0,
            ]);
        }
    }

    /**
     * Add a signature placement to the document.
     *
     * @param array $params
     */
    public function addPlacement(array $params): void
    {
        $id = Str::uuid()->toString();
        $this->placements[] = [
            'id'              => $id,
            'page'            => (int) ($params['page'] ?? $this->currentPage),
            'x_ratio'         => (float) ($params['x_ratio'] ?? 0.35),
            'y_ratio'         => (float) ($params['y_ratio'] ?? 0.70),
            'width_ratio'     => (float) ($params['width_ratio'] ?? 0.30),
            'height_ratio'    => (float) ($params['height_ratio'] ?? 0.12),
            'rotation'        => (int) ($params['rotation'] ?? 0),
            'signature_data'  => (string) ($params['signature_data'] ?? $this->activeSignaturePreview ?? ''),
            'signature_type'  => (string) ($params['signature_type'] ?? $this->activeSignatureType),
            'signature_style' => (string) ($params['signature_style'] ?? $this->typedStyle),
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
        $this->placements = array_values(array_filter($this->placements, fn($p) => $p['id'] !== $id));
        if ($this->selectedPlacementId === $id) {
            $this->selectedPlacementId = !empty($this->placements) ? $this->placements[0]['id'] : null;
        }
    }

    public function clearPlacements(): void
    {
        $this->placements = [];
        $this->selectedPlacementId = null;
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        PdfSigningService $signingService
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

        $this->errorMessage = null;
        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        $startTime = hrtime(true);

        try {
            $jobId = Str::uuid()->toString();
            $jobDir = $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $storageResult = $storage->storeUploadedFile($this->upload, $jobId, $sanitized['safe_filename']);
            $sourcePath = $storageResult['absolute_path'];

            $outputFilename = 'signed_' . $sanitized['safe_filename'];
            $outputPath = $jobDir . DIRECTORY_SEPARATOR . $outputFilename;

            // Execute signing
            $signingService->sign($sourcePath, $outputPath, $this->placements);

            $elapsedMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $processedSizeBytes = filesize($outputPath);

            $job = FileJob::create([
                'id'                    => $jobId,
                'tool_type'             => ToolType::SIGN_PDF,
                'status'                => JobStatus::COMPLETED,
                'download_token'        => Str::random(48),
                'input_metadata'        => [
                    'file'            => $sanitized['safe_filename'],
                    'output_filename' => $outputFilename,
                    'clean_name'      => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'ip'              => request()->ip(),
                ],
                'expires_at'            => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
                'processing_started_at' => now(),
                'completed_at'          => now(),
            ]);

            ProcessedFile::create([
                'id'                   => Str::uuid()->toString(),
                'file_job_id'          => $job->id,
                'original_name'        => 'signed_' . $sanitized['original_name'],
                'stored_filename'      => $outputFilename,
                'mime_type'            => 'application/pdf',
                'original_size_bytes'  => $sanitized['size_bytes'],
                'processed_size_bytes' => $processedSizeBytes,
                'download_token'       => Str::random(48),
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
            Log::error("SignPdfComponent: processing failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->errorMessage = $e->getMessage();
            $this->jobStatus = 'failed';
        }
    }

    public function resetTool(): void
    {
        $this->reset([
            'upload',
            'signatureUpload',
            'filename',
            'fileSizeBytes',
            'fileSizeFormatted',
            'pageCount',
            'currentPage',
            'pagePreviews',
            'placements',
            'selectedPlacementId',
            'typedName',
            'activeSignaturePreview',
            'errorMessage',
            'statusMessage'
        ]);
        $this->resetState();
    }

    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }

    public function render()
    {
        return view('livewire.tools.sign-pdf-component')
            ->layout('layouts.app', ['title' => __('tools.sign_pdf.title')]);
    }
}
