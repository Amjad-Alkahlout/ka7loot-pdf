<?php

namespace Tests\Feature;

use App\Services\File\FileStorageManager;
use App\Services\Image\Matting\DecontaminationMattingService;
use App\Services\Image\U2NetBackgroundRemovalEngine;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class U2NetBackgroundRemovalEngineTest extends TestCase
{
    protected U2NetBackgroundRemovalEngine $engine;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(U2NetBackgroundRemovalEngine::class);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'u2t_' . uniqid('', true);
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_u2net_is_available_when_configured(): void
    {
        if (!file_exists(config('fileprocessor.models.u2net'))) {
            $this->markTestSkipped('U-2-Net model missing.');
        }
        $this->assertTrue($this->engine->isAvailable());
    }

    public function test_u2net_engine_metadata(): void
    {
        $this->assertEquals(U2NetBackgroundRemovalEngine::ID, $this->engine->getId());
        $this->assertEquals(U2NetBackgroundRemovalEngine::LABEL, $this->engine->getLabel());
        $this->assertEquals(U2NetBackgroundRemovalEngine::DESCRIPTION, $this->engine->getDescription());
        $this->assertTrue($this->engine->isAi());
    }

    public function test_u2net_fails_gracefully_on_missing_model(): void
    {
        Config::set('fileprocessor.models.u2net', $this->tempDir . '/missing_model.onnx');
        $this->assertFalse($this->engine->isAvailable());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('U-2-Net engine is not available');

        $inputPath = base_path('tests/Fixtures/background-removal/synthetic-trs-topology.png');
        $outputPath = $this->tempDir . '/out_missing.png';
        $this->engine->removeBackground($inputPath, $outputPath);
    }

    public function test_u2net_fails_on_invalid_sha256(): void
    {
        $corruptModel = $this->tempDir . '/corrupt_hash_u2net.onnx';
        file_put_contents($corruptModel, 'corrupt_invalid_onnx_weights_content');
        Config::set('fileprocessor.models.u2net', $corruptModel);

        $this->assertFalse($this->engine->isAvailable(), 'Model with invalid SHA-256 hash must be rejected.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('U-2-Net engine is not available');

        $inputPath = base_path('tests/Fixtures/background-removal/synthetic-trs-topology.png');
        $outputPath = $this->tempDir . '/out_invalid_hash.png';
        $this->engine->removeBackground($inputPath, $outputPath);
    }

    public function test_u2net_fails_on_corrupt_onnx_model_execution(): void
    {
        $mockSubprocess = $this->createMock(SubprocessExecutorService::class);
        $mockSubprocess->expects($this->once())
            ->method('execute')
            ->willReturn([
                'output'       => json_encode(['success' => false, 'error' => 'ONNX Runtime Exception: Invalid model protobuf format']),
                'error_output' => 'Invalid model protobuf',
                'exit_code'    => 0,
            ]);

        $engine = $this->getMockBuilder(U2NetBackgroundRemovalEngine::class)
            ->setConstructorArgs([$mockSubprocess, app(SystemBinaryDetector::class), app(FileStorageManager::class), app(DecontaminationMattingService::class)])
            ->onlyMethods(['isAvailable'])
            ->getMock();
        $engine->method('isAvailable')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('U-2-Net runner failed: ONNX Runtime Exception');

        $inputPath = base_path('tests/Fixtures/background-removal/synthetic-trs-topology.png');
        $outputPath = $this->tempDir . '/out_corrupt_proto.png';
        $engine->removeBackground($inputPath, $outputPath);
    }

    public function test_u2net_fails_on_node_process_failure_or_nonzero_exit(): void
    {
        $mockSubprocess = $this->createMock(SubprocessExecutorService::class);
        $mockSubprocess->expects($this->once())
            ->method('execute')
            ->willThrowException(new RuntimeException('Command [node] failed with exit code 1: Cannot find module onnxruntime-node'));

        $engine = $this->getMockBuilder(U2NetBackgroundRemovalEngine::class)
            ->setConstructorArgs([$mockSubprocess, app(SystemBinaryDetector::class), app(FileStorageManager::class), app(DecontaminationMattingService::class)])
            ->onlyMethods(['isAvailable'])
            ->getMock();
        $engine->method('isAvailable')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Command [node] failed with exit code 1');

        $inputPath = base_path('tests/Fixtures/background-removal/synthetic-trs-topology.png');
        $outputPath = $this->tempDir . '/out_node_crash.png';
        $engine->removeBackground($inputPath, $outputPath);
    }

    public function test_u2net_fails_on_inference_timeout(): void
    {
        $mockSubprocess = $this->createMock(SubprocessExecutorService::class);
        $mockSubprocess->expects($this->once())
            ->method('execute')
            ->willThrowException(new RuntimeException('Subprocess execution failed: The process exceeded the timeout of 30 seconds.'));

        $engine = $this->getMockBuilder(U2NetBackgroundRemovalEngine::class)
            ->setConstructorArgs([$mockSubprocess, app(SystemBinaryDetector::class), app(FileStorageManager::class), app(DecontaminationMattingService::class)])
            ->onlyMethods(['isAvailable'])
            ->getMock();
        $engine->method('isAvailable')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The process exceeded the timeout of 30 seconds');

        $inputPath = base_path('tests/Fixtures/background-removal/synthetic-trs-topology.png');
        $outputPath = $this->tempDir . '/out_timeout.png';
        $engine->removeBackground($inputPath, $outputPath);
    }

    public function test_u2net_fails_on_malformed_or_empty_runner_output(): void
    {
        $mockSubprocess = $this->createMock(SubprocessExecutorService::class);
        $mockSubprocess->expects($this->once())
            ->method('execute')
            ->willReturn([
                'output'       => 'INVALID_NON_JSON_CRASH_DUMP',
                'error_output' => 'Fatal Error: Out of memory',
                'exit_code'    => 0,
            ]);

        $engine = $this->getMockBuilder(U2NetBackgroundRemovalEngine::class)
            ->setConstructorArgs([$mockSubprocess, app(SystemBinaryDetector::class), app(FileStorageManager::class), app(DecontaminationMattingService::class)])
            ->onlyMethods(['isAvailable'])
            ->getMock();
        $engine->method('isAvailable')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('U-2-Net runner failed: Fatal Error: Out of memory');

        $inputPath = base_path('tests/Fixtures/background-removal/synthetic-trs-topology.png');
        $outputPath = $this->tempDir . '/out_malformed.png';
        $engine->removeBackground($inputPath, $outputPath);
    }

    public function test_synthetic_trs_topology_regression_and_decontamination(): void
    {
        if (!$this->engine->isAvailable()) { $this->markTestSkipped('U-2-Net missing.'); }
        $inputPath = base_path('tests/Fixtures/background-removal/synthetic-trs-topology.png');
        $this->assertFileExists($inputPath, 'Permanent synthetic topology fixture must be present.');

        $outputPath = $this->tempDir . '/synthetic_out.png';

        $meta = $this->engine->removeBackground($inputPath, $outputPath, ['tolerance' => 25.0, 'feather' => 2]);

        // 1. Contract and dimension invariants
        $this->assertTrue($meta['is_ai']);
        $this->assertEquals('ml_u2net', $meta['engine']);
        $this->assertEquals(400, $meta['width']);
        $this->assertEquals(300, $meta['height']);
        $this->assertFileExists($outputPath);

        $res = imagecreatefrompng($outputPath);
        $this->assertEquals(400, imagesx($res), 'Output width must match input width');
        $this->assertEquals(300, imagesy($res), 'Output height must match input height');
        $this->assertTrue(imageistruecolor($res), 'Output image must be true color');

        // 2. Alpha channel transparency assertions
        // A. Outer background (10, 10) must be fully transparent (alpha 127)
        $outerAlpha = (imagecolorat($res, 10, 10) >> 24) & 0x7F;
        $this->assertEquals(127, $outerAlpha, 'Outer perimeter background must be fully transparent (alpha 127).');

        // B. Enclosed white logo hole (100, 100) must remain transparent (alpha 127)
        $holeAlpha = (imagecolorat($res, 100, 100) >> 24) & 0x7F;
        $this->assertEquals(127, $holeAlpha, 'Enclosed white logo background hole must remain fully transparent.');

        // C. Blue logo core (50, 50) must be fully opaque (alpha 0)
        $blueRgb = imagecolorat($res, 50, 50);
        $blueAlpha = ($blueRgb >> 24) & 0x7F;
        $this->assertEquals(0, $blueAlpha, 'Blue logo core must remain fully opaque (alpha 0).');

        // D. Legitimate off-white foreground (#EFEFEF) at (280, 100) must remain fully opaque (alpha 0)
        $offWhiteRgb = imagecolorat($res, 280, 100);
        $offWhiteAlpha = ($offWhiteRgb >> 24) & 0x7F;
        $this->assertEquals(0, $offWhiteAlpha, 'Legitimate off-white interior foreground must remain fully opaque.');

        // E. 1px thin line at (100, 200) must not be erased (alpha 0)
        $stroke1pxAlpha = (imagecolorat($res, 100, 200) >> 24) & 0x7F;
        $this->assertEquals(0, $stroke1pxAlpha, '1px thin stroke must not be erased.');

        // F. 2px signature curve at (200, 255) must not be erased (alpha 0)
        $stroke2pxAlpha = (imagecolorat($res, 200, 255) >> 24) & 0x7F;
        $this->assertEquals(0, $stroke2pxAlpha, '2px signature stroke must not be erased.');

        // 3. Edge decontamination assertion over black composite
        $blackCanvas = imagecreatetruecolor(400, 300);
        imagefilledrectangle($blackCanvas, 0, 0, 400, 300, imagecolorallocate($blackCanvas, 0, 0, 0));
        imagealphablending($blackCanvas, true);
        imagecopy($blackCanvas, $res, 0, 0, 0, 0, 400, 300);

        $whiteHaloNearLine = 0;
        for ($x = 40; $x <= 360; $x++) {
            foreach ([198, 199, 201, 202] as $y) {
                $c = imagecolorat($blackCanvas, $x, $y);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                if ($r > 30 || $g > 30 || $b > 30) {
                    $whiteHaloNearLine++;
                }
            }
        }
        $this->assertEquals(0, $whiteHaloNearLine, 'Edge decontamination must eliminate white/gray edge halo near thin stroke.');

        imagedestroy($blackCanvas);
        imagedestroy($res);
    }

    public function test_real_trs_fixture_produces_matting_and_hole_punches(): void
    {
        $inputPath = base_path('tests/Fixtures/background-removal/real-trs-signature.png');
        if (!file_exists($inputPath)) {
            $this->markTestSkipped('Real TRS fixture not found; skipping optional local benchmark.');
        }

        $outputPath = $this->tempDir . '/real_out.png';

        $meta = $this->engine->removeBackground($inputPath, $outputPath);

        $this->assertTrue($meta['is_ai']);
        $this->assertFileExists($outputPath);

        $im = imagecreatefrompng($outputPath);

        $transparentPixels = 0;
        $w = imagesx($im);
        $h = imagesy($im);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $alpha = (imagecolorat($im, $x, $y) >> 24) & 0x7F;
                if ($alpha === 127) {
                    $transparentPixels++;
                }
            }
        }
        $this->assertGreaterThan(50000, $transparentPixels, 'The AI mask was properly topology-punched by classical fallback');
        imagedestroy($im);
    }
}
