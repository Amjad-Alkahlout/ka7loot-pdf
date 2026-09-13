<?php

namespace App\Services\File;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

class ArchiveGeneratorService
{
    /**
     * Create a zip archive from a list of files.
     *
     * @param array<array{path: string, name?: string}>|array<string> $files List of files to include
     * @param string $outputZipPath Absolute path to output .zip archive
     * @return array{
     *     output_path: string,
     *     files_count: int,
     *     size_bytes: int
     * }
     */
    public function createZip(array $files, string $outputZipPath): array
    {
        if (empty($files)) {
            throw new InvalidArgumentException("Cannot create an empty zip archive.");
        }

        $zip = new ZipArchive();
        $status = $zip->open($outputZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($status !== true) {
            throw new RuntimeException("Failed to open or create zip archive at: {$outputZipPath}. Error code: {$status}");
        }

        $usedNames = [];
        $addedCount = 0;

        foreach ($files as $file) {
            $filePath = is_array($file) ? $file['path'] : $file;
            $entryName = is_array($file) && !empty($file['name']) ? $file['name'] : basename($filePath);

            if (!file_exists($filePath)) {
                continue;
            }

            // Deduplicate entry name inside the ZIP
            $cleanName = $entryName;
            $counter = 1;
            $base = pathinfo($entryName, PATHINFO_FILENAME);
            $ext = pathinfo($entryName, PATHINFO_EXTENSION);
            while (in_array($cleanName, $usedNames, true)) {
                $cleanName = $base . "_{$counter}" . ($ext ? ".{$ext}" : "");
                $counter++;
            }
            $usedNames[] = $cleanName;

            $zip->addFile($filePath, $cleanName);
            $addedCount++;
        }

        $zip->close();

        if ($addedCount === 0) {
            throw new RuntimeException("None of the specified files could be added to the zip archive.");
        }

        return [
            'output_path' => $outputZipPath,
            'files_count' => $addedCount,
            'size_bytes'  => (int) filesize($outputZipPath),
        ];
    }
}
