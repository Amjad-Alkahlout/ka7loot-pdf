<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\DailyFileQuota;
use App\Models\FileJob;
use App\Models\FileQuotaReservation;
use App\Models\User;
use App\Services\File\FileStorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletion_requires_the_exact_confirmation_phrase(): void
    {
        $user = User::factory()->create(['clerk_id' => 'user_delete_1']);
        $this->actingAs($user);

        $this->delete(route('account.destroy'), ['confirmation' => 'delete account'])
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_successful_deletion_removes_clerk_user_owned_data_and_storage(): void
    {
        Http::fake([
            'https://api.clerk.com/v1/users/user_delete_2' => Http::response([], 200),
        ]);
        config(['clerk.secret_key' => 'test-secret']);

        $user = User::factory()->create(['clerk_id' => 'user_delete_2']);
        $this->actingAs($user);
        $job = FileJob::withoutEvents(fn () => FileJob::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'tool_type' => ToolType::MERGE_PDF,
            'status' => JobStatus::COMPLETED,
            'download_token' => Str::random(48),
            'expires_at' => now()->addHour(),
        ]));
        $job->processedFiles()->create([
            'id' => (string) Str::uuid(),
            'original_name' => 'private.pdf',
            'stored_filename' => 'private.pdf',
            'mime_type' => 'application/pdf',
        ]);
        app(FileStorageManager::class)->putFileContents($job->id, 'private.pdf', 'secret');
        DailyFileQuota::create([
            'usage_date' => now()->toDateString(),
            'subject_type' => 'user',
            'subject_key' => 'user:'.$user->id,
            'user_id' => $user->id,
        ]);
        FileQuotaReservation::create([
            'id' => (string) Str::uuid(),
            'file_job_id' => $job->id,
            'usage_date' => now()->toDateString(),
            'subject_type' => 'user',
            'subject_key' => 'user:'.$user->id,
            'user_id' => $user->id,
            'units' => 1,
            'status' => 'consumed',
        ]);

        $this->delete(route('account.destroy'), ['confirmation' => 'DELETE ACCOUNT'])
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', __('ui.account_deleted'));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.clerk.com/v1/users/user_delete_2');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('file_jobs', ['id' => $job->id]);
        $this->assertDatabaseMissing('daily_file_quotas', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('file_quota_reservations', ['user_id' => $user->id]);
        $this->assertDirectoryDoesNotExist(app(FileStorageManager::class)->getRootPath().DIRECTORY_SEPARATOR.$job->id);
        $this->assertFalse(auth()->check());
    }

    public function test_clerk_failure_preserves_local_account_and_data(): void
    {
        Http::fake([
            'https://api.clerk.com/v1/users/user_delete_3' => Http::response([], 503),
        ]);
        config(['clerk.secret_key' => 'test-secret']);

        $user = User::factory()->create(['clerk_id' => 'user_delete_3']);
        $this->actingAs($user);

        $this->delete(route('account.destroy'), ['confirmation' => 'DELETE ACCOUNT'])
            ->assertSessionHasErrors('account');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertTrue(auth()->check());
    }
}
