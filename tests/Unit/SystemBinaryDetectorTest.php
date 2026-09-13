<?php

namespace Tests\Unit;

use App\Services\System\SystemBinaryDetector;
use Tests\TestCase;

class SystemBinaryDetectorTest extends TestCase
{
    protected SystemBinaryDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new SystemBinaryDetector();
    }

    public function test_gd_is_detected_as_ready(): void
    {
        $this->assertTrue($this->detector->canProcessImages());
    }

    public function test_fpdi_is_detected_as_ready(): void
    {
        $this->assertTrue($this->detector->canManipulatePdf());
    }

    public function test_configured_binary_override_is_honored(): void
    {
        // Fake binary override pointing to php.exe (which exists)
        $phpPath = PHP_BINARY;
        config(['fileprocessor.binaries.ghostscript' => $phpPath]);

        $this->detector->probe(forceRefresh: true);

        $this->assertTrue($this->detector->hasGhostscript());
        $this->assertEquals($phpPath, $this->detector->getGhostscriptPath());
    }

    public function test_detector_returns_expected_types(): void
    {
        $this->detector->probe(forceRefresh: true);

        $this->assertIsBool($this->detector->hasGhostscript());
        $this->assertIsBool($this->detector->hasPoppler());
        $this->assertIsBool($this->detector->canRasterizePdf());
        $this->assertIsBool($this->detector->canDeepCompressPdf());
    }
}
