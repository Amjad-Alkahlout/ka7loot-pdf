<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\CompressImageComponent;
use App\Livewire\Tools\CompressPdfComponent;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class LocalizationConsistencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected array $toolRoutes = [
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
        '/protect-pdf',
        '/unlock-pdf',
        '/sign-pdf',
        '/remove-background',
        '/change-background',
        '/edit-pdf',
    ];

    /**
     * Requirement 1-3, 8: Audit that every translation key referenced in resources/ and app/
     * exists in both English and Arabic translation files.
     */
    public function test_all_codebase_translation_keys_exist_in_en_and_ar(): void
    {
        $translations = [
            'en' => [
                'tools' => require base_path('lang/en/tools.php'),
                'ui' => require base_path('lang/en/ui.php'),
                'validation' => require base_path('lang/en/validation.php'),
            ],
            'ar' => [
                'tools' => require base_path('lang/ar/tools.php'),
                'ui' => require base_path('lang/ar/ui.php'),
                'validation' => require base_path('lang/ar/validation.php'),
            ],
        ];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path())),
            '/\.(php|blade\.php)$/'
        );

        $keysFound = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/vendor/') || str_contains($path, '/storage/') || str_contains($path, '/node_modules/') || str_contains($path, '/tests/')) {
                continue;
            }

            $content = file_get_contents($path);
            preg_match_all('/(?:__|\btrans|@lang|Lang::get)\s*\(\s*[\x27\x22]([a-zA-Z0-9_\-\.]+)[\x27\x22]/', $content, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $k) {
                    if (str_contains($k, '.')) {
                        $keysFound[$k][] = $path;
                    }
                }
            }
        }

        $resolveKey = function (array $allTrans, string $locale, string $key): bool {
            $parts = explode('.', $key);
            $curr = $allTrans[$locale] ?? [];
            foreach ($parts as $part) {
                if (!is_array($curr) || !array_key_exists($part, $curr)) {
                    return false;
                }
                $curr = $curr[$part];
            }
            return !is_array($curr);
        };

        $missingKeys = [];
        foreach (['en', 'ar'] as $locale) {
            foreach (array_keys($keysFound) as $k) {
                if (!$resolveKey($translations, $locale, $k)) {
                    $missingKeys[$locale][] = $k;
                }
            }
        }

        $this->assertEmpty(
            $missingKeys,
            'Unresolved translation keys found: ' . json_encode($missingKeys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Requirement 9, 10, 11: Render all tool pages in Arabic and English,
     * ensuring no raw translation keys (ui.*, tools.*, validation.*) appear in HTML or text.
     */
    public function test_rendered_tool_pages_never_contain_raw_translation_keys_in_ar_and_en(): void
    {
        $specificObservedKeys = [
            'tools.compress_pdf.method',
            'tools.compress_pdf.method_target',
            'tools.compress_pdf.method_preset',
            'tools.compress_pdf.level',
            'tools.compress_pdf.level_low_desc',
            'tools.compress_pdf.level_recommended_desc',
            'tools.compress_pdf.level_extreme_desc',
            'tools.compress_image.method',
            'tools.compress_image.method_target',
            'tools.compress_image.method_preset',
            'tools.compress_image.quality',
            'tools.compress_image.quality_approx',
            'ui.process_another',
            'tools.jpg_to_pdf.reorder_hint',
            'tools.jpg_to_pdf.dimensions',
            'tools.pdf_to_jpg.dpi_normal',
            'tools.pdf_to_jpg.dpi_high',
            'tools.resize_image.mode',
            'tools.split_pdf.range_hint',
            'tools.split_pdf.pages_unit',
        ];

        foreach (['ar', 'en'] as $locale) {
            App::setLocale($locale);

            foreach ($this->toolRoutes as $url) {
                $response = $this->withSession(['locale' => $locale])->get($url);
                $response->assertStatus(200);

                $html = $response->getContent();

                // 1. Verify none of the observed missing keys are present anywhere in the raw HTML
                foreach ($specificObservedKeys as $observedKey) {
                    $this->assertStringNotContainsString(
                        $observedKey,
                        $html,
                        "Found raw translation key '{$observedKey}' rendered on '{$url}' in locale '{$locale}'."
                    );
                }

                // 2. Verify visible text (stripped of tags) has no translation keys
                $visibleText = strip_tags($html);
                foreach (['ui.', 'tools.', 'validation.'] as $prefix) {
                    $this->assertStringNotContainsString(
                        $prefix,
                        $visibleText,
                        "Visible text on page '{$url}' in locale '{$locale}' contains raw prefix '{$prefix}'."
                    );
                }

                // 3. Clean Livewire technical attributes (e.g. wire:snapshot name="tools.component-name")
                // and check that no other HTML content contains raw translation prefixes
                $cleanedHtml = preg_replace('/wire:snapshot="[^"]*"/', '', $html);
                $cleanedHtml = preg_replace('/wire:effects="[^"]*"/', '', $cleanedHtml);

                foreach (['ui.', 'tools.', 'validation.'] as $prefix) {
                    $this->assertStringNotContainsString(
                        $prefix,
                        $cleanedHtml,
                        "HTML markup on page '{$url}' in locale '{$locale}' contains raw translation key prefix '{$prefix}'."
                    );
                }

                // 4. Check for un-rendered Blade syntax or escaped translation expressions
                $this->assertDoesNotMatchRegularExpression(
                    '/\{\{\s*__\([^\)]+\)\s*\}\}/',
                    $html,
                    "Un-rendered Blade expression found on '{$url}' in locale '{$locale}'."
                );
            }
        }
    }

    /**
     * Requirement 9, 10: Test job result view in completed state for both locales
     * to ensure ui.process_another and all completion metrics are properly translated.
     */
    public function test_job_result_component_renders_without_raw_translation_keys(): void
    {
        $job = FileJob::create([
            'id' => '00000000-0000-0000-0000-000000000001',
            'tool_type' => ToolType::COMPRESS_PDF,
            'status' => JobStatus::COMPLETED,
            'original_filename' => 'doc.pdf',
            'original_size_bytes' => 1048576,
            'download_token' => 'test-download-token',
            'expires_at' => now()->addHour(),
            'metadata' => [
                'engine' => 'Ghostscript',
                'target_size_bytes' => 500000,
                'target_achieved' => true,
                'target_iterations' => 2,
                'quality_priority' => 'balanced',
            ],
        ]);

        $file = ProcessedFile::create([
            'id' => '00000000-0000-0000-0000-000000000002',
            'file_job_id' => $job->id,
            'stored_filename' => 'doc_compressed.pdf',
            'original_name' => 'doc_compressed.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 450000,
            'download_count' => 0,
        ]);

        foreach (['ar', 'en'] as $locale) {
            App::setLocale($locale);

            $rendered = view('livewire.tools.job-result', [
                'job' => $job,
                'processedFiles' => collect([$file]),
            ])->render();

            $this->assertStringNotContainsString('ui.process_another', $rendered);
            $this->assertStringNotContainsString('ui.try_another', $rendered);
            $this->assertStringNotContainsString('ui.delete_now', $rendered);
            $this->assertStringNotContainsString('ui.download', $rendered);

            foreach (['ui.', 'tools.', 'validation.'] as $prefix) {
                $this->assertStringNotContainsString(
                    $prefix,
                    $rendered,
                    "Job result view contains unparsed key prefix '{$prefix}' in locale '{$locale}'."
                );
            }
        }
    }

    /**
     * Requirement 9, 10: Test target-size error validation localization in Arabic and English.
     */
    public function test_target_size_validation_messages_are_properly_localized(): void
    {
        $fakePdf = UploadedFile::fake()->create('sample.pdf', 500, 'application/pdf');
        $fakeImg = UploadedFile::fake()->image('sample.jpg', 600, 600)->size(500);

        foreach (['ar', 'en'] as $locale) {
            App::setLocale($locale);

            // Compress PDF: invalid target
            $compPdf = Livewire::test(CompressPdfComponent::class);
            $compPdf->set('upload', $fakePdf);
            $compPdf->set('compressionMethod', 'target');
            $compPdf->set('targetValue', -10);
            $compPdf->call('process');
            $compPdf->assertHasErrors(['targetValue']);

            $errorsPdf = $compPdf->errors()->get('targetValue');
            foreach ($errorsPdf as $err) {
                $this->assertStringNotContainsString('tools.validation_', $err);
                $this->assertStringNotContainsString('validation.', $err);
                if ($locale === 'ar') {
                    $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $err);
                }
            }

            // Compress Image: invalid target
            $compImg = Livewire::test(CompressImageComponent::class);
            $compImg->set('upload', $fakeImg);
            $compImg->set('compressionMethod', 'target');
            $compImg->set('targetValue', -10);
            $compImg->call('process');
            $compImg->assertHasErrors(['targetValue']);

            $errorsImg = $compImg->errors()->get('targetValue');
            foreach ($errorsImg as $err) {
                $this->assertStringNotContainsString('tools.validation_', $err);
                $this->assertStringNotContainsString('validation.', $err);
                if ($locale === 'ar') {
                    $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $err);
                }
            }
        }
    }
}
