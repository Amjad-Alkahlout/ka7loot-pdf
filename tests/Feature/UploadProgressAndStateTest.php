<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Livewire\Tools\CompressImageComponent;
use App\Livewire\Tools\CompressPdfComponent;
use App\Livewire\Tools\MergePdfComponent;
use App\Livewire\Tools\SplitPdfComponent;
use App\Models\FileJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class UploadProgressAndStateTest extends TestCase
{
    public function test_upload_progress_component_is_rendered_with_event_listeners(): void
    {
        Livewire::test(CompressPdfComponent::class)
            ->assertSee('x-data="fileUploadHandler', false)
            ->assertSee('livewire-upload-start', false)
            ->assertSee('livewire-upload-progress', false)
            ->assertSee('livewire-upload-finish', false)
            ->assertSee('livewire-upload-error', false)
            ->assertSee(__('ui.cancel_upload'))
            ->assertSee(__('ui.upload_speed'))
            ->assertSee(__('ui.time_remaining'));
    }

    public function test_arabic_and_english_translations_for_upload_progress(): void
    {
        // English
        app()->setLocale('en');
        $this->assertEquals('Uploading...', __('ui.uploading'));
        $this->assertEquals('Speed', __('ui.upload_speed'));
        $this->assertEquals('Est. Remaining', __('ui.time_remaining'));
        $this->assertEquals('Cancel Upload', __('ui.cancel_upload'));
        $this->assertEquals('Uploaded', __('ui.state_uploaded'));
        $this->assertEquals('Uploading', __('ui.state_uploading'));

        // Arabic
        app()->setLocale('ar');
        $this->assertEquals('جارٍ الرفع...', __('ui.uploading'));
        $this->assertEquals('السرعة', __('ui.upload_speed'));
        $this->assertEquals('الوقت المتبقي التقريبي', __('ui.time_remaining'));
        $this->assertEquals('إلغاء الرفع', __('ui.cancel_upload'));
        $this->assertEquals('تم الرفع', __('ui.state_uploaded'));
        $this->assertEquals('جارٍ الرفع', __('ui.state_uploading'));
    }

    public function test_single_file_upload_transitions_to_uploaded_state(): void
    {
        app()->setLocale('en');
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        Livewire::test(CompressPdfComponent::class)
            ->assertSet('jobStatus', 'empty')
            ->set('upload', $file)
            ->assertSet('jobStatus', 'uploaded')
            ->assertSee('document.pdf')
            ->assertSee(__('tools.compress_pdf.action'));
    }

    public function test_cancel_tool_upload_resets_state_and_upload(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 1024, 'application/pdf');

        Livewire::test(CompressPdfComponent::class)
            ->set('upload', $file)
            ->assertSet('jobStatus', 'uploaded')
            ->call('cancelToolUpload')
            ->assertSet('jobStatus', 'empty')
            ->assertSet('upload', null)
            ->assertSee(__('ui.select_files'));
    }

    public function test_multi_file_upload_transitions_to_uploaded_state_and_cancel(): void
    {
        $file1 = UploadedFile::fake()->create('doc1.pdf', 500, 'application/pdf');
        $file2 = UploadedFile::fake()->create('doc2.pdf', 600, 'application/pdf');

        Livewire::test(MergePdfComponent::class)
            ->assertSet('jobStatus', 'empty')
            ->set('uploads', [$file1, $file2])
            ->assertSet('jobStatus', 'uploaded')
            ->assertSee('doc1.pdf')
            ->assertSee('doc2.pdf')
            ->call('cancelToolUpload')
            ->assertSet('jobStatus', 'empty')
            ->assertSet('uploads', [])
            ->assertSee(__('ui.select_files'));
    }

    public function test_state_separation_and_stepper(): void
    {
        $file = UploadedFile::fake()->create('sample.png', 500, 'image/png');

        Livewire::test(CompressImageComponent::class)
            ->assertSet('jobStatus', 'empty')
            ->assertSee(__('ui.state_uploading'))
            ->assertSee(__('ui.state_uploaded'))
            ->assertSee(__('ui.state_processing'))
            ->assertSee(__('ui.state_completed'))
            ->set('upload', $file)
            ->assertSet('jobStatus', 'uploaded');
    }

    public function test_indeterminate_processing_indicator_without_fake_percentage(): void
    {
        $component = Livewire::test(CompressPdfComponent::class)
            ->set('jobStatus', 'processing')
            ->set('statusMessage', 'Processing document...');

        $component->assertSee('Processing document...')
            ->assertSee('animate-pulse')
            ->assertDontSee('50%')
            ->assertDontSee('75%');
    }

    public function test_upload_limits_displayed_dynamically_from_shared_config(): void
    {
        // PDF tools show 50 MB
        Livewire::test(CompressPdfComponent::class)
            ->assertSee('50 MB');

        Livewire::test(SplitPdfComponent::class)
            ->assertSee('50 MB');

        // Image tools show 25 MB
        Livewire::test(CompressImageComponent::class)
            ->assertSee('25 MB');
    }
}
