<?php

namespace App\Jobs;

use App\Enums\CompressionLevel;
use App\Enums\ImageFit;
use App\Enums\JobStatus;
use App\Enums\PageOrientation;
use App\Enums\PageSize;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\ToolUsage;
use App\Services\File\ArchiveGeneratorService;
use App\Services\File\FileStorageManager;
use App\Services\Image\ImageBackgroundService;
use App\Services\Image\ImageCompressionService;
use App\Services\Image\ImageConversionService;
use App\Services\Image\ImageResizeService;
use App\Services\Image\ImageTargetSizeCompressionService;
use App\Services\Pdf\JpgToPdfService;
use App\Services\Pdf\PdfCompressionService;
use App\Services\Pdf\PdfMergeService;
use App\Services\Pdf\PdfOrganizeService;
use App\Services\Pdf\PdfSigningService;
use App\Services\Pdf\PdfSplitService;
use App\Services\Pdf\PdfTargetSizeCompressionService;
use App\Services\Pdf\PdfToImageService;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(
        public string $fileJobId
    ) {}

    public function handle(
        FileStorageManager $storage,
        PdfMergeService $pdfMerge,
        PdfSplitService $pdfSplit,
        PdfOrganizeService $pdfOrganize,
        PdfCompressionService $pdfCompress,
        PdfToImageService $pdfToImage,
        JpgToPdfService $jpgToPdf,
        ImageCompressionService $imageCompress,
        ImageResizeService $imageResize,
        ImageConversionService $imageConvert,
        ArchiveGeneratorService $archiver,
        ?PdfSigningService $pdfSign = null
    ): void {
        $lock = Cache::lock("process_file_job:{$this->fileJobId}", 120);

        if (!$lock->get()) {
            // Another worker is currently processing this job
            Log::info("ProcessFileJob: Concurrency lock active for job {$this->fileJobId}. Skipping concurrent execution.");
            return;
        }

        $startTime = hrtime(true);

        try {
            $job = FileJob::find($this->fileJobId);
            if (!$job) {
                Log::warning("ProcessFileJob: Job {$this->fileJobId} not found in database.");
                $this->releaseLockSafely($lock);
                return;
            }

            // Idempotency: if already completed, exit cleanly without repeating work
            if ($job->status === JobStatus::COMPLETED) {
                Log::info("ProcessFileJob: Job {$this->fileJobId} is already marked as completed. Skipping.");
                $this->releaseLockSafely($lock);
                return;
            }

            $job->update([
                'status'                => JobStatus::PROCESSING,
                'processing_started_at' => now(),
            ]);

            $jobDir = $storage->getJobDirectory($job->id);
            $meta = $job->input_metadata ?? [];

            // Clean up any previously created output files to guarantee idempotency on retry
            $this->cleanPreviousOutputs($job, $jobDir);
            ProcessedFile::where('file_job_id', $job->id)->delete();

            $outputs = []; // Array of ['original_name', 'stored_filename', 'mime_type', 'original_size_bytes', 'processed_size_bytes']
            $resultMeta = $meta;

            switch ($job->tool_type) {
                case ToolType::MERGE_PDF:
                    $files = array_map(fn($f) => $jobDir . DIRECTORY_SEPARATOR . $f, $meta['files'] ?? []);
                    $totalInputBytes = array_sum(array_map('filesize', array_filter($files, 'file_exists')));
                    $outFilename = 'merged_output.pdf';
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    $res = $pdfMerge->merge($files, $outPath);

                    $outputs[] = [
                        'original_name'        => $meta['output_filename'] ?? 'merged.pdf',
                        'stored_filename'      => $outFilename,
                        'mime_type'            => 'application/pdf',
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['size_bytes'],
                    ];
                    $resultMeta['engine'] = 'fpdi';
                    $resultMeta['engine_label'] = 'PDF document processing (FPDI)';
                    break;

                case ToolType::SPLIT_PDF:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $mode = $meta['split_mode'] ?? ($meta['mode'] ?? 'range');
                    $cleanBase = $meta['clean_name'] ?? 'document';
                    $pageCount = file_exists($source) ? $pdfSplit->getPageCount($source) : 0;

                    if ($mode === 'range') {
                        // Support pre-parsed groups or raw range string
                        if (!empty($meta['ranges']) && is_array($meta['ranges'])) {
                            $groups = $meta['ranges'];
                        } else {
                            $rangeStr = $meta['range'] ?? '1';
                            $groups = $pageCount > 0 ? $pdfSplit->parseRangeGroups($rangeStr, $pageCount) : [];
                        }

                        $createdFiles = [];
                        foreach ($groups as $idx => $rangeSpec) {
                            $partNum = $idx + 1;
                            $label = $rangeSpec['range'] ?? ($rangeSpec['label'] ?? (string) $partNum);
                            $outFilename = "split_part_{$partNum}.pdf";
                            $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                            $pages = $rangeSpec['pages'] ?? (isset($rangeSpec['start'], $rangeSpec['end']) ? range($rangeSpec['start'], $rangeSpec['end']) : [$partNum]);
                            $res = $pdfSplit->extractPages($source, $outPath, $pages);

                            $displayName = count($groups) === 1
                                ? "{$cleanBase}_page_{$label}.pdf"
                                : "{$cleanBase}_part_{$partNum}_pages_{$label}.pdf";

                            $createdFiles[] = [
                                'path'                 => $outPath,
                                'original_name'        => $displayName,
                                'stored_filename'      => $outFilename,
                                'mime_type'            => 'application/pdf',
                                'original_size_bytes'  => $totalInputBytes,
                                'processed_size_bytes' => $res['size_bytes'],
                            ];
                        }

                        if (count($createdFiles) > 1) {
                            $zipStored = 'output_split_bundle.zip';
                            $zipPath = $jobDir . DIRECTORY_SEPARATOR . $zipStored;
                            $zipFiles = array_map(fn($cf) => $cf['path'], $createdFiles);
                            $zipRes = $archiver->createZip($zipFiles, $zipPath);

                            $outputs[] = [
                                'original_name'        => "{$cleanBase}_split_bundle.zip",
                                'stored_filename'      => $zipStored,
                                'mime_type'            => 'application/zip',
                                'original_size_bytes'  => $totalInputBytes,
                                'processed_size_bytes' => $zipRes['size_bytes'],
                            ];

                            foreach ($createdFiles as $cf) {
                                $outputs[] = [
                                    'original_name'        => $cf['original_name'],
                                    'stored_filename'      => $cf['stored_filename'],
                                    'mime_type'            => $cf['mime_type'],
                                    'original_size_bytes'  => $cf['original_size_bytes'],
                                    'processed_size_bytes' => $cf['processed_size_bytes'],
                                ];
                            }
                        } elseif (count($createdFiles) === 1) {
                            $outputs[] = [
                                'original_name'        => $createdFiles[0]['original_name'],
                                'stored_filename'      => $createdFiles[0]['stored_filename'],
                                'mime_type'            => $createdFiles[0]['mime_type'],
                                'original_size_bytes'  => $totalInputBytes,
                                'processed_size_bytes' => $createdFiles[0]['processed_size_bytes'],
                            ];
                        }
                    } elseif ($mode === 'every_n') {
                        $interval = max(1, (int) ($meta['every_n'] ?? 1));
                        $groups = $pageCount > 0 ? $pdfSplit->generateEveryNGroups($pageCount, $interval) : [];

                        $createdFiles = [];
                        foreach ($groups as $idx => $group) {
                            $partNum = $idx + 1;
                            $rangeLabel = $group['range'] ?? (string) $partNum;
                            $outFilename = "split_interval_{$partNum}.pdf";
                            $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                            $res = $pdfSplit->extractPages($source, $outPath, $group['pages']);
                            $createdFiles[] = [
                                'path'                 => $outPath,
                                'original_name'        => "{$cleanBase}_part_{$partNum}_pages_{$rangeLabel}.pdf",
                                'stored_filename'      => $outFilename,
                                'mime_type'            => 'application/pdf',
                                'original_size_bytes'  => $totalInputBytes,
                                'processed_size_bytes' => $res['size_bytes'],
                            ];
                        }

                        if (count($createdFiles) > 1) {
                            $zipStored = 'output_split_every_n.zip';
                            $zipPath = $jobDir . DIRECTORY_SEPARATOR . $zipStored;
                            $zipFiles = array_map(fn($cf) => $cf['path'], $createdFiles);
                            $zipRes = $archiver->createZip($zipFiles, $zipPath);

                            $outputs[] = [
                                'original_name'        => "{$cleanBase}_split_every_{$interval}_pages.zip",
                                'stored_filename'      => $zipStored,
                                'mime_type'            => 'application/zip',
                                'original_size_bytes'  => $totalInputBytes,
                                'processed_size_bytes' => $zipRes['size_bytes'],
                            ];

                            foreach ($createdFiles as $cf) {
                                $outputs[] = [
                                    'original_name'        => $cf['original_name'],
                                    'stored_filename'      => $cf['stored_filename'],
                                    'mime_type'            => $cf['mime_type'],
                                    'original_size_bytes'  => $cf['original_size_bytes'],
                                    'processed_size_bytes' => $cf['processed_size_bytes'],
                                ];
                            }
                        } elseif (count($createdFiles) === 1) {
                            $outputs[] = [
                                'original_name'        => $createdFiles[0]['original_name'],
                                'stored_filename'      => $createdFiles[0]['stored_filename'],
                                'mime_type'            => $createdFiles[0]['mime_type'],
                                'original_size_bytes'  => $totalInputBytes,
                                'processed_size_bytes' => $createdFiles[0]['processed_size_bytes'],
                            ];
                        }
                    } else {
                        // Extract all individual pages as zip
                        $splitDir = $jobDir . DIRECTORY_SEPARATOR . 'split_pages';
                        $pageFiles = $pdfSplit->splitAllPages($source, $splitDir);

                        $zipFiles = array_map(fn($pf) => $pf['path'], $pageFiles);
                        $zipStored = 'output_pages.zip';
                        $zipPath = $jobDir . DIRECTORY_SEPARATOR . $zipStored;
                        $zipRes = $archiver->createZip($zipFiles, $zipPath);

                        $outputs[] = [
                            'original_name'        => ($meta['clean_name'] ?? 'document') . '_split_all.zip',
                            'stored_filename'      => $zipStored,
                            'mime_type'            => 'application/zip',
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => $zipRes['size_bytes'],
                        ];
                    }
                    $resultMeta['engine'] = 'fpdi';
                    $resultMeta['engine_label'] = 'PDF document processing (FPDI)';
                    break;

                case ToolType::SIGN_PDF:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $outFilename = 'output_signed.pdf';
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;
                    $placements = $meta['placements'] ?? [];

                    $signer = $pdfSign ?? app(PdfSigningService::class);
                    $res = $signer->sign($source, $outPath, $placements);

                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'document') . '_signed.pdf',
                        'stored_filename'      => $outFilename,
                        'mime_type'            => 'application/pdf',
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['size_bytes'],
                    ];
                    $resultMeta['engine'] = 'fpdi';
                    $resultMeta['engine_label'] = 'PDF document processing (FPDI)';
                    break;
                case ToolType::ORGANIZE_PDF:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $operations = $meta['operations'] ?? [];
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . 'output_organized.pdf';

                    $res = $pdfOrganize->organize($source, $outPath, $operations);
                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'document') . '_organized.pdf',
                        'stored_filename'      => 'output_organized.pdf',
                        'mime_type'            => 'application/pdf',
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['size_bytes'],
                    ];
                    $resultMeta['engine'] = 'fpdi';
                    $resultMeta['engine_label'] = 'PDF document processing (FPDI)';
                    break;

                case ToolType::JPG_TO_PDF:
                    $sources = array_map(fn($f) => $jobDir . DIRECTORY_SEPARATOR . $f, $meta['files'] ?? []);
                    $totalInputBytes = array_sum(array_map('filesize', array_filter($sources, 'file_exists')));
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . 'output_images.pdf';

                    $pageSize = PageSize::tryFrom($meta['page_size'] ?? '') ?? PageSize::A4;
                    $orientation = PageOrientation::tryFrom($meta['orientation'] ?? '') ?? PageOrientation::AUTO;
                    $margin = (float) ($meta['margin'] ?? 10.0);

                    $res = $jpgToPdf->convertImagesToPdf($sources, $outPath, $pageSize, $orientation, $margin);
                    $outputs[] = [
                        'original_name'        => $meta['output_filename'] ?? 'converted_images.pdf',
                        'stored_filename'      => 'output_images.pdf',
                        'mime_type'            => 'application/pdf',
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['size_bytes'],
                    ];
                    $resultMeta['engine'] = 'fpdi';
                    $resultMeta['engine_label'] = 'PDF document processing (FPDI)';
                    break;

                case ToolType::COMPRESS_PDF:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $outFilename = 'output_compressed.pdf';
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    if (($meta['compression_method'] ?? 'preset') === 'target') {
                        $targetService = app(PdfTargetSizeCompressionService::class);
                        $targetBytes = (int) ($meta['target_size_bytes'] ?? 500 * 1024);
                        $priority = $meta['priority'] ?? 'balanced';

                        $res = $targetService->compressToTarget($source, $outPath, $targetBytes, $priority);

                        $outputs[] = [
                            'original_name'        => ($meta['clean_name'] ?? 'document') . '_compressed.pdf',
                            'stored_filename'      => $outFilename,
                            'mime_type'            => 'application/pdf',
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => $res['processed_size'],
                        ];

                        $resultMeta['savings_percent'] = $res['savings_percent'];
                        $resultMeta['engine'] = 'ghostscript';
                        $resultMeta['engine_label'] = 'Deep compression (Ghostscript)';
                        $resultMeta['target_status'] = $res['target_status'];
                        $resultMeta['target_achieved'] = $res['target_achieved'] ?? ($res['target_status'] === 'achieved');
                        $resultMeta['target_size_bytes'] = $res['target_size'] ?? $targetBytes;
                        $resultMeta['target_iterations'] = $res['iterations'];
                        $resultMeta['target_explanation'] = $res['explanation'] ?? null;
                        $resultMeta['diagnostics'] = $res['diagnostics'] ?? [];
                    } else {
                        $level = CompressionLevel::tryFrom($meta['compression_level'] ?? '') ?? CompressionLevel::RECOMMENDED;
                        $res = $pdfCompress->compress($source, $outPath, $level);

                        $outputs[] = [
                            'original_name'        => ($meta['clean_name'] ?? 'document') . '_compressed.pdf',
                            'stored_filename'      => $outFilename,
                            'mime_type'            => 'application/pdf',
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => $res['processed_size'],
                        ];

                        $resultMeta['savings_percent'] = $res['savings_percent'];
                        $resultMeta['engine'] = $res['engine'];
                        $resultMeta['engine_label'] = $res['is_deep'] ? 'Deep compression (Ghostscript)' : 'Limited optimization (Pure PHP)';
                        $resultMeta['notice'] = $res['notice'] ?? null;
                    }
                    break;

                case ToolType::PDF_TO_JPG:
                case ToolType::PDF_TO_PNG:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $format = ($job->tool_type === ToolType::PDF_TO_PNG) ? 'png' : 'jpg';
                    $dpi = (int) ($meta['dpi'] ?? 150);
                    $renderDir = $jobDir . DIRECTORY_SEPARATOR . 'rendered_pages';

                    $images = $pdfToImage->convertPdfToImages($source, $renderDir, $format, $dpi);

                    if (count($images) === 1) {
                        $single = $images[0];
                        $outFilename = 'output_page_1.' . $format;
                        copy($single['path'], $jobDir . DIRECTORY_SEPARATOR . $outFilename);

                        $outputs[] = [
                            'original_name'        => ($meta['clean_name'] ?? 'page') . '.' . $format,
                            'stored_filename'      => $outFilename,
                            'mime_type'            => $single['mime_type'],
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => filesize($single['path']),
                        ];
                    } else {
                        // Zip multi-page output
                        $zipPath = $jobDir . DIRECTORY_SEPARATOR . 'output_pages.zip';
                        $zipRes = $archiver->createZip($images, $zipPath);

                        $outputs[] = [
                            'original_name'        => ($meta['clean_name'] ?? 'document') . '_pages.zip',
                            'stored_filename'      => 'output_pages.zip',
                            'mime_type'            => 'application/zip',
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => $zipRes['size_bytes'],
                        ];
                    }
                    $resultMeta['engine'] = 'poppler';
                    $resultMeta['engine_label'] = 'PDF rasterization (Poppler)';
                    break;

                case ToolType::COMPRESS_IMAGE:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $ext = pathinfo($source, PATHINFO_EXTENSION);
                    $outFilename = 'output_compressed.' . $ext;
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    if (($meta['compression_method'] ?? 'preset') === 'target') {
                        $targetService = app(ImageTargetSizeCompressionService::class);
                        $targetBytes = (int) ($meta['target_size_bytes'] ?? 200 * 1024);
                        $priority = $meta['priority'] ?? 'balanced';

                        $res = $targetService->compressToTarget($source, $outPath, $targetBytes, $priority);

                        $outputs[] = [
                            'original_name'        => ($meta['clean_name'] ?? 'image') . '_compressed.' . $ext,
                            'stored_filename'      => $outFilename,
                            'mime_type'            => $res['mime_type'],
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => $res['processed_size'],
                        ];

                        $resultMeta['savings_percent'] = $res['savings_percent'];
                        $resultMeta['engine'] = 'gd';
                        $resultMeta['engine_label'] = 'Image processing (GD)';
                        $resultMeta['target_status'] = $res['target_status'];
                        $resultMeta['target_achieved'] = $res['target_achieved'] ?? ($res['target_status'] === 'achieved');
                        $resultMeta['target_size_bytes'] = $res['target_size'] ?? $targetBytes;
                        $resultMeta['target_iterations'] = $res['iterations'];
                        $resultMeta['target_explanation'] = $res['explanation'] ?? null;
                        $resultMeta['dimension_changed'] = $res['dimension_changed'] ?? false;
                        $resultMeta['dimensions'] = $res['dimensions'] ?? null;
                        $resultMeta['original_dimensions'] = $res['original_dimensions'] ?? null;
                        $resultMeta['quality_used'] = $res['quality_used'] ?? null;
                        $resultMeta['diagnostics'] = $res['diagnostics'] ?? [];
                    } else {
                        $level = CompressionLevel::tryFrom($meta['compression_level'] ?? '') ?? CompressionLevel::RECOMMENDED;
                        $res = $imageCompress->compress($source, $outPath, $level);

                        $outputs[] = [
                            'original_name'        => ($meta['clean_name'] ?? 'image') . '_compressed.' . $ext,
                            'stored_filename'      => $outFilename,
                            'mime_type'            => $res['mime_type'],
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => $res['processed_size'],
                        ];

                        $resultMeta['savings_percent'] = $res['savings_percent'];
                        $resultMeta['engine'] = 'gd';
                        $resultMeta['engine_label'] = 'Image processing (GD)';
                        $resultMeta['notice'] = $res['notice'] ?? null;
                    }
                    break;

                case ToolType::RESIZE_IMAGE:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $ext = pathinfo($source, PATHINFO_EXTENSION);
                    $outFilename = 'output_resized.' . $ext;
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    $width = !empty($meta['target_width']) ? (int) $meta['target_width'] : (!empty($meta['width']) ? (int) $meta['width'] : null);
                    $height = !empty($meta['target_height']) ? (int) $meta['target_height'] : (!empty($meta['height']) ? (int) $meta['height'] : null);
                    $percentage = !empty($meta['percentage']) ? (int) $meta['percentage'] : null;

                    if ($percentage && !$width && !$height && file_exists($source)) {
                        $dims = @getimagesize($source);
                        if ($dims && $dims[0] > 0 && $dims[1] > 0) {
                            $pct = max(1, min(500, $percentage)) / 100.0;
                            $width = (int) round($dims[0] * $pct);
                            $height = (int) round($dims[1] * $pct);
                        }
                    }

                    $fit = ImageFit::tryFrom($meta['fit'] ?? '') ?? ImageFit::CONTAIN;
                    $quality = !empty($meta['quality']) ? (int) $meta['quality'] : 85;

                    $res = $imageResize->resize($source, $outPath, $width, $height, $fit, $quality);

                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'image') . '_resized.' . $ext,
                        'stored_filename'      => $outFilename,
                        'mime_type'            => $res['mime_type'],
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['processed_size'] ?? $res['size_bytes'] ?? (file_exists($outPath) ? filesize($outPath) : 0),
                    ];
                    $resultMeta['engine'] = 'gd';
                    $resultMeta['engine_label'] = 'Image processing (GD)';
                    break;

                case ToolType::CONVERT_IMAGE:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $targetFormat = strtolower($meta['target_format'] ?? 'jpg');
                    $outFilename = 'output_converted.' . $targetFormat;
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    $res = $imageConvert->convert($source, $outPath, $targetFormat);

                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'image') . '.' . $targetFormat,
                        'stored_filename'      => $outFilename,
                        'mime_type'            => $res['mime_type'],
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['processed_size'] ?? $res['size_bytes'] ?? (file_exists($outPath) ? filesize($outPath) : 0),
                    ];
                    $resultMeta['engine'] = 'gd';
                    $resultMeta['engine_label'] = 'Image processing (GD)';
                    break;

                case ToolType::REMOVE_BACKGROUND:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $outFilename = 'output_transparent.png';
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    $bgService = app(ImageBackgroundService::class);
                    $options = $meta['options'] ?? [];
                    $res = $bgService->removeBackground($source, $outPath, $options);

                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'image') . '_nobg.png',
                        'stored_filename'      => $outFilename,
                        'mime_type'            => 'image/png',
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['size_bytes'] ?? (file_exists($outPath) ? filesize($outPath) : 0),
                    ];
                    $resultMeta['engine'] = $res['engine'];
                    $resultMeta['engine_label'] = $res['engine_label'];
                    $resultMeta['is_ai'] = $res['is_ai'];
                    break;

                case ToolType::CHANGE_BACKGROUND:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $bgConfig = $meta['background_config'] ?? ['type' => 'solid', 'color' => '#FFFFFF'];

                    if (($bgConfig['type'] ?? '') === 'image' && !empty($bgConfig['image_file'])) {
                        $bgConfig['image_path'] = $jobDir . DIRECTORY_SEPARATOR . $bgConfig['image_file'];
                    }

                    $ext = ($bgConfig['type'] ?? '') === 'transparent' ? 'png' : 'png';
                    $outFilename = 'output_changed.' . $ext;
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    $bgService = app(ImageBackgroundService::class);
                    $options = $meta['options'] ?? [];
                    $res = $bgService->changeBackground($source, $outPath, $bgConfig, $options);

                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'image') . '_custom_bg.' . $ext,
                        'stored_filename'      => $outFilename,
                        'mime_type'            => 'image/png',
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['size_bytes'] ?? (file_exists($outPath) ? filesize($outPath) : 0),
                    ];
                    $resultMeta['engine'] = $res['engine'];
                    $resultMeta['engine_label'] = $res['engine_label'];
                    $resultMeta['is_ai'] = $res['is_ai'];
                    break;
            }

            $durationMs = (int) round((hrtime(true) - $startTime) / 1e6);

            // Persist output files & transition state atomically
            DB::transaction(function () use ($job, $outputs, $resultMeta, $durationMs, $totalInputBytes) {
                ProcessedFile::where('file_job_id', $job->id)->delete();

                foreach ($outputs as $out) {
                    ProcessedFile::create([
                        'file_job_id'          => $job->id,
                        'original_name'        => $out['original_name'],
                        'stored_filename'      => $out['stored_filename'],
                        'disk'                 => config('fileprocessor.storage.disk', 'local'),
                        'mime_type'            => $out['mime_type'],
                        'original_size_bytes'  => $out['original_size_bytes'],
                        'processed_size_bytes' => $out['processed_size_bytes'],
                    ]);
                }

                $job->update([
                    'status'                 => JobStatus::COMPLETED,
                    'processing_finished_at' => now(),
                    'duration_ms'            => $durationMs,
                    'input_metadata'         => $resultMeta,
                    'error_message'          => null,
                ]);

                $ip = $job->input_metadata['ip'] ?? '127.0.0.1';
                ToolUsage::log(
                    toolType: $job->tool_type->value,
                    ip: $ip,
                    successful: true,
                    bytes: $totalInputBytes,
                    durationMs: $durationMs,
                    fileJobId: $job->id
                );
            });

            $this->releaseLockSafely($lock);
            Log::info("ProcessFileJob: Successfully completed job {$job->id} in {$durationMs}ms.");
        } catch (Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $startTime) / 1e6);
            Log::error("ProcessFileJob: Failed execution for job {$this->fileJobId}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            $job = FileJob::find($this->fileJobId);
            if ($job) {
                $job->update([
                    'status'                 => JobStatus::FAILED,
                    'processing_finished_at' => now(),
                    'duration_ms'            => $durationMs,
                    'error_message'          => $e->getMessage(),
                ]);

                $ip = $job->input_metadata['ip'] ?? '127.0.0.1';
                ToolUsage::log(
                    toolType: $job->tool_type->value,
                    ip: $ip,
                    successful: false,
                    bytes: 0,
                    durationMs: $durationMs,
                    fileJobId: $job->id
                );
            }

            $this->releaseLockSafely($lock);
            throw $e;
        }
    }

    protected function releaseLockSafely(?\Illuminate\Contracts\Cache\Lock $lock): void
    {
        if (!$lock) {
            return;
        }
        try {
            $lock->release();
        } catch (Throwable $e) {
            Log::debug("ProcessFileJob: Lock release note: {$e->getMessage()}");
        }
    }

    protected function cleanPreviousOutputs(FileJob $job, string $jobDir): void
    {
        $patterns = [
            'output_*.*',
            'rendered_pages',
            'split_pages',
            'split_part_*.pdf',
            'split_interval_*.pdf',
        ];

        foreach ($patterns as $pattern) {
            $matches = glob($jobDir . DIRECTORY_SEPARATOR . $pattern);
            if ($matches) {
                foreach ($matches as $match) {
                    if (is_dir($match)) {
                        $this->deleteDirRecursively($match);
                    } elseif (is_file($match)) {
                        @unlink($match);
                    }
                }
            }
        }
    }

    protected function deleteDirRecursively(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($target) ? $this->deleteDirRecursively($target) : @unlink($target);
        }
        return @rmdir($dir);
    }
}
