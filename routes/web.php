<?php

use App\Http\Controllers\FileDownloadController;
use App\Livewire\Tools\CompressImageComponent;
use App\Livewire\Tools\CompressPdfComponent;
use App\Livewire\Tools\JpgToPdfComponent;
use App\Livewire\Tools\MergePdfComponent;
use App\Livewire\Tools\SplitPdfComponent;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('pages.home');
})->name('home');

// Tool Routes
Route::get('/merge-pdf', MergePdfComponent::class)->name('tools.merge-pdf');
Route::get('/split-pdf', SplitPdfComponent::class)->name('tools.split-pdf');
Route::get('/compress-pdf', CompressPdfComponent::class)->name('tools.compress-pdf');
Route::get('/jpg-to-pdf', JpgToPdfComponent::class)->name('tools.jpg-to-pdf');
Route::get('/compress-image', CompressImageComponent::class)->name('tools.compress-image');

// Secure File Download & Immediate Deletion
Route::get('/download/{token}/{fileId}', [FileDownloadController::class, 'download'])
    ->middleware('throttle:file-download')
    ->name('file.download');

Route::post('/job/{token}/delete-now', [FileDownloadController::class, 'deleteNow'])
    ->name('file.delete');

// Locale Switcher
Route::get('/lang/{locale}', [FileDownloadController::class, 'switchLocale'])
    ->name('locale.switch');
