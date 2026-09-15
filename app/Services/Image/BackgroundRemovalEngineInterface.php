<?php

namespace App\Services\Image;

interface BackgroundRemovalEngineInterface
{
    /**
     * Remove the background from an image file and save the transparent PNG output.
     *
     * @param string $inputPath Absolute path to the source image (JPG, PNG, WebP)
     * @param string $outputPath Absolute path where the resulting transparent PNG should be written
     * @param array $options Engine-specific tuning parameters (e.g., tolerance, feather, flood_fill)
     * @return array Metadata about the operation ['engine', 'engine_label', 'width', 'height', 'format', 'is_ai']
     */
    public function removeBackground(string $inputPath, string $outputPath, array $options = []): array;

    /**
     * Unique identifier for this engine.
     */
    public function getId(): string;

    /**
     * Human-readable label for this engine.
     */
    public function getLabel(): string;

    /**
     * Technical description of how this engine operates.
     */
    public function getDescription(): string;

    /**
     * Whether this engine uses machine learning / AI segmentation.
     */
    public function isAi(): bool;
}
