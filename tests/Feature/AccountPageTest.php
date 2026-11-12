<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_requires_authentication(): void
    {
        $this->get(route('account'))->assertRedirect(route('home'));
    }

    public function test_account_shows_only_owned_jobs_and_quota_in_english(): void
    {
        $user = User::factory()->create(['name' => 'Account User', 'email' => 'account@example.com']);
        $other = User::factory()->create(['name' => 'Other User']);
        $this->actingAs($user)->withSession(['locale' => 'en']);

        $job = $this->createJob($user, JobStatus::COMPLETED);
        $job->processedFiles()->create([
            'id' => (string) Str::uuid(),
            'original_name' => 'result.pdf',
            'stored_filename' => 'result.pdf',
            'mime_type' => 'application/pdf',
        ]);
        $this->createJob($other, JobStatus::COMPLETED);

        $response = $this->get(route('account'));

        $response->assertOk()
            ->assertSee('Account User')
            ->assertSee('Today')
            ->assertSee('Merge PDF Files')
            ->assertDontSee('Other User');
        $this->assertStringNotContainsString('other', $response->getContent());
    }

    public function test_expired_owned_job_has_no_download_link_and_arabic_is_rtl(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['locale' => 'ar']);
        $job = $this->createJob($user, JobStatus::EXPIRED, now()->subMinute());
        $file = $job->processedFiles()->create([
            'id' => (string) Str::uuid(),
            'original_name' => 'expired.pdf',
            'stored_filename' => 'expired.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->get(route('account'));

        $response->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('ui.status_expired'))
            ->assertSee(__('ui.files_expired_account'));
        $this->assertStringNotContainsString(route('file.download', ['token' => $job->download_token, 'fileId' => $file->id]), $response->getContent());
    }

    public function test_user_cannot_download_another_users_job(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $this->actingAs($owner);
        $job = $this->createJob($owner, JobStatus::COMPLETED);
        $file = $job->processedFiles()->create([
            'id' => (string) Str::uuid(),
            'original_name' => 'private.pdf',
            'stored_filename' => 'private.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $this->actingAs($attacker)
            ->get(route('file.download', ['token' => $job->download_token, 'fileId' => $file->id]))
            ->assertNotFound();
    }

    protected function createJob(User $user, JobStatus $status, $expiresAt = null): FileJob
    {
        return FileJob::withoutEvents(fn () => FileJob::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tool_type' => ToolType::MERGE_PDF,
            'status' => $status,
            'download_token' => Str::random(48),
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]));
    }
}
