<?php

use App\Http\Controllers\FileDownloadController;
use App\Http\Controllers\PublicShareDownloadController;
use App\Livewire\Tools\ChangeImageBackgroundComponent;
use App\Livewire\Tools\CompressImageComponent;
use App\Livewire\Tools\CompressPdfComponent;
use App\Livewire\Tools\ConvertImageComponent;
use App\Livewire\Tools\EditPdfComponent;
use App\Livewire\Tools\JpgToPdfComponent;
use App\Livewire\Tools\MergePdfComponent;
use App\Livewire\Tools\OrganizePdfComponent;
use App\Livewire\Tools\PdfToJpgComponent;
use App\Livewire\Tools\PdfToPngComponent;
use App\Livewire\Tools\ProtectPdfComponent;
use App\Livewire\Tools\RemoveImageBackgroundComponent;
use App\Livewire\Tools\ResizeImageComponent;
use App\Livewire\Tools\SignPdfComponent;
use App\Livewire\Tools\SplitPdfComponent;
use App\Livewire\Tools\UnlockPdfComponent;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('pages.home');
})->name('home');

// PDF Tools
Route::get('/merge-pdf', MergePdfComponent::class)->name('tools.merge-pdf');
Route::get('/split-pdf', SplitPdfComponent::class)->name('tools.split-pdf');
Route::get('/compress-pdf', CompressPdfComponent::class)->name('tools.compress-pdf');
Route::get('/pdf-to-jpg', PdfToJpgComponent::class)->name('tools.pdf-to-jpg');
Route::get('/pdf-to-png', PdfToPngComponent::class)->name('tools.pdf-to-png');
Route::get('/jpg-to-pdf', JpgToPdfComponent::class)->name('tools.jpg-to-pdf');
Route::get('/organize-pdf', OrganizePdfComponent::class)->name('tools.organize-pdf');
Route::get('/protect-pdf', ProtectPdfComponent::class)->name('tools.protect-pdf');
Route::get('/unlock-pdf', UnlockPdfComponent::class)->name('tools.unlock-pdf');
Route::get('/sign-pdf', SignPdfComponent::class)->name('tools.sign-pdf');
Route::get('/edit-pdf', EditPdfComponent::class)->name('tools.edit-pdf');

// Image Tools
Route::get('/compress-image', CompressImageComponent::class)->name('tools.compress-image');
Route::get('/resize-image', ResizeImageComponent::class)->name('tools.resize-image');
Route::get('/convert-image', ConvertImageComponent::class)->name('tools.convert-image');
Route::get('/remove-background', RemoveImageBackgroundComponent::class)->name('tools.remove-background');
Route::get('/change-background', ChangeImageBackgroundComponent::class)->name('tools.change-background');

// Legal & Trust Pages
Route::get('/privacy-policy', function () {
    return view('pages.privacy-policy');
})->name('privacy-policy');

Route::get('/terms-of-service', function () {
    return view('pages.terms-of-service');
})->name('terms-of-service');

Route::get('/security', function () {
    return view('pages.security');
})->name('security');

Route::get('/about', function () {
    return view('pages.about');
})->name('about');

Route::get('/contact', function () {
    return view('pages.contact');
})->name('contact');

