<?php

namespace Tests\Feature;

use App\Livewire\Tools\CompressPdfComponent;
use App\Livewire\Tools\SplitPdfComponent;
use App\Livewire\Tools\CompressImageComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowStepperDirectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test stepper standalone render in Arabic (RTL) across all 4 stages.
     */
    public function test_stepper_arabic_rtl_all_stages(): void
    {
        App::setLocale('ar');

        // Stage 1: Initial / Uploading state
        $htmlStage1 = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'empty',
            'hasUpload' => false,
        ])->render();

        $this->assertStringContainsString('dir="rtl"', $htmlStage1);
        $this->assertStringContainsString('data-direction="rtl"', $htmlStage1);
        $this->assertStringContainsString('right-6 left-auto', $htmlStage1);
        $this->assertStringContainsString('right: 1.5rem; left: auto; width: 10%;', $htmlStage1);
        $this->assertStringContainsString(__('ui.state_uploading'), $htmlStage1);

        // Stage 2: Uploaded state
        $htmlStage2 = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'uploaded',
            'hasUpload' => true,
        ])->render();

        $this->assertStringContainsString('dir="rtl"', $htmlStage2);
        $this->assertStringContainsString('right-6 left-auto', $htmlStage2);
        $this->assertStringContainsString('right: 1.5rem; left: auto; width: 38%;', $htmlStage2);
        $this->assertStringContainsString(__('ui.state_uploaded'), $htmlStage2);

        // Stage 3: Processing state
        $htmlStage3 = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'processing',
            'hasUpload' => true,
        ])->render();

        $this->assertStringContainsString('dir="rtl"', $htmlStage3);
        $this->assertStringContainsString('right-6 left-auto', $htmlStage3);
        $this->assertStringContainsString('right: 1.5rem; left: auto; width: 72%;', $htmlStage3);
        $this->assertStringContainsString(__('ui.state_processing'), $htmlStage3);
        $this->assertStringContainsString('animate-pulse', $htmlStage3);

        // Stage 4: Completed state
        $htmlStage4 = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'completed',
            'hasUpload' => true,
        ])->render();

        $this->assertStringContainsString('dir="rtl"', $htmlStage4);
        $this->assertStringContainsString('right-6 left-auto', $htmlStage4);
        $this->assertStringContainsString('right: 1.5rem; left: auto; width: calc(100% - 3rem);', $htmlStage4);
        $this->assertStringContainsString(__('ui.state_completed'), $htmlStage4);
    }

    /**
     * Test stepper standalone render in English (LTR) across all 4 stages.
     */
    public function test_stepper_english_ltr_all_stages(): void
    {
        App::setLocale('en');

        // Stage 1: Initial / Uploading state
        $htmlStage1 = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'empty',
            'hasUpload' => false,
        ])->render();

        $this->assertStringContainsString('dir="ltr"', $htmlStage1);
        $this->assertStringContainsString('data-direction="ltr"', $htmlStage1);
        $this->assertStringContainsString('left-6 right-auto', $htmlStage1);
        $this->assertStringContainsString('left: 1.5rem; right: auto; width: 10%;', $htmlStage1);
        $this->assertStringContainsString('Uploading', $htmlStage1);

        // Stage 2: Uploaded state
        $htmlStage2 = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'uploaded',
            'hasUpload' => true,
        ])->render();

        $this->assertStringContainsString('dir="ltr"', $htmlStage2);
        $this->assertStringContainsString('left-6 right-auto', $htmlStage2);
        $this->assertStringContainsString('left: 1.5rem; right: auto; width: 38%;', $htmlStage2);
        $this->assertStringContainsString('Uploaded', $htmlStage2);

        // Stage 3: Processing state
        $htmlStage3 = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'processing',
            'hasUpload' => true,
        ])->render();

        $this->assertStringContainsString('dir="ltr"', $htmlStage3);
        $this->assertStringContainsString('left-6 right-auto', $htmlStage3);
        $this->assertStringContainsString('left: 1.5rem; right: auto; width: 72%;', $htmlStage3);
        $this->assertStringContainsString('Processing', $htmlStage3);
        $this->assertStringContainsString('animate-pulse', $htmlStage3);

        // Stage 4: Completed state
        $htmlStage4 = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'completed',
            'hasUpload' => true,
        ])->render();

        $this->assertStringContainsString('dir="ltr"', $htmlStage4);
        $this->assertStringContainsString('left-6 right-auto', $htmlStage4);
        $this->assertStringContainsString('left: 1.5rem; right: auto; width: calc(100% - 3rem);', $htmlStage4);
        $this->assertStringContainsString('Completed', $htmlStage4);
    }

    /**
     * Test stage order sequence in DOM:
     * Step 1 (Uploading) -> Step 2 (Uploaded) -> Step 3 (Processing) -> Step 4 (Completed).
     * In RTL layout, this natural flex order places Step 1 on the right and Step 4 on the left.
     */
    public function test_stage_order_sequence_and_relative_positions(): void
    {
        foreach (['ar', 'en'] as $locale) {
            App::setLocale($locale);

            $html = view('livewire.tools.workflow-stepper', [
                'jobStatus' => 'processing',
                'hasUpload' => true,
            ])->render();

            $posStep1 = strpos($html, 'data-step="1"');
            $posStep2 = strpos($html, 'data-step="2"');
            $posStep3 = strpos($html, 'data-step="3"');
            $posStep4 = strpos($html, 'data-step="4"');

            $this->assertNotFalse($posStep1);
            $this->assertNotFalse($posStep2);
            $this->assertNotFalse($posStep3);
            $this->assertNotFalse($posStep4);

            // Verify sequential appearance in DOM
            $this->assertTrue($posStep1 < $posStep2, "Step 1 must precede Step 2 in DOM for locale {$locale}");
            $this->assertTrue($posStep2 < $posStep3, "Step 2 must precede Step 3 in DOM for locale {$locale}");
            $this->assertTrue($posStep3 < $posStep4, "Step 3 must precede Step 4 in DOM for locale {$locale}");
        }
    }

    /**
     * Test explicit direction override via prop without relying only on app locale.
     */
    public function test_explicit_direction_override_prop(): void
    {
        // Even if app locale is English, explicit isRtl=true must render RTL stepper
        App::setLocale('en');

        $htmlRtl = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'uploaded',
            'hasUpload' => true,
            'isRtl'     => true,
        ])->render();

        $this->assertStringContainsString('dir="rtl"', $htmlRtl);
        $this->assertStringContainsString('right-6 left-auto', $htmlRtl);
        $this->assertStringContainsString('right: 1.5rem; left: auto;', $htmlRtl);

        // Even if app locale is Arabic, explicit isRtl=false must render LTR stepper
        App::setLocale('ar');

        $htmlLtr = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'uploaded',
            'hasUpload' => true,
            'isRtl'     => false,
        ])->render();

        $this->assertStringContainsString('dir="ltr"', $htmlLtr);
        $this->assertStringContainsString('left-6 right-auto', $htmlLtr);
        $this->assertStringContainsString('left: 1.5rem; right: auto;', $htmlLtr);
    }

    /**
     * Test Livewire tool components render direction-aware stepper.
     */
    public function test_livewire_tool_components_render_correct_direction(): void
    {
        // Arabic session
        App::setLocale('ar');

        Livewire::test(CompressPdfComponent::class)
            ->assertSeeHtml('dir="rtl"')
            ->assertSeeHtml('right-6 left-auto')
            ->assertSeeHtml('right: 1.5rem; left: auto; width: 10%;');

        Livewire::test(SplitPdfComponent::class)
            ->assertSeeHtml('dir="rtl"')
            ->assertSeeHtml('right-6 left-auto')
            ->assertSeeHtml('right: 1.5rem; left: auto; width: 10%;');

        // English session
        App::setLocale('en');

        Livewire::test(CompressPdfComponent::class)
            ->assertSeeHtml('dir="ltr"')
            ->assertSeeHtml('left-6 right-auto')
            ->assertSeeHtml('left: 1.5rem; right: auto; width: 10%;');

        Livewire::test(CompressImageComponent::class)
            ->assertSeeHtml('dir="ltr"')
            ->assertSeeHtml('left-6 right-auto')
            ->assertSeeHtml('left: 1.5rem; right: auto; width: 10%;');
    }

    /**
     * Test failed state rendering in both RTL and LTR.
     */
    public function test_failed_state_rendering(): void
    {
        App::setLocale('ar');
        $htmlAr = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'failed',
            'hasUpload' => true,
        ])->render();

        $this->assertStringContainsString(__('ui.state_failed'), $htmlAr);
        $this->assertStringContainsString('bg-rose-600', $htmlAr);
        $this->assertStringContainsString('right-6 left-auto', $htmlAr);

        App::setLocale('en');
        $htmlEn = view('livewire.tools.workflow-stepper', [
            'jobStatus' => 'failed',
            'hasUpload' => true,
        ])->render();

        $this->assertStringContainsString('Failed', $htmlEn);
        $this->assertStringContainsString('bg-rose-600', $htmlEn);
        $this->assertStringContainsString('left-6 right-auto', $htmlEn);
    }
}
