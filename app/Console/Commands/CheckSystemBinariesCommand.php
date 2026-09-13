<?php

namespace App\Console\Commands;

use App\Services\System\SystemBinaryDetector;
use Illuminate\Console\Command;

class CheckSystemBinariesCommand extends Command
{
    protected $signature = 'app:check-system-binaries {--refresh : Force re-detection without cache}';
    protected $description = 'Inspect host environment for optional PDF and image processing tools (Ghostscript, Poppler)';

    public function handle(SystemBinaryDetector $detector): int
    {
        if ($this->option('refresh')) {
            $detector->probe(forceRefresh: true);
        }

        $this->info("System Capability & Binary Diagnostics for PDF & Image Processing:");
        $this->newLine();

        $rows = [
            [
                'Component' => 'PHP GD Extension',
                'Status' => $detector->canProcessImages() ? '<info>READY</info>' : '<error>MISSING</error>',
                'Path / Driver' => 'Built-in extension',
                'Version' => gd_info()['GD Version'] ?? 'Active',
                'Tools Supported' => 'Compress Image, Resize Image, Convert Image, JPG to PDF',
                'Install Guide' => 'Enable extension=gd in php.ini',
            ],
            [
                'Component' => 'FPDI & FPDF Engine',
                'Status' => $detector->canManipulatePdf() ? '<info>READY</info>' : '<error>MISSING</error>',
                'Path / Driver' => 'setasign/fpdi pure-PHP',
                'Version' => 'v2.6.x',
                'Tools Supported' => 'Merge PDF, Split PDF, Organize PDF, JPG to PDF',
                'Install Guide' => 'Composer dependency (installed)',
            ],
            [
                'Component' => 'Ghostscript (gs / gswin64c)',
                'Status' => $detector->hasGhostscript() ? '<info>READY</info>' : '<comment>OPTIONAL (NOT FOUND)</comment>',
                'Path / Driver' => $detector->getGhostscriptPath() ?: 'None',
                'Version' => $detector->getGhostscriptVersion() ?: 'N/A',
                'Tools Supported' => 'Deep Compress PDF (high ratio)',
                'Install Guide' => PHP_OS_FAMILY === 'Windows' ? 'choco install ghostscript' : 'apt install ghostscript',
            ],
            [
                'Component' => 'Poppler (pdftoppm)',
                'Status' => $detector->hasPoppler() ? '<info>READY</info>' : '<comment>OPTIONAL (NOT FOUND)</comment>',
                'Path / Driver' => $detector->getPopplerPath() ?: 'None',
                'Version' => $detector->getPopplerVersion() ?: 'N/A',
                'Tools Supported' => 'PDF to JPG, PDF to PNG (rendering)',
                'Install Guide' => PHP_OS_FAMILY === 'Windows' ? 'choco install poppler' : 'apt install poppler-utils',
            ],
        ];

        $this->table(
            ['Component', 'Status', 'Path / Driver', 'Version', 'Tools Supported', 'Install Guide'],
            $rows
        );

        $this->newLine();
        if (!$detector->hasGhostscript() || !$detector->hasPoppler()) {
            $this->comment('Note: Pure-PHP tools (JPG to PDF, Merge PDF, Split PDF, Organize PDF, Compress Image, Resize Image, Convert Image) work 100% out of the box.');
            $this->comment('To enable deep PDF compression and PDF-to-image rasterization on Windows, run:');
            $this->line('  <info>choco install ghostscript poppler -y</info>');
        } else {
            $this->info('All system binaries are detected and ready for high-performance processing.');
        }

        return Command::SUCCESS;
    }
}
