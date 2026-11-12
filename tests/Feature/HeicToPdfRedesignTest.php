<?php

namespace Tests\Feature;

use App\Livewire\Tools\HeicToPdfComponent;
use App\Services\File\DailyFileQuotaService;
use App\Services\Image\HeicConverterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class HeicToPdfRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_zero_files_large_dropzone_renders_and_compact_bar_does_not(): void
    {
        Livewire::test(HeicToPdfComponent::class)
            ->assertSet('uploads', [])
            ->assertSee(__('ui.select_files'))
            ->assertSee(__('ui.drop_files_here'))
            ->assertDontSee(__('ui.add_more_files'));
    }

    public function test_with_one_or_more_files_compact_bar_renders_and_large_dropzone_does_not(): void
    {
        $file1 = UploadedFile::fake()->create('vacation-1.heic', 500, 'image/heic');
        $file2 = UploadedFile::fake()->create('vacation-2.heic', 750, 'image/heic');

        $component = Livewire::test(HeicToPdfComponent::class)
            ->set('newFiles', [$file1, $file2])
            ->assertCount('uploads', 2)
            // Compact bar renders
            ->assertSee(__('ui.add_more_files'))
            // Large dropzone does not render
            ->assertDontSee(__('ui.select_files'))
            // Header shows count and total size
            ->assertSee(__('ui.files_selected', ['count' => 2]))
            ->assertSee('vacation-1.heic')
            ->assertSee('vacation-2.heic')
            // Reorder and delete controls
            ->assertSee(__('ui.clear_all'))
            ->assertSee(__('ui.move_up'))
            ->assertSee(__('ui.move_down'))
            ->assertSee(__('ui.remove'));

        // Total size calculation (500KB + 750KB = 1.2 MB)
        $this->assertStringContainsString('MB', $component->get('totalSizeFormatted'));
    }

    public function test_page_order_stays_in_sync_with_list_reordering(): void
    {
        $fileA = UploadedFile::fake()->create('aaa.heic', 100, 'image/heic');
        $fileB = UploadedFile::fake()->create('bbb.heic', 100, 'image/heic');

        $component = Livewire::test(HeicToPdfComponent::class)
            ->set('newFiles', [$fileA, $fileB]);

        $this->assertSame('aaa.heic', $component->get('uploads')[0]->getClientOriginalName());
        $this->assertSame('bbb.heic', $component->get('uploads')[1]->getClientOriginalName());

        // Move second file up
        $component->call('moveUp', 1);

        $this->assertSame('bbb.heic', $component->get('uploads')[0]->getClientOriginalName());
        $this->assertSame('aaa.heic', $component->get('uploads')[1]->getClientOriginalName());

        // Move first file down
        $component->call('moveDown', 0);

        $this->assertSame('aaa.heic', $component->get('uploads')[0]->getClientOriginalName());
        $this->assertSame('bbb.heic', $component->get('uploads')[1]->getClientOriginalName());
    }

    public function test_failed_thumbnail_does_not_break_the_list(): void
    {
        $file = UploadedFile::fake()->create('corrupted.heic', 50, 'image/heic');

        $component = Livewire::test(HeicToPdfComponent::class)
            ->set('newFiles', [$file])
            ->assertOk()
            ->assertSee('corrupted.heic')
            ->assertSee('HEIC')
            // Fallback icon container is embedded in the Blade output
            ->assertSee('x-show="failed || !', false)
            ->assertSee(__('ui.remove'));

        // List remains fully interactive even if thumbnail fails
        $component->call('removeFile', 0)
            ->assertCount('uploads', 0)
            ->assertSee(__('ui.select_files'));
    }

    public function test_heic_preview_route_is_strictly_authorized_per_user(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory', 'livewire-tmp');

        $filename = 'authorized_test_' . uniqid() . '.heic';
        $disk->put($livewireDir . '/' . $filename, 'dummy-heic-content');

        // 1. Unsigned request is forbidden (403)
        $unsignedUrl = route('tools.heic-preview', ['filename' => $filename]);
        $this->get($unsignedUrl)->assertStatus(403);

        // 2. Tampered signature is forbidden (403)
        $tamperedUrl = $unsignedUrl . '?signature=invalid123';
        $this->get($tamperedUrl)->assertStatus(403);

        // 3. Different session ID is forbidden (403)
        $signedDifferentSession = URL::temporarySignedRoute(
            'tools.heic-preview',
            now()->addHour(),
            ['filename' => $filename, 'session_id' => 'different_session_id_456']
        );
        $this->withSession(['preview_session_id' => 'my_session_123'])
            ->get($signedDifferentSession)
            ->assertStatus(403);

        // 4. Path traversal attempt is rejected (400)
        $traversalSigned = URL::temporarySignedRoute(
            'tools.heic-preview',
            now()->addHour(),
            ['filename' => '../secret.txt']
        );
        $this->get($traversalSigned)->assertStatus(400);

        // Clean up
        $disk->delete($livewireDir . '/' . $filename);
    }

    public function test_heic_preview_generates_and_serves_jpeg_when_authorized(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory', 'livewire-tmp');

        $filename = 'sample_' . uniqid() . '.heic';
        $disk->put($livewireDir . '/' . $filename, 'mock-heic-data');

        // Mock converter to simulate successful JPEG creation
        $this->mock(HeicConverterService::class, function ($mock) {
            $mock->shouldReceive('convertToJpeg')
                ->once()
                ->andReturnUsing(function (string $input, string $output) {
                    file_put_contents($output, 'fake-jpeg-image-bytes');
                    return ['output_path' => $output, 'size_bytes' => 20];
                });
        });

        $sessionId = 'test_session_abc';
        $signedUrl = URL::temporarySignedRoute(
            'tools.heic-preview',
            now()->addHour(),
            ['filename' => $filename, 'session_id' => $sessionId]
        );

        $response = $this->withSession(['preview_session_id' => $sessionId])->get($signedUrl);

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));

        // Clean up generated files
        $disk->delete($livewireDir . '/' . $filename);
        $disk->delete($livewireDir . '/thumb_' . md5($filename) . '.jpg');
        $disk->delete($livewireDir . '/thumb_' . pathinfo($filename, PATHINFO_FILENAME) . '.jpg');
    }

    public function test_heic_preview_accepts_livewire_base64_encoded_filenames(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        // Livewire encodes original metadata into filename with base64 padding '=' and '+'
        $filename = 'a1b2c3d4-metaSU1HXzIwMjQuSEVJQw==-.HEIC';
        $disk->put($livewireDir . '/' . $filename, 'mock-heic-data');

        $this->mock(HeicConverterService::class, function ($mock) {
            $mock->shouldReceive('convertToJpeg')
                ->once()
                ->andReturnUsing(function (string $input, string $output) {
                    file_put_contents($output, 'mock-jpeg-bytes');
                    return ['output_path' => $output, 'size_bytes' => 15];
                });
        });

        $sessionId = 'test_session_base64';
        $signedUrl = URL::temporarySignedRoute(
            'tools.heic-preview',
            now()->addHour(),
            ['filename' => $filename, 'session_id' => $sessionId]
        );

        // Must succeed without 400 Bad Request
        $response = $this->withSession(['preview_session_id' => $sessionId])->get($signedUrl);
        $response->assertOk();

        // Clean up
        $disk->delete($livewireDir . '/' . $filename);
        $disk->delete($livewireDir . '/thumb_' . md5($filename) . '.jpg');
    }

    public function test_thumbnails_are_deleted_together_with_original_files(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory', 'livewire-tmp');

        $uploaded = UploadedFile::fake()->create('delete_me.heic', 100, 'image/heic');

        $component = Livewire::test(HeicToPdfComponent::class)
            ->set('newFiles', [$uploaded])
            ->assertCount('uploads', 1);

        $upload = $component->get('uploads')[0];
        $storedFilename = basename($upload->getFilename());

        // Create mock thumbnail on disk
        $thumbName = 'thumb_' . pathinfo($storedFilename, PATHINFO_FILENAME) . '.jpg';
        $disk->put($livewireDir . '/' . $thumbName, 'thumbnail-bytes');
        $this->assertTrue($disk->exists($livewireDir . '/' . $thumbName));

        // Remove the individual file
        $component->call('removeFile', 0);

        // Verify thumbnail is deleted together with original
        $this->assertFalse($disk->exists($livewireDir . '/' . $thumbName));
    }

    public function test_quota_warning_displays_inline_when_selection_exceeds_remaining(): void
    {
        $quotaMock = $this->mock(DailyFileQuotaService::class);
        $quotaMock->shouldReceive('summary')->andReturn([
            'used' => 19,
            'limit' => 20,
            'remaining' => 1,
            'reserved' => 0,
            'guest' => true,
        ]);

        $file1 = UploadedFile::fake()->create('img1.heic', 100, 'image/heic');
        $file2 = UploadedFile::fake()->create('img2.heic', 100, 'image/heic');

        // Selecting 2 files when only 1 remaining triggers the inline warning
        Livewire::test(HeicToPdfComponent::class)
            ->set('newFiles', [$file1, $file2])
            ->assertSee(__('ui.quota_exceeded_warning_title'))
            ->assertSee(__('ui.quota_exceeded_warning_desc', ['count' => 2, 'remaining' => 1]));
    }
}
