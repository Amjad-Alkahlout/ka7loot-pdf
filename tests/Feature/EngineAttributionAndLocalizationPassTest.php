<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Jobs\ProcessFileJob;
use App\Livewire\Tools\CompressImageComponent;
use App\Livewire\Tools\CompressPdfComponent;
use App\Livewire\Tools\ConvertImageComponent;
use App\Livewire\Tools\JpgToPdfComponent;
use App\Livewire\Tools\ResizeImageComponent;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\File\FileStorageManager;
use App\Services\Image\ImageTargetSizeCompressionService;
use App\Services\Pdf\PdfTargetSizeCompressionService;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EngineAttributionAndLocalizationPassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function createTestImage(int $width = 400, int $height = 300, string $filename = 'test.jpg'): UploadedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'img_test_');
        $img = imagecreatetruecolor($width, $height);
        $blue = imagecolorallocate($img, 30, 100, 200);
        imagefill($img, 0, 0, $blue);
        imagejpeg($img, $tempPath, 90);
        imagedestroy($img);

        return new UploadedFile($tempPath, $filename, 'image/jpeg', null, true);
    }

    protected function createTestPdf(int $pages = 3, string $filename = 'sample.pdf'): UploadedFile
    {
        $fpdf = new \FPDF();
        for ($i = 1; $i <= $pages; $i++) {
            $fpdf->AddPage();
            $fpdf->SetFont('Helvetica', 'B', 16);
            $fpdf->Cell(40, 10, "Test Page {$i}");
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'pdf_test_');
        $fpdf->Output($tempPath, 'F');

        return new UploadedFile($tempPath, $filename, 'application/pdf', null, true);
    }

    public function test_image_tools_never_display_ghostscript_engine(): void
    {
        $storage = app(FileStorageManager::class);

        // 1. Compress Image
        $jobId = Str::uuid()->toString();
        $jobDir = $storage->getJobDirectory($jobId);
        $imageFile = $this->createTestImage(400, 300, 'photo.jpg');
        copy($imageFile->getRealPath(), $jobDir . DIRECTORY_SEPARATOR . 'photo.jpg');

        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::COMPRESS_IMAGE,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'file'              => 'photo.jpg',
                'clean_name'        => 'photo',
                'compression_level' => 'recommended',
                'ip'                => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        app()->call([new ProcessFileJob($job->id), 'handle']);
        $job->refresh();

        $processedFiles = ProcessedFile::where('file_job_id', $job->id)->get();
        $view = view('livewire.tools.job-result', [
            'job'            => $job,
            'processedFiles' => $processedFiles,
        ])->render();

        $this->assertStringNotContainsStringIgnoringCase('Ghostscript', $view);
        $this->assertStringContainsString('GD', $view);

        // 2. Resize Image
        $jobId2 = Str::uuid()->toString();
        $jobDir2 = $storage->getJobDirectory($jobId2);
        copy($imageFile->getRealPath(), $jobDir2 . DIRECTORY_SEPARATOR . 'photo2.jpg');

        $job2 = FileJob::create([
            'id'             => $jobId2,
            'tool_type'      => ToolType::RESIZE_IMAGE,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'file'         => 'photo2.jpg',
                'clean_name'   => 'photo2',
                'target_width' => 200,
                'ip'           => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        app()->call([new ProcessFileJob($job2->id), 'handle']);
        $job2->refresh();

        $processedFiles2 = ProcessedFile::where('file_job_id', $job2->id)->get();
        $view2 = view('livewire.tools.job-result', [
            'job'            => $job2,
            'processedFiles' => $processedFiles2,
        ])->render();

        $this->assertStringNotContainsStringIgnoringCase('Ghostscript', $view2);
        $this->assertStringContainsString('GD', $view2);

        // 3. Convert Image
        $jobId3 = Str::uuid()->toString();
        $jobDir3 = $storage->getJobDirectory($jobId3);
        copy($imageFile->getRealPath(), $jobDir3 . DIRECTORY_SEPARATOR . 'photo3.jpg');

        $job3 = FileJob::create([
            'id'             => $jobId3,
            'tool_type'      => ToolType::CONVERT_IMAGE,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'file'          => 'photo3.jpg',
                'clean_name'    => 'photo3',
                'target_format' => 'png',
                'ip'            => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        app()->call([new ProcessFileJob($job3->id), 'handle']);
        $job3->refresh();

        $processedFiles3 = ProcessedFile::where('file_job_id', $job3->id)->get();
        $view3 = view('livewire.tools.job-result', [
            'job'            => $job3,
            'processedFiles' => $processedFiles3,
        ])->render();

        $this->assertStringNotContainsStringIgnoringCase('Ghostscript', $view3);
        $this->assertStringContainsString('GD', $view3);

        $storage->deleteJobDirectory($jobId);
        $storage->deleteJobDirectory($jobId2);
        $storage->deleteJobDirectory($jobId3);
    }

    public function test_jpg_to_pdf_displays_fpdi_engine(): void
    {
        $storage = app(FileStorageManager::class);
        $jobId = Str::uuid()->toString();
        $jobDir = $storage->getJobDirectory($jobId);

        $img1 = $this->createTestImage(200, 200, 'img1.jpg');
        copy($img1->getRealPath(), $jobDir . DIRECTORY_SEPARATOR . 'img1.jpg');

        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::JPG_TO_PDF,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'files'           => ['img1.jpg'],
                'output_filename' => 'images.pdf',
                'ip'              => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        app()->call([new ProcessFileJob($job->id), 'handle']);
        $job->refresh();

        $processedFiles = ProcessedFile::where('file_job_id', $job->id)->get();
        $view = view('livewire.tools.job-result', [
            'job'            => $job,
            'processedFiles' => $processedFiles,
        ])->render();

        $this->assertStringNotContainsStringIgnoringCase('Ghostscript', $view);
        $this->assertStringContainsString('FPDI', $view);

        $storage->deleteJobDirectory($jobId);
    }

    public function test_target_size_image_compression_service(): void
    {
        $service = app(ImageTargetSizeCompressionService::class);
        $tempInput = tempnam(sys_get_temp_dir(), 'target_img_');
        $tempOutput = tempnam(sys_get_temp_dir(), 'target_out_');

        // Create a 800x600 image
        $img = imagecreatetruecolor(800, 600);
        $color = imagecolorallocate($img, 120, 200, 80);
        imagefill($img, 0, 0, $color);
        imagejpeg($img, $tempInput, 95);
        imagedestroy($img);

        $origSize = filesize($tempInput);
        $targetBytes = (int) round($origSize * 0.5); // Target 50% reduction

        $result = $service->compressToTarget($tempInput, $tempOutput, $targetBytes, 'balanced');

        $this->assertFileExists($tempOutput);
        $this->assertLessThan($origSize, $result['processed_size']);
        $this->assertEquals('gd', $result['engine']);
        $this->assertContains($result['target_status'], ['achieved', 'closest']);
        $this->assertGreaterThan(0, $result['iterations']);

        @unlink($tempInput);
        @unlink($tempOutput);
    }

    public function test_target_size_pdf_compression_service(): void
    {
        $detector = app(SystemBinaryDetector::class);
        if (!$detector->hasGhostscript()) {
            $this->markTestSkipped('Ghostscript not available on host');
        }

        $service = app(PdfTargetSizeCompressionService::class);
        $pdfFile = $this->createTestPdf(4, 'large_sample.pdf');
        $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_target_out_');

        $origSize = filesize($pdfFile->getRealPath());
        $targetBytes = (int) round($origSize * 0.7);

        $result = $service->compressToTarget($pdfFile->getRealPath(), $tempOutput, $targetBytes, 'balanced');

        $this->assertFileExists($tempOutput);
        $this->assertEquals('ghostscript', $result['engine']);
        $this->assertContains($result['target_status'], ['achieved', 'closest', 'not_achieved']);

        @unlink($tempOutput);
    }

    public function test_jpg_to_pdf_reordering_and_mapping(): void
    {
        $img1 = UploadedFile::fake()->image('first.jpg', 100, 100);
        $img2 = UploadedFile::fake()->image('second.jpg', 150, 150);

        $component = Livewire::test(JpgToPdfComponent::class)
            ->set('uploads', [$img1, $img2]);

        // Check initial order
        $initialImages = $component->get('uploads');
        $this->assertCount(2, $initialImages);
        $this->assertEquals('first.jpg', $initialImages[0]->getClientOriginalName());
        $this->assertEquals('second.jpg', $initialImages[1]->getClientOriginalName());

        // Move first image down
        $component->call('moveDown', 0);
        $reordered = $component->get('uploads');
        $this->assertEquals('second.jpg', $reordered[0]->getClientOriginalName());
        $this->assertEquals('first.jpg', $reordered[1]->getClientOriginalName());

        // Move second image back up
        $component->call('moveUp', 1);
        $restored = $component->get('uploads');
        $this->assertEquals('first.jpg', $restored[0]->getClientOriginalName());
        $this->assertEquals('second.jpg', $restored[1]->getClientOriginalName());
    }

    public function test_arabic_localization_no_unintended_english_on_pages(): void
    {
        App::setLocale('ar');

        $routes = [
            '/',
            '/merge-pdf',
            '/split-pdf',
            '/compress-pdf',
            '/jpg-to-pdf',
            '/organize-pdf',
            '/pdf-to-jpg',
            '/pdf-to-png',
            '/compress-image',
            '/resize-image',
            '/convert-image',
        ];

        foreach ($routes as $url) {
            $response = $this->withSession(['locale' => 'ar'])->get($url);
            $response->assertStatus(200);

            $content = $response->getContent();

            // Assert proper Arabic page direction
            $this->assertStringContainsString('dir="rtl"', $content);

            // Assert unintended English strings are NOT present
            $this->assertStringNotContainsString('All rights reserved', $content);
            $this->assertStringNotContainsString('Get Started &rarr;', $content);
        }

        App::setLocale('en');
    }

    public function test_resize_image_job_handles_missing_fit_and_percentage_safely(): void
    {
        $storage = app(FileStorageManager::class);
        $jobId = Str::uuid()->toString();
        $jobDir = $storage->getJobDirectory($jobId);

        $imageFile = $this->createTestImage(500, 400, 'sample_resize.jpg');
        copy($imageFile->getRealPath(), $jobDir . DIRECTORY_SEPARATOR . 'source.jpg');

        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::RESIZE_IMAGE,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'file'         => 'source.jpg',
                'clean_name'   => 'sample_resize',
                'target_width' => 250,
                'ip'           => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        app()->call([new ProcessFileJob($job->id), 'handle']);

        $job->refresh();
        $this->assertEquals(JobStatus::COMPLETED, $job->status);

        $processed = ProcessedFile::where('file_job_id', $job->id)->first();
        $this->assertNotNull($processed);
        $this->assertFileExists($jobDir . DIRECTORY_SEPARATOR . $processed->stored_filename);

        $storage->deleteJobDirectory($jobId);
    }
}
