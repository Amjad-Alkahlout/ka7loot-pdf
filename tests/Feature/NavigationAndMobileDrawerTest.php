<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\File\DailyFileQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationAndMobileDrawerTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_renders_mobile_toggle_and_quota_popover_for_guest_in_english(): void
    {
        $response = $this->withSession(['locale' => 'en'])->get('/');

        $response->assertOk();

        // 1. Mobile hamburger toggle
        $response->assertSee('id="mobile-menu-button"', false);
        $response->assertSee('aria-controls="mobile-nav-drawer"', false);
        $response->assertSee('aria-expanded="false"', false);
        $response->assertSee(__('ui.toggle_navigation'));

        // 2. Quota popover trigger and panel
        $response->assertSee('id="quota-popover-button"', false);
        $response->assertSee('id="quota-popover"', false);
        $response->assertSee('role="progressbar"', false);
        $response->assertSee(__('ui.daily_quota'));
        $response->assertSee(__('ui.quota_resets_midnight'));
        $response->assertSee(__('ui.guest_quota_prompt'));

        // 3. Mobile drawer container and contents
        $response->assertSee('id="mobile-nav-drawer"', false);
        $response->assertSee('id="mobile-drawer-close"', false);
        $response->assertSee(__('ui.pdf_essentials'));
        $response->assertSee(__('ui.convert_export'));
        $response->assertSee(__('ui.security_signing'));
        $response->assertSee(__('ui.image_tools_menu'));

        // Key tool links inside drawer
        $response->assertSee(route('tools.merge-pdf'));
        $response->assertSee(route('tools.compress-pdf'));
        $response->assertSee(route('tools.split-pdf'));
        $response->assertSee(route('tools.organize-pdf'));
        $response->assertSee(route('tools.jpg-to-pdf'));
        $response->assertSee(route('tools.heic-to-pdf'));
        $response->assertSee(route('tools.sign-pdf'));
        $response->assertSee(route('tools.edit-pdf'));
        $response->assertSee(route('tools.compress-image'));
        $response->assertSee(route('tools.remove-background'));
    }

    public function test_header_and_drawer_render_authenticated_controls(): void
    {
        $user = User::factory()->create([
            'name' => 'Sara Al-Ahmad',
            'email' => 'sara@example.com',
        ]);

        $response = $this->actingAs($user)->withSession(['locale' => 'en'])->get('/');

        $response->assertOk();

        // Authenticated user controls
        $response->assertSee('Sara Al-Ahmad');
        $response->assertSee(route('account'));
        $response->assertSee(__('ui.sign_out'));
        $response->assertSee(__('ui.view_account'));
    }

    public function test_header_and_drawer_render_cleanly_in_arabic_with_rtl(): void
    {
        $response = $this->withSession(['locale' => 'ar'])->get('/');

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);

        // Arabic translations
        $response->assertSee(__('ui.toggle_navigation'));
        $response->assertSee(__('ui.close_menu'));
        $response->assertSee(__('ui.daily_quota'));
        $response->assertSee(__('ui.pdf_essentials'));
        $response->assertSee(__('ui.convert_export'));
        $response->assertSee(__('ui.security_signing'));
        $response->assertSee(__('ui.image_tools_menu'));
        $response->assertSee(__('ui.quota_resets_midnight'));

        // Drawer alignment in RTL
        $response->assertSee('left-0');
    }

    public function test_quota_popover_reflects_actual_remaining_and_limit(): void
    {
        $quota = app(DailyFileQuotaService::class)->summary();

        $response = $this->get('/');
        $response->assertOk();

        $response->assertSee("{$quota['remaining']}/{$quota['limit']}");
    }
}
