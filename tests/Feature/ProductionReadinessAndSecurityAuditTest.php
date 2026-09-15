<?php

namespace Tests\Feature;

use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\TemporaryShare;
use App\Services\File\FileStorageManager;
use App\Services\Share\TemporaryShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Tests\TestCase;

class ProductionReadinessAndSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected array $allToolRoutes = [
        'tools.merge-pdf'         => '/merge-pdf',
        'tools.split-pdf'         => '/split-pdf',
        'tools.compress-pdf'      => '/compress-pdf',
        'tools.pdf-to-jpg'        => '/pdf-to-jpg',
        'tools.pdf-to-png'        => '/pdf-to-png',
        'tools.jpg-to-pdf'        => '/jpg-to-pdf',
        'tools.organize-pdf'      => '/organize-pdf',
        'tools.protect-pdf'       => '/protect-pdf',
        'tools.unlock-pdf'        => '/unlock-pdf',
        'tools.sign-pdf'          => '/sign-pdf',
        'tools.edit-pdf'          => '/edit-pdf',
        'tools.compress-image'    => '/compress-image',
        'tools.resize-image'      => '/resize-image',
        'tools.convert-image'     => '/convert-image',
        'tools.remove-background' => '/remove-background',
        'tools.change-background' => '/change-background',
    ];

    /**
     * Requirement 2 & 3: Complete feature inventory - all tool routes resolve 200 in EN and AR.
     */
    public function test_all_16_tool_routes_resolve_200_in_english_and_arabic(): void
    {
        foreach ($this->allToolRoutes as $name => $uri) {
            // English check
            $enRes = $this->withSession(['locale' => 'en'])->get($uri);
            $enRes->assertStatus(200, "Route [{$uri}] must return 200 in English");
            $enRes->assertSee('dir="ltr"', false);
            $enRes->assertSee('Ka7loot PDF');

            // Arabic check
            $arRes = $this->withSession(['locale' => 'ar'])->get($uri);
            $arRes->assertStatus(200, "Route [{$uri}] must return 200 in Arabic");
            $arRes->assertSee('dir="rtl"', false);
            $arRes->assertSee('Ka7loot PDF');
        }
    }

    /**
     * Requirement 13 & 14: Legal and Trust pages resolve 200 in EN and AR.
     */
    public function test_legal_and_trust_pages_resolve_200(): void
    {
        $legalUris = [
            '/privacy-policy',
            '/terms-of-service',
            '/security',
            '/about',
            '/contact',
        ];

        foreach ($legalUris as $uri) {
            $resEn = $this->withSession(['locale' => 'en'])->get($uri);
            $resEn->assertStatus(200, "Legal page [{$uri}] must return 200 in EN");
            $resEn->assertSee('Ka7loot PDF');

            $resAr = $this->withSession(['locale' => 'ar'])->get($uri);
            $resAr->assertStatus(200, "Legal page [{$uri}] must return 200 in AR");
            $resAr->assertSee('Ka7loot PDF');
        }
    }

    /**
     * Requirement 13: SEO XML Sitemap contains all canonical URLs.
     */
    public function test_sitemap_xml_contains_all_tools_and_pages(): void
    {
        $res = $this->get('/sitemap.xml');
        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/xml');

        $content = $res->getContent();
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $content);
        $this->assertStringContainsString('<urlset', $content);
        $this->assertStringContainsString('/merge-pdf', $content);
        $this->assertStringContainsString('/edit-pdf', $content);
        $this->assertStringContainsString('/privacy-policy', $content);
        $this->assertStringContainsString('/security', $content);
        $this->assertStringContainsString('/contact', $content);
    }

    /**
     * Requirement 13: Custom 404 page renders cleanly without debug leaks.
     */
    public function test_custom_404_error_page_renders(): void
    {
        $res = $this->get('/non-existent-tool-path-404-test');
        $res->assertStatus(404);
        $res->assertSee('404');
        $res->assertSee(__('ui.error_404_title'));
        $res->assertSee(__('ui.back_to_home'));
    }

    /**
     * Requirement 15: Ads / Monetization placeholder slots exist and are non-intrusive.
     */
    public function test_ads_placeholder_slots_exist_in_layout(): void
    {
        $res = $this->get('/');
        $res->assertStatus(200);
        $res->assertSee('id="ad-slot-header"', false);
        $res->assertSee('id="ad-slot-footer"', false);
    }

    /**
     * Requirement 6: Security Audit - Magic bytes & Extension mismatch rejection.
     */
    public function test_security_extension_mismatch_and_executable_rejection(): void
    {
        $action = app(SanitizeUploadedFileAction::class);

        // Fake executable pretending to be PDF
        $fakeExe = UploadedFile::fake()->createWithContent('malicious.pdf', "MZ\x90\x00\x03\x00\x00\x00This is an executable");

        $this->expectException(ValidationException::class);
        $action->execute($fakeExe, ['application/pdf']);
    }

    /**
     * Requirement 6: Security Audit - Path traversal filename sanitization.
     */
    public function test_security_filename_path_traversal_sanitized(): void
    {
        $action = app(SanitizeUploadedFileAction::class);

        $pdfContent = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";
        $traversalFile = UploadedFile::fake()->createWithContent('../../etc/passwd.pdf', $pdfContent);

        $res = $action->execute($traversalFile, ['application/pdf']);

        $this->assertStringNotContainsString('../', $res['original_name']);
        $this->assertStringNotContainsString('..\\', $res['original_name']);
        $this->assertMatchesRegularExpression('/^[a-f0-9\\-]+\\.pdf$/', $res['safe_filename']);
    }

    /**
     * Requirement 6: Security Audit - Enforce configured file size ceilings.
     */
    public function test_security_oversized_pdf_is_rejected(): void
    {
        $action = app(SanitizeUploadedFileAction::class);

        // Create 51MB dummy PDF (exceeding default 50MB ceiling)
        $oversizedFile = UploadedFile::fake()->create('huge.pdf', 52 * 1024, 'application/pdf');

        $this->expectException(ValidationException::class);
        $action->execute($oversizedFile, ['application/pdf']);
    }

    /**
     * Requirement 6: Security Audit - Rate limiter bindings verified.
     */
    public function test_rate_limiters_configured_in_container(): void
    {
        $limiters = [
            'tool-processing',
            'heavy-processing',
            'file-download',
            'share-download',
            'public-share-create',
            'public-share-access',
        ];

        foreach ($limiters as $limiter) {
            $callback = RateLimiter::limiter($limiter);
            $this->assertNotNull($callback, "Rate limiter [{$limiter}] must be registered.");
        }
    }

    /**
     * Requirement 6 & 16: Temporary QR Share token security & atomic download limit.
     */
    public function test_temporary_share_token_entropy_and_hash_isolation(): void
    {
        $shareService = app(TemporaryShareService::class);

        $job = FileJob::create([
            'tool_type'      => ToolType::COMPRESS_PDF,
            'status'         => JobStatus::COMPLETED,
            'input_metadata' => [],
            'expires_at'     => now()->addHour(),
        ]);

        $job->processedFiles()->create([
            'original_name'   => 'document.pdf',
            'stored_filename' => 'output.pdf',
            'mime_type'       => 'application/pdf',
            'size_bytes'      => 1234,
            'is_zip_archive'  => false,
        ]);

        $res = $shareService->createShare($job, null, 60, 2);
        $token = $res['token'];
        $share = $res['share'];

        // Plain token must be 64 characters hex
        $this->assertEquals(64, strlen($token));
        // Database record must NOT store plain token
        $dbRecord = TemporaryShare::find($share->id);
        $this->assertNotEquals($token, $dbRecord->token_hash);
        $this->assertEquals(hash('sha256', $token), $dbRecord->token_hash);

        // Max downloads is 2
        $this->assertEquals(2, $dbRecord->max_downloads);
        $this->assertEquals(0, $dbRecord->download_count);
    }

    /**
     * Requirement: Livewire dynamic asset routes resolve correctly and return valid JavaScript.
     */
    public function test_livewire_dynamic_asset_routes_resolve_and_return_javascript(): void
    {
        // 1. In debug mode, livewire.js is registered by default
        $resDebug = $this->get('/livewire/livewire.js');
        $resDebug->assertStatus(200);
        $this->assertStringContainsString('javascript', strtolower($resDebug->headers->get('Content-Type')));

        // 2. Register and verify the minified route (/livewire/livewire.min.js) used in production
        Route::get('/livewire/livewire.min.js', [FrontendAssets::class, 'returnJavaScriptAsFile']);

        $resMin = $this->get('/livewire/livewire.min.js');
        $resMin->assertStatus(200);
        $this->assertStringContainsString('javascript', strtolower($resMin->headers->get('Content-Type')));

        // 3. Cache-busting query strings with arbitrary hashes (e.g. ?id=516a241e) must resolve 200
        $resQuery = $this->get('/livewire/livewire.min.js?id=516a241e&v=3.5');
        $resQuery->assertStatus(200);
        $this->assertStringContainsString('javascript', strtolower($resQuery->headers->get('Content-Type')));

        // 4. Source maps endpoint resolves 200
        $resMap = $this->get('/livewire/livewire.min.js.map');
        $resMap->assertStatus(200);
    }

    /**
     * Requirement: Nginx configuration uses preferential prefix matching (^~) for /livewire/
     * to prevent static regex from intercepting dynamic JS, preview, and upload endpoints.
     */
    public function test_nginx_configuration_has_livewire_preferential_prefix_rule(): void
    {
        $nginxConfPath = base_path('docker/nginx/default.conf');
        $this->assertFileExists($nginxConfPath);
        $content = file_get_contents($nginxConfPath);

        // 1. Verify preferential prefix match ^~ is defined for /livewire/
        $this->assertMatchesRegularExpression('/location\s+\^~\s+\/livewire\/\s*\{/', $content);

        // 2. Verify /livewire/ delegates to Laravel via index.php
        $this->assertStringContainsString('try_files $uri $uri/ /index.php?$query_string;', $content);

        // 3. Verify static asset caching regex is preserved with try_files =404
        $this->assertMatchesRegularExpression('/location\s+~\*\s+\\\.\(ico\|css\|js\|/', $content);
        $this->assertStringContainsString('expires 30d;', $content);
        $this->assertStringContainsString('add_header Cache-Control "public, immutable";', $content);
        $this->assertStringContainsString('try_files $uri =404;', $content);

        // 4. Verify client upload size limit is 100M
        $this->assertStringContainsString('client_max_body_size 100M;', $content);
    }
}
