<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Exceptions\DailyQuotaExceededException;
use App\Models\FileJob;
use App\Models\User;
use App\Services\File\DailyFileQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyFileQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_limited_to_twenty_files_and_batch_units_are_counted(): void
    {
        session(['file_quota_guest_key' => 'guest-test']);
        $this->createJobs(19);

        $batch = $this->makeJob(['files' => ['a.pdf', 'b.pdf']]);

        $this->expectException(DailyQuotaExceededException::class);
        $batch->save();
    }

    public function test_authenticated_user_has_fifty_file_limit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->createJobs(50);

        $this->expectException(DailyQuotaExceededException::class);
        $this->makeJob()->save();
    }

    public function test_failed_reservation_is_released_and_successful_settlement_is_idempotent(): void
    {
        $job = $this->makeJob();
        $job->save();

        $quota = app(DailyFileQuotaService::class);
        $quota->release($job);
        $quota->release($job);

        $this->assertDatabaseHas('daily_file_quotas', [
            'subject_type' => 'guest',
            'reserved_count' => 0,
            'consumed_count' => 0,
            'released_count' => 1,
        ]);

        $successful = $this->makeJob();
        $successful->save();
        $quota->settle($successful);
        $quota->settle($successful);

        $this->assertDatabaseHas('daily_file_quotas', [
            'subject_type' => 'guest',
            'reserved_count' => 0,
            'consumed_count' => 1,
        ]);
    }

    public function test_guest_usage_merges_with_existing_user_usage(): void
    {
        session(['file_quota_guest_key' => 'guest-transfer']);
        $this->createJobs(8);

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->createJobs(10);

        app(DailyFileQuotaService::class)->mergeGuestIntoUser($user->id, 'guest-transfer');

        $summary = app(DailyFileQuotaService::class)->summary();
        $this->assertSame(32, $summary['remaining']);
        $this->assertDatabaseCount('file_quota_reservations', 18);

        app(DailyFileQuotaService::class)->mergeGuestIntoUser($user->id, 'guest-transfer');
        $this->assertDatabaseCount('file_quota_reservations', 18);
    }

    public function test_quota_resets_on_the_next_application_day(): void
    {
        Carbon::setTestNow('2026-11-11 23:59:00');
        $this->createJobs(20);
        $this->assertSame(0, app(DailyFileQuotaService::class)->summary()['remaining']);

        Carbon::setTestNow('2026-11-12 00:01:00');
        $this->assertSame(20, app(DailyFileQuotaService::class)->summary()['remaining']);
    }

    protected function createJobs(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $job = $this->makeJob();
            $job->save();
            app(DailyFileQuotaService::class)->settle($job);
        }
    }

    protected function makeJob(array $metadata = []): FileJob
    {
        return new FileJob([
            'id' => (string) Str::uuid(),
            'tool_type' => ToolType::CONVERT_IMAGE,
            'status' => JobStatus::PENDING,
            'input_metadata' => array_merge(['file' => 'input.jpg'], $metadata),
            'expires_at' => now()->addHour(),
        ]);
    }
}
