<?php

namespace Tests\Feature\BackgroundRemoval;

use App\Services\Image\HybridBackgroundRemovalEngine;
use App\Services\Image\U2NetBackgroundRemovalEngine;
use Tests\TestCase;

class RealFixtureQualityTest extends TestCase
{
    public function test_u2net_perfectly_removes_white_islands_but_preserves_signature()
    {
        $engine = app(HybridBackgroundRemovalEngine::class);
        $input = base_path('tests/Fixtures/background-removal/real-trs-signature.png');
        if (!file_exists($input)) {
            $this->markTestSkipped('Real TRS fixture not found.');
        }
        $output = storage_path('app/private/secure_temp/test_out.png');

        $result = $engine->removeBackground($input, $output);
        $this->assertTrue(file_exists($output), 'Output file must exist.');

        $out = imagecreatefrompng($output);
        $w = imagesx($out);
        $h = imagesy($out);

        $whiteOpaque = 0;
        $blackOpaque = 0;
        $blueOpaque = 0;
        $blueEdgeOpaque = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $v = imagecolorat($out, $x, $y);
                $alpha = ($v >> 24) & 0x7F;

                if ($alpha < 60) {
                    $r = ($v >> 16) & 0xFF;
                    $g = ($v >> 8) & 0xFF;
                    $b = $v & 0xFF;

                    if ($r >= 220 && $g >= 220 && $b >= 220) {
                        $whiteOpaque++;
                    }
                    if ($r < 120 && $g < 120 && $b < 120) {
                        $blackOpaque++;
                    }
                    if ($r < 100 && $b > 120 && $b > $g) {
                        $blueOpaque++;
                    }
                    if ($r < 220 && $b > $r + 10 && $b > $g + 10) {
                        $blueEdgeOpaque++;
                    }
                }
            }
        }

        $this->assertGreaterThan(1000, $blackOpaque, 'Black signature strokes must be preserved.');
        $this->assertGreaterThan(1000, $blueOpaque, 'Blue FIS logo must be preserved.');
        $this->assertGreaterThan(7000, $blueEdgeOpaque, 'Blue FIS logo anti-aliased foreground edges must not be aggressively eroded.');
        $this->assertLessThan(150, $whiteOpaque, 'Large white background regions must be removed.');

        @unlink($output);
    }
}
