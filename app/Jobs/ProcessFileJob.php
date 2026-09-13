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
use App\Services\Image\ImageCompressionService;
use App\Services\Image\ImageConversionService;
use App\Services\Image\ImageResizeService;
use App\Services\Pdf\JpgToPdfService;
use App\Services\Pdf\PdfCompressionService;
use App\Services\Pdf\PdfMergeService;
use App\Services\Pdf\PdfOrganizeService;
use App\Services\Pdf\PdfSplitService;
use App\Services\Pdf\PdfToImageService;
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
    public array $backoff = [5, 30, 60];

    public function __construct(
        public string $fileJobId
    ) {}

    public function handle(
        FileStorageManager $storage,
        PdfMergeService $pdfMerge,
        PdfSplitService $pdfSplit,
        PdfOrganizeService $pdfOrganize,
        JpgToPdfService $jpgToPdf,
        PdfCompressionService $pdfCompress,
        PdfToImageService $pdfToImage,
        ImageCompressionService $imageCompress,
        ImageResizeService $imageResize,
        ImageConversionService $imageConvert,
        ArchiveGeneratorService $archiver
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
                $lock->release();
                return;
            }

            // Idempotency: if already completed, exit cleanly without repeating work
            if ($job->status === JobStatus::COMPLETED) {
                Log::info("ProcessFileJob: Job {$this->fileJobId} is already marked as completed. Skipping.");
                $lock->release();
                return;
            }

            // Atomic state transition: pending -> processing
            $updated = DB::table('file_jobs')
                ->where('id', $job->id)
                ->whereIn('status', [JobStatus::PENDING->value, JobStatus::FAILED->value, JobStatus::PROCESSING->value])
                ->update([
                    'status' => JobStatus::PROCESSING->value,
                    'updated_at' => now(),
                ]);

            if (!$updated) {
                $lock->release();
                return;
            }

            $meta = $job->input_metadata ?? [];
            $jobDir = $storage->getJobDirectory($job->id);

            // Clean up any previously created output files to guarantee idempotency on retry
            $this->cleanPreviousOutputs($job);

            $outputs = [];
            $totalInputBytes = 0;

            switch ($job->tool_type) {
                case ToolType::MERGE_PDF:
                    $sources = array_map(fn($f) => $jobDir . DIRECTORY_SEPARATOR . $f, $meta['files'] ?? []);
                    $totalInputBytes = array_sum(array_map('filesize', array_filter($sources, 'file_exists')));
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . 'output_merged.pdf';

                    $res = $pdfMerge->merge($sources, $outPath);
                    $outputs[] = [
                        'original_name'        => $meta['output_filename'] ?? 'merged.pdf',
                        'stored_filename'      => 'output_merged.pdf',
                        'mime_type'            => 'application/pdf',
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['size_bytes'],
                    ];
                    break;

                case ToolType::SPLIT_PDF:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $mode = $meta['mode'] ?? 'all'; // 'all' or 'range'

                    if ($mode === 'range') {
                        $range = $meta['range'] ?? '1';
                        $pages = $pdfSplit->parsePageRange($range, 10000);
                        $outPath = $jobDir . DIRECTORY_SEPARATOR . 'output_split_range.pdf';
                        $res = $pdfSplit->extractPages($source, $outPath, $pages);

                        $outputs[] = [
                            'original_name'        => 'split_pages_' . preg_replace('/[^0-9\-]/', '_', $range) . '.pdf',
                            'stored_filename'      => 'output_split_range.pdf',
                            'mime_type'            => 'application/pdf',
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => $res['size_bytes'],
                        ];
                    } else {
                        // Split all pages and zip them
                        $splitDir = $jobDir . DIRECTORY_SEPARATOR . 'split_pages';
                        $splitFiles = $pdfSplit->splitAllPages($source, $splitDir);
                        $zipPath = $jobDir . DIRECTORY_SEPARATOR . 'output_split_pages.zip';
                        $zipRes = $archiver->createZip($splitFiles, $zipPath);

                        $outputs[] = [
                            'original_name'        => ($meta['clean_name'] ?? 'document') . '_split.zip',
                            'stored_filename'      => 'output_split_pages.zip',
                            'mime_type'            => 'application/zip',
                            'original_size_bytes'  => $totalInputBytes,
                            'processed_size_bytes' => $zipRes['size_bytes'],
                        ];
                    }
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
                    break;

                case ToolType::COMPRESS_PDF:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $level = CompressionLevel::tryFrom($meta['compression_level'] ?? '') ?? CompressionLevel::RECOMMENDED;
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . 'output_compressed.pdf';

                    $res = $pdfCompress->compress($source, $outPath, $level);
                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'document') . '_compressed.pdf',
                        'stored_filename'      => 'output_compressed.pdf',
                        'mime_type'            => 'application/pdf',
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['processed_size'],
                    ];
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
                    break;

                case ToolType::COMPRESS_IMAGE:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $level = CompressionLevel::tryFrom($meta['compression_level'] ?? '') ?? CompressionLevel::RECOMMENDED;
                    $ext = pathinfo($source, PATHINFO_EXTENSION);
                    $outFilename = 'output_compressed.' . $ext;
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    $res = $imageCompress->compress($source, $outPath, $level);
                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'image') . '_compressed.' . $ext,
                        'stored_filename'      => $outFilename,
                        'mime_type'            => $res['mime_type'],
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['processed_size'],
                    ];
                    break;

                case ToolType::RESIZE_IMAGE:
                    $source = $jobDir . DIRECTORY_SEPARATOR . ($meta['file'] ?? '');
                    $totalInputBytes = file_exists($source) ? filesize($source) : 0;
                    $w = !empty($meta['target_width']) ? (int) $meta['target_width'] : null;
                    $h = !empty($meta['target_height']) ? (int) $meta['target_height'] : null;
                    $fit = ImageFit::tryFrom($meta['fit'] ?? '') ?? ImageFit::CONTAIN;
                    $ext = pathinfo($source, PATHINFO_EXTENSION);
                    $outFilename = 'output_resized.' . $ext;
                    $outPath = $jobDir . DIRECTORY_SEPARATOR . $outFilename;

                    $res = $imageResize->resize($source, $outPath, $w, $h, $fit);
                    $outputs[] = [
                        'original_name'        => ($meta['clean_name'] ?? 'image') . '_resized.' . $ext,
                        'stored_filename'      => $outFilename,
                        'mime_type'            => $res['mime_type'],
                        'original_size_bytes'  => $totalInputBytes,
                        'processed_size_bytes' => $res['processed_size'],
                    ];
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
                        'processed_size_bytes' => $res['processed_size'],
                    ];
                    break;
            }

            // Duration in milliseconds
            $durationMs = (int) round((hrtime(true) - $startTime) / 1e6);

            // Persist output records & transition state atomically
            DB::transaction(function () use ($job, $outputs, $durationMs, $totalInputBytes) {
                // Delete existing records to avoid duplicates on retry
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
                    'status'        => JobStatus::COMPLETED,
                    'duration_ms'   => $durationMs,
                    'error_message' => null,
                ]);

                // Record idempotent telemetry
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

            Log::info("ProcessFileJob: Successfully completed job {$job->id} in {$durationMs}ms.");
        } catch (Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $startTime) / 1e6);
            Log::error("ProcessFileJob: Failed execution for job {$this->fileJobId}: {$e->getMessage()}");

            $job = FileJob::find($this->fileJobId);
            if ($job) {
                $job->update([
                    'status'        => JobStatus::FAILED,
                    'duration_ms'   => $durationMs,
                    'error_message' => $e->getMessage(),
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

            $lock->release();
            throw $e;
        }

        $lock->release();
    }

    /**
     * Clean up previous outputs before starting processing to prevent duplicates on retries.
     */
    protected function cleanPreviousOutputs(FileJob $job): void
    {
        $existing = ProcessedFile::where('file_job_id', $job->id)->get();
        $storage = app(FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($job->id);

        foreach ($existing as $file) {
            $path = $jobDir . DIRECTORY_SEPARATOR . $file->stored_filename;
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        // Clean up common output files
        $patterns = ['output_*.*', 'rendered_pages', 'split_pages'];
        foreach ($patterns as $pattern) {
            $matches = glob($jobDir . DIRECTORY_SEPARATOR . $pattern);
            foreach ($matches as $match) {
                if (is_dir($match)) {
                    $this->deleteDirRecursively($match);
                } elseif (is_file($match)) {
                    @unlink($match);
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
