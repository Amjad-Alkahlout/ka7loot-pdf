<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Livewire\Tools\HeicToPdfComponent;
use App\Livewire\Tools\JpgToPdfComponent;
use App\Livewire\Tools\MergePdfComponent;
use App\Services\Pdf\PdfThumbnailService;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class Phase2MultiFileGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    protected function createValidPdf(string $filename, string $text = 'Sample'): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'test_pdf_') . '.pdf';
        $this->tempFiles[] = $temp;

        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(40, 10, $text);
        $pdf->Output($temp, 'F');

        return UploadedFile::fake()->createWithContent($filename, file_get_contents($temp));
    }

    public function test_merge_pdf_renders_gallery_grid_and_signed_previews(): void
    {
        $pdf1 = $this->createValidPdf('doc1.pdf', 'Document 1');
        $pdf2 = $this->createValidPdf('doc2.pdf', 'Document 2');
        $pdf3 = $this->createValidPdf('doc3.pdf', 'Document 3');

        $component = Livewire::test(MergePdfComponent::class)
            ->set('uploads', [$pdf1, $pdf2, $pdf3])
            ->assertSet('jobStatus', 'uploaded')
            ->assertSee('doc1.pdf')
            ->assertSee('doc2.pdf')
            ->assertSee('doc3.pdf')
            ->assertSee('x-sortable="onReorder"', false)
            ->assertSee('data-sortable-handle=".drag-handle"', false)
            ->assertSee('id="pdf-inspect-modal"', false)
            ->assertDontSee(':src="highResUrl"', false)
            ->assertSee(':src="url"', false)
            ->assertDontSee(':src="src"', false)
            ->assertSee('refreshUrl:', false);

        // Preview URL generation
        $url0 = $component->instance()->getPreviewUrl(0, 1, 120);
        $this->assertNotNull($url0);
        $this->assertStringContainsString('session_id=', $url0);
        $this->assertStringContainsString('page=1', $url0);

        // High-res URL generation
        $highRes0 = $component->instance()->getHighResPreviewUrl(0, 1);
        $this->assertNotNull($highRes0);
        $this->assertStringContainsString('dpi=220', $highRes0);

        $this->assertNotNull($component->instance()->getPreviewUrlForFilename(
            $component->get('uploads')[0]->getFilename(),
            1,
            120
        ));
        $this->assertNull($component->instance()->getPreviewUrlForFilename('not-selected.pdf', 1, 120));

        $files = $component->get('uploads');
        $thumbnailService = app(PdfThumbnailService::class);
        $preview1 = $thumbnailService->getCachedPreviewPath($files[0]->getRealPath(), 1, 120, 85);
        $preview2 = $thumbnailService->getCachedPreviewPath($files[1]->getRealPath(), 1, 120, 85);
        @mkdir(dirname($preview1), 0755, true);
        @mkdir(dirname($preview2), 0755, true);
        file_put_contents($preview1, 'preview-one');
        file_put_contents($preview2, 'preview-two');

        try {
            $removedFilename = $files[0]->getFilename();
            $preservedFilename = $files[1]->getFilename();
            $component->call('removeFile', 0);

            $this->assertFileDoesNotExist($preview1);
            $this->assertFileExists($preview2);
            $this->assertNull($component->instance()->getPreviewUrlForFilename($removedFilename, 1, 120));
            $this->assertNotNull($component->instance()->getPreviewUrlForFilename($preservedFilename, 1, 120));
        } finally {
            $thumbnailService->deleteThumbnailsForPdf($files[0]->getRealPath());
            $thumbnailService->deleteThumbnailsForPdf($files[1]->getRealPath());
        }
    }

    public function test_merge_pdf_enforces_max_file_count_across_the_selected_job(): void
    {
        config(['fileprocessor.limits.max_batch_files' => 2]);
        $uploads = [
            UploadedFile::fake()->create('one.pdf', 1),
            UploadedFile::fake()->create('two.pdf', 1),
            UploadedFile::fake()->create('three.pdf', 1),
        ];

        Livewire::test(MergePdfComponent::class)
            ->set('uploads', $uploads)
            ->call('process')
            ->assertHasErrors('uploads');
    }

    public function test_jpg_to_pdf_enforces_total_size_across_sequential_additions(): void
    {
        config(['fileprocessor.limits.max_total_batch_kb' => 10]);
        $uploads = [
            UploadedFile::fake()->image('one.jpg')->size(6),
            UploadedFile::fake()->image('two.jpg')->size(6),
        ];

        Livewire::test(JpgToPdfComponent::class)
            ->set('uploads', $uploads)
            ->call('process')
            ->assertHasErrors('uploads');
    }

    public function test_merge_pdf_reorder_uploads_strictly_validates_and_reorders(): void
    {
        $pdf1 = $this->createValidPdf('alpha.pdf', 'Alpha');
        $pdf2 = $this->createValidPdf('beta.pdf', 'Beta');
        $pdf3 = $this->createValidPdf('gamma.pdf', 'Gamma');

        $component = Livewire::test(MergePdfComponent::class)
            ->set('uploads', [$pdf1, $pdf2, $pdf3]);

        $uploads = $component->get('uploads');
        $id1 = $uploads[0]->getFilename();
        $id2 = $uploads[1]->getFilename();
        $id3 = $uploads[2]->getFilename();

        // 1. Valid reordering: [gamma, alpha, beta]
        $component->call('reorderUploads', [$id3, $id1, $id2]);
        $reordered = $component->get('uploads');
        $this->assertSame('gamma.pdf', $reordered[0]->getClientOriginalName());
        $this->assertSame('alpha.pdf', $reordered[1]->getClientOriginalName());
        $this->assertSame('beta.pdf', $reordered[2]->getClientOriginalName());

        // 2. Incomplete payload (missing ID): should safely reject and preserve order
        $component->call('reorderUploads', [$id1, $id2]);
        $unchanged = $component->get('uploads');
        $this->assertSame('gamma.pdf', $unchanged[0]->getClientOriginalName());
        $this->assertSame('alpha.pdf', $unchanged[1]->getClientOriginalName());
        $this->assertSame('beta.pdf', $unchanged[2]->getClientOriginalName());

        // 3. Duplicate ID in payload: should safely reject
        $component->call('reorderUploads', [$id1, $id1, $id2]);
        $unchanged2 = $component->get('uploads');
        $this->assertSame('gamma.pdf', $unchanged2[0]->getClientOriginalName());

        // 4. Unexpected foreign ID in payload: should safely reject
        $component->call('reorderUploads', [$id1, $id2, 'bogus_id_999']);
        $unchanged3 = $component->get('uploads');
        $this->assertSame('gamma.pdf', $unchanged3[0]->getClientOriginalName());
    }

    public function test_merge_pdf_move_controls_and_append_via_new_files(): void
    {
        $pdf1 = $this->createValidPdf('first.pdf', 'First');
        $pdf2 = $this->createValidPdf('second.pdf', 'Second');

        $component = Livewire::test(MergePdfComponent::class)
            ->set('uploads', [$pdf1, $pdf2]);

        // Move first down
        $component->call('moveDown', 0);
        $this->assertSame('second.pdf', $component->get('uploads')[0]->getClientOriginalName());
        $this->assertSame('first.pdf', $component->get('uploads')[1]->getClientOriginalName());

        // Move second back up
        $component->call('moveUp', 1);
        $this->assertSame('first.pdf', $component->get('uploads')[0]->getClientOriginalName());
        $this->assertSame('second.pdf', $component->get('uploads')[1]->getClientOriginalName());

        // Append third file via newFiles
        $pdf3 = $this->createValidPdf('third.pdf', 'Third');
        $component->set('newFiles', [$pdf3]);

        $this->assertCount(3, $component->get('uploads'));
        $this->assertSame('first.pdf', $component->get('uploads')[0]->getClientOriginalName());
        $this->assertSame('second.pdf', $component->get('uploads')[1]->getClientOriginalName());
        $this->assertSame('third.pdf', $component->get('uploads')[2]->getClientOriginalName());

        // Remove upload
        $component->call('removeUpload', 1);
        $this->assertCount(2, $component->get('uploads'));
        $this->assertSame('first.pdf', $component->get('uploads')[0]->getClientOriginalName());
        $this->assertSame('third.pdf', $component->get('uploads')[1]->getClientOriginalName());

        $pdf4 = $this->createValidPdf('fourth.pdf', 'Fourth');
        $component->set('newFiles', [$pdf4]);
        $this->assertSame(['first.pdf', 'third.pdf', 'fourth.pdf'], array_map(
            fn ($file) => $file->getClientOriginalName(),
            $component->get('uploads')
        ));
        $component->assertSee('showUploadControl()', false)
            ->assertSee('compact: true', false);
    }

    public function test_merge_pdf_arabic_rtl_layout(): void
    {
        App::setLocale('ar');

        $pdf1 = $this->createValidPdf('ar1.pdf', 'Doc 1');
        $pdf2 = $this->createValidPdf('ar2.pdf', 'Doc 2');

        Livewire::test(MergePdfComponent::class)
            ->set('uploads', [$pdf1, $pdf2])
            ->assertSee('دمج ملفات PDF')
            ->assertSee('اسحب المستندات أو استخدم الأسهم لإعادة ترتيب صفحات الدمج')
            ->assertSee('rtl:rotate-180', false);
    }

    public function test_jpg_to_pdf_renders_gallery_grid_and_sortable(): void
    {
        $img1 = UploadedFile::fake()->image('photo1.jpg', 600, 400);
        $img2 = UploadedFile::fake()->image('photo2.png', 800, 600);
        $img3 = UploadedFile::fake()->image('photo3.webp', 1000, 800);

        $component = Livewire::test(JpgToPdfComponent::class)
            ->set('uploads', [$img1, $img2, $img3])
            ->assertSet('jobStatus', 'uploaded')
            ->assertSee('photo1.jpg')
            ->assertSee('photo2.png')
            ->assertSee('photo3.webp')
            ->assertSee('x-sortable="onReorder"', false)
            ->assertSee('data-sortable-handle=".drag-handle"', false)
            ->assertSee('object-contain', false);

        $uploads = $component->get('uploads');
        $id1 = $uploads[0]->getFilename();
        $id2 = $uploads[1]->getFilename();
        $id3 = $uploads[2]->getFilename();

        // Reorder via SortableJS handler
        $component->call('reorderUploads', [$id3, $id2, $id1]);
        $reordered = $component->get('uploads');
        $this->assertSame('photo3.webp', $reordered[0]->getClientOriginalName());
        $this->assertSame('photo2.png', $reordered[1]->getClientOriginalName());
        $this->assertSame('photo1.jpg', $reordered[2]->getClientOriginalName());

        // Incomplete order rejected safely
        $component->call('reorderUploads', [$id1, $id2]);
        $this->assertSame('photo3.webp', $component->get('uploads')[0]->getClientOriginalName());

        // Append via newFiles
        $img4 = UploadedFile::fake()->image('photo4.jpg', 500, 500);
        $component->set('newFiles', [$img4]);
        $this->assertCount(4, $component->get('uploads'));
        $this->assertSame('photo4.jpg', $component->get('uploads')[3]->getClientOriginalName());

        $component->call('removeFile', 1);
        $this->assertSame(['photo3.webp', 'photo1.jpg', 'photo4.jpg'], array_map(
            fn ($file) => $file->getClientOriginalName(),
            $component->get('uploads')
        ));

        $img5 = UploadedFile::fake()->image('photo5.png', 500, 500);
        $component->set('newFiles', [$img5]);
        $this->assertSame(['photo3.webp', 'photo1.jpg', 'photo4.jpg', 'photo5.png'], array_map(
            fn ($file) => $file->getClientOriginalName(),
            $component->get('uploads')
        ));
        $component->assertSee('showUploadControl()', false)
            ->assertSee('compact: true', false);
    }

    public function test_jpg_to_pdf_arabic_rtl_layout(): void
    {
        App::setLocale('ar');

        $img1 = UploadedFile::fake()->image('pic1.jpg', 400, 400);
        $img2 = UploadedFile::fake()->image('pic2.jpg', 400, 400);

        Livewire::test(JpgToPdfComponent::class)
            ->set('uploads', [$img1, $img2])
            ->assertSee('تحويل JPG إلى PDF')
            ->assertSee('اسحب الصور أو استخدم الأسهم لإعادة ترتيب صفحات مستند PDF الناتج')
            ->assertSee('rtl:rotate-180', false);
    }

    public function test_heic_to_pdf_gallery_grid_and_reorder_validation(): void
    {
        $heic1 = UploadedFile::fake()->create('heic1.heic', 300, 'image/heic');
        $heic2 = UploadedFile::fake()->create('heic2.heic', 400, 'image/heic');
        $heic3 = UploadedFile::fake()->create('heic3.heic', 500, 'image/heic');

        $component = Livewire::test(HeicToPdfComponent::class)
            ->set('newFiles', [$heic1, $heic2, $heic3])
            ->assertCount('uploads', 3)
            ->assertSee('heic1.heic')
            ->assertSee('heic2.heic')
            ->assertSee('heic3.heic')
            ->assertSee('x-sortable="onReorder"', false)
            ->assertSee('data-sortable-handle=".drag-handle"', false)
            ->assertSee('object-contain', false);

        $uploads = $component->get('uploads');
        $id1 = $uploads[0]->getFilename();
        $id2 = $uploads[1]->getFilename();
        $id3 = $uploads[2]->getFilename();

        // Reorder via SortableJS handler
        $component->call('reorderFiles', [$id2, $id3, $id1]);
        $reordered = $component->get('uploads');
        $this->assertSame('heic2.heic', $reordered[0]->getClientOriginalName());
        $this->assertSame('heic3.heic', $reordered[1]->getClientOriginalName());
        $this->assertSame('heic1.heic', $reordered[2]->getClientOriginalName());

        // Incomplete payload rejected safely
        $component->call('reorderFiles', [$id1, $id2]);
        $this->assertSame('heic2.heic', $component->get('uploads')[0]->getClientOriginalName());

        // Duplicate payload rejected safely
        $component->call('reorderFiles', [$id1, $id1, $id2]);
        $this->assertSame('heic2.heic', $component->get('uploads')[0]->getClientOriginalName());

        $component->call('removeFile', 1);
        $heic4 = UploadedFile::fake()->create('heic4.heic', 600, 'image/heic');
        $component->set('newFiles', [$heic4]);
        $this->assertSame(['heic2.heic', 'heic1.heic', 'heic4.heic'], array_map(
            fn ($file) => $file->getClientOriginalName(),
            $component->get('uploads')
        ));
        $component->assertSee('showUploadControl()', false)
            ->assertSee('queueBatches: true', false);
    }

    public function test_heic_to_pdf_arabic_rtl_layout(): void
    {
        App::setLocale('ar');

        $heic1 = UploadedFile::fake()->create('photo_ar1.heic', 300, 'image/heic');
        $heic2 = UploadedFile::fake()->create('photo_ar2.heic', 400, 'image/heic');

        Livewire::test(HeicToPdfComponent::class)
            ->set('newFiles', [$heic1, $heic2])
            ->assertSee('تحويل HEIC إلى PDF')
            ->assertSee('اسحب الصور أو استخدم الأسهم لترتيب صفحات PDF بالطريقة التي تفضلها.')
            ->assertSee('rtl:rotate-180', false);
    }

    public function test_jpg_to_pdf_service_converts_webp_preserves_original_and_cleans_temporary_png(): void
    {
        $im = imagecreatetruecolor(200, 200);
        $blue = imagecolorallocate($im, 0, 100, 220);
        imagefill($im, 0, 0, $blue);
        $webpPath = tempnam(sys_get_temp_dir(), 'test_webp_') . '.webp';
        imagewebp($im, $webpPath);
        imagedestroy($im);
        $this->tempFiles[] = $webpPath;

        $originalHash = hash_file('sha256', $webpPath);
        $outputPath = tempnam(sys_get_temp_dir(), 'test_out_') . '.pdf';
        $this->tempFiles[] = $outputPath;

        $tempDir = sys_get_temp_dir();
        $pngsBefore = glob($tempDir . DIRECTORY_SEPARATOR . 'webp_pdf_*.png') ?: [];

        $service = app(\App\Services\Pdf\JpgToPdfService::class);
        $result = $service->convertImagesToPdf([$webpPath], $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertSame(1, $result['total_pages']);

        // Original file must remain 100% unchanged
        $this->assertSame($originalHash, hash_file('sha256', $webpPath));

        // Temporary PNG files must all be cleaned up
        $pngsAfter = glob($tempDir . DIRECTORY_SEPARATOR . 'webp_pdf_*.png') ?: [];
        $this->assertSame(count($pngsBefore), count($pngsAfter));
    }

    public function test_jpg_to_pdf_service_corrupt_webp_fails_safely_and_cleans_temporary_files(): void
    {
        $corruptPath = tempnam(sys_get_temp_dir(), 'corrupt_webp_') . '.webp';
        file_put_contents($corruptPath, 'RIFF....WEBPVP8 ... CORRUPT BYTES ...');
        $this->tempFiles[] = $corruptPath;

        $outputPath = tempnam(sys_get_temp_dir(), 'test_out_fail_') . '.pdf';
        $this->tempFiles[] = $outputPath;

        $tempDir = sys_get_temp_dir();
        $pngsBefore = glob($tempDir . DIRECTORY_SEPARATOR . 'webp_pdf_*.png') ?: [];

        $service = app(\App\Services\Pdf\JpgToPdfService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image to PDF conversion failed');

        try {
            $service->convertImagesToPdf([$corruptPath], $outputPath);
        } finally {
            $pngsAfter = glob($tempDir . DIRECTORY_SEPARATOR . 'webp_pdf_*.png') ?: [];
            $this->assertSame(count($pngsBefore), count($pngsAfter));
        }
    }
}
