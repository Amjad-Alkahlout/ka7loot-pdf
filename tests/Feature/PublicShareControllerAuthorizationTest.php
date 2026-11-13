<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\User;
use App\Services\Share\TemporaryShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicShareControllerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_route_creates_share_for_current_guest_session(): void
    {
        $sessionKey = 'share-create-owner-key';
        $this->withSession(['guest_file_job_key' => $sessionKey]);
        $job = $this->createCompletedGuestJob($sessionKey);

        $response = $this->withSession(['guest_file_job_key' => $sessionKey])->postJson('/share/create', [
            'job_id' => $job->id,
            'file_id' => $job->processedFiles()->first()->id,
            'expiration_minutes' => 20,
            'max_downloads' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['url', 'qr_svg', 'expires_at', 'remaining_downloads']);
        $this->assertDatabaseHas('temporary_shares', [
            'file_job_id' => $job->id,
            'max_downloads' => 2,
        ]);
    }

    public function test_create_and_revoke_routes_reject_another_guest_session(): void
    {
        $job = $this->createCompletedGuestJob('owner-session-key');
        $result = app(TemporaryShareService::class)->createShare($job);
        $share = $result['share'];

        $this->postJson('/share/create', ['job_id' => $job->id])->assertNotFound();
        $this->postJson('/s/' . $result['token'] . '/revoke')->assertNotFound();

        $this->assertNull($share->fresh()->revoked_at);
    }

    public function test_revoke_route_allows_current_guest_job_owner(): void
    {
        $sessionKey = 'share-revoke-owner-key';
        $this->withSession(['guest_file_job_key' => $sessionKey]);
        $job = $this->createCompletedGuestJob($sessionKey);
        $shareService = app(TemporaryShareService::class);
        $result = $shareService->createShare($job);

        $this->withSession(['guest_file_job_key' => $sessionKey])
            ->postJson('/s/' . $result['token'] . '/revoke')
            ->assertOk()
            ->assertJson(['revoked' => true]);

        $this->assertNotNull($result['share']->fresh()->revoked_at);
    }

    public function test_authenticated_owner_can_create_share_but_another_user_cannot(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $job = $this->createCompletedGuestJob(null, $owner);

        $this->actingAs($otherUser)
            ->postJson('/share/create', ['job_id' => $job->id])
            ->assertNotFound();

        $this->actingAs($owner)
            ->postJson('/share/create', ['job_id' => $job->id])
            ->assertCreated();
    }

    private function createCompletedGuestJob(?string $sessionKey, ?User $user = null): FileJob
    {
        $metadata = $sessionKey === null ? [] : ['guest_session_key' => $sessionKey];
        $job = FileJob::create([
            'tool_type' => ToolType::MERGE_PDF,
            'status' => JobStatus::COMPLETED,
            'user_id' => $user?->id,
            'input_metadata' => $metadata,
            'expires_at' => now()->addHour(),
        ]);
        $job->processedFiles()->create([
            'original_name' => 'shared.pdf',
            'stored_filename' => 'shared.pdf',
            'mime_type' => 'application/pdf',
            'original_size_bytes' => 100,
            'processed_size_bytes' => 90,
        ]);

        return $job;
    }
}