// SEO XML Sitemap
Route::get('/sitemap.xml', function () {
    $baseUrl = rtrim((string) config('app.url', 'https://ka7lootpdf.me'), '/');
    if (!str_contains($baseUrl, 'ka7lootpdf.me')) {
        $baseUrl = 'https://ka7lootpdf.me';
    } elseif (str_starts_with($baseUrl, 'http://')) {
        $baseUrl = 'https://' . substr($baseUrl, 7);
    }

    $routes = [
        'home'                    => ['path' => '/',                   'priority' => '1.0', 'changefreq' => 'daily'],
        'tools.merge-pdf'         => ['path' => '/merge-pdf',         'priority' => '0.9', 'changefreq' => 'weekly'],
        'tools.split-pdf'         => ['path' => '/split-pdf',         'priority' => '0.9', 'changefreq' => 'weekly'],
        'tools.compress-pdf'      => ['path' => '/compress-pdf',      'priority' => '0.9', 'changefreq' => 'weekly'],
        'tools.pdf-to-jpg'        => ['path' => '/pdf-to-jpg',        'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.pdf-to-png'        => ['path' => '/pdf-to-png',        'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.jpg-to-pdf'        => ['path' => '/jpg-to-pdf',        'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.organize-pdf'      => ['path' => '/organize-pdf',      'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.protect-pdf'       => ['path' => '/protect-pdf',       'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.unlock-pdf'        => ['path' => '/unlock-pdf',        'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.sign-pdf'          => ['path' => '/sign-pdf',          'priority' => '0.9', 'changefreq' => 'weekly'],
        'tools.edit-pdf'          => ['path' => '/edit-pdf',          'priority' => '0.9', 'changefreq' => 'weekly'],
        'tools.compress-image'    => ['path' => '/compress-image',    'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.resize-image'      => ['path' => '/resize-image',      'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.convert-image'     => ['path' => '/convert-image',     'priority' => '0.8', 'changefreq' => 'weekly'],
        'tools.remove-background' => ['path' => '/remove-background', 'priority' => '0.9', 'changefreq' => 'weekly'],
        'tools.change-background' => ['path' => '/change-background', 'priority' => '0.8', 'changefreq' => 'weekly'],
        'about'                   => ['path' => '/about',             'priority' => '0.5', 'changefreq' => 'monthly'],
        'contact'                 => ['path' => '/contact',           'priority' => '0.5', 'changefreq' => 'monthly'],
        'privacy-policy'          => ['path' => '/privacy-policy',    'priority' => '0.5', 'changefreq' => 'monthly'],
        'terms-of-service'        => ['path' => '/terms-of-service',  'priority' => '0.5', 'changefreq' => 'monthly'],
        'security'                => ['path' => '/security',          'priority' => '0.5', 'changefreq' => 'monthly'],
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($routes as $routeInfo) {
        $loc = $routeInfo['path'] === '/' ? $baseUrl : $baseUrl . $routeInfo['path'];
        $xml .= "  <url>\n";
        $xml .= "    <loc>{$loc}</loc>\n";
        $xml .= "    <changefreq>{$routeInfo['changefreq']}</changefreq>\n";
        $xml .= "    <priority>{$routeInfo['priority']}</priority>\n";
        $xml .= "  </url>\n";
    }
    $xml .= '</urlset>';

    return response($xml, 200, [
        'Content-Type'  => 'application/xml',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('sitemap');

// Robots.txt
Route::get('/robots.txt', function () {
    $baseUrl = rtrim((string) config('app.url', 'https://ka7lootpdf.me'), '/');
    if (!str_contains($baseUrl, 'ka7lootpdf.me')) {
        $baseUrl = 'https://ka7lootpdf.me';
    } elseif (str_starts_with($baseUrl, 'http://')) {
        $baseUrl = 'https://' . substr($baseUrl, 7);
    }

    $content = "User-agent: *\n";
    $content .= "Disallow: /download/\n";
    $content .= "Disallow: /s/\n";
    $content .= "Disallow: /job/\n";
    $content .= "Disallow: /share/\n";
    $content .= "Disallow: /lang/\n";
    $content .= "Disallow: /livewire/\n";
    $content .= "Allow: /\n\n";
    $content .= "Sitemap: {$baseUrl}/sitemap.xml\n";

    return response($content, 200, [
        'Content-Type'  => 'text/plain',
        'Cache-Control' => 'public, max-age=86400',
    ]);
});

// Secure File Download & Immediate Deletion
Route::get('/download/{token}/{fileId}', [FileDownloadController::class, 'download'])
    ->middleware('throttle:file-download')
    ->name('file.download');

Route::post('/job/{token}/delete-now', [FileDownloadController::class, 'deleteNow'])
    ->middleware('throttle:file-download')
    ->name('file.delete-now');

// Temporary QR File Sharing
Route::post('/share/create', [PublicShareDownloadController::class, 'create'])
    ->middleware('throttle:public-share-create')
    ->name('share.create');

Route::get('/s/{token}', [PublicShareDownloadController::class, 'show'])
    ->middleware('throttle:public-share-access')
    ->name('share.show');

Route::get('/s/{token}/download', [PublicShareDownloadController::class, 'download'])
    ->middleware('throttle:public-share-access')
    ->name('share.download');

Route::get('/s/{token}/qr', [PublicShareDownloadController::class, 'qrCode'])
    ->middleware('throttle:public-share-access')
    ->name('share.qr');

Route::post('/s/{token}/revoke', [PublicShareDownloadController::class, 'revoke'])
    ->name('share.revoke');

// Locale Switcher
Route::get('/lang/{locale}', [FileDownloadController::class, 'switchLocale'])
    ->name('locale.switch');
