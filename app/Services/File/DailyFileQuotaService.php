<?php

namespace App\Services\File;

use App\Exceptions\DailyQuotaExceededException;
use App\Models\DailyFileQuota;
use App\Models\FileJob;
use App\Models\FileQuotaReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DailyFileQuotaService
{
    public function dailyLimit(): int
    {
        return auth()->check()
            ? (int) config('fileprocessor.daily_quota.authenticated', 50)
            : (int) config('fileprocessor.daily_quota.guest', 20);
    }

    public function usageDate(): string
    {
        return now(config('app.timezone'))->toDateString();
    }

    public function guestKey(): string
    {
        if (! session()->has('file_quota_guest_key')) {
            session(['file_quota_guest_key' => (string) Str::uuid()]);
        }

        return (string) session('file_quota_guest_key');
    }

    public function subject(): array
    {
        if (auth()->check()) {
            return [
                'type' => 'user',
                'key' => 'user:'.auth()->id(),
                'user_id' => auth()->id(),
            ];
        }

        $key = $this->guestKey();

        return ['type' => 'guest', 'key' => 'guest:'.$key, 'user_id' => null];
    }

    public function summary(): array
    {
        $subject = $this->subject();
        if (! Schema::hasTable('daily_file_quotas')) {
            $limit = $this->dailyLimit();

            return [
                'limit' => $limit,
                'used' => 0,
                'reserved' => 0,
                'remaining' => $limit,
                'guest' => $subject['type'] === 'guest',
            ];
        }

        $quota = DailyFileQuota::whereDate('usage_date', $this->usageDate())
            ->where('subject_type', $subject['type'])
            ->where('subject_key', $subject['key'])
            ->first();

        $used = (int) ($quota?->consumed_count ?? 0);
        $reserved = (int) ($quota?->reserved_count ?? 0);
        $limit = $this->dailyLimit();

        return [
            'limit' => $limit,
            'used' => $used,
            'reserved' => $reserved,
            'remaining' => max(0, $limit - $used - $reserved),
            'guest' => $subject['type'] === 'guest',
        ];
    }

    public function assertAvailable(int $units = 1): void
    {
        $summary = $this->summary();
        if ($units > $summary['remaining']) {
            throw new DailyQuotaExceededException($units, $summary['remaining'], $summary['limit']);
        }
    }

    public function reserveForJob(FileJob $job): FileQuotaReservation
    {
        $existing = FileQuotaReservation::where('file_job_id', $job->id)->first();
        if ($existing && $existing->status === 'reserved') {
            return $existing;
        }

        $units = $this->unitsForJob($job);
        $subject = $existing
            ? ['type' => $existing->subject_type, 'key' => $existing->subject_key, 'user_id' => $existing->user_id]
            : $this->subject();
        $date = $existing?->usage_date?->toDateString() ?? $this->usageDate();

        return DB::transaction(function () use ($job, $units, $subject, $date, $existing) {
            if ($existing && $existing->status !== 'released') {
                return $existing;
            }
            DailyFileQuota::upsert([[
                'usage_date' => $date,
                'subject_type' => $subject['type'],
                'subject_key' => $subject['key'],
                'user_id' => $subject['user_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['usage_date', 'subject_type', 'subject_key'], ['updated_at']);
            $quota = DailyFileQuota::whereDate('usage_date', $date)
                ->where('subject_type', $subject['type'])
                ->where('subject_key', $subject['key'])
                ->firstOrFail();
            $quota = DailyFileQuota::whereKey($quota->id)->lockForUpdate()->first();
            $available = $this->dailyLimit() - (int) $quota->consumed_count - (int) $quota->reserved_count;

            if ($units > $available) {
                throw new DailyQuotaExceededException($units, max(0, $available), $this->dailyLimit());
            }

            $quota->increment('reserved_count', $units);

            if ($existing) {
                $existing->update([
                    'consumed_units' => 0,
                    'released_units' => 0,
                    'status' => 'reserved',
                ]);

                return $existing->fresh();
            }

            return FileQuotaReservation::create([
                'id' => (string) Str::uuid(),
                'file_job_id' => $job->id,
                'usage_date' => $date,
                'subject_type' => $subject['type'],
                'subject_key' => $subject['key'],
                'user_id' => $subject['user_id'],
                'units' => $units,
                'status' => 'reserved',
            ]);
        });
    }

    public function settle(FileJob $job): void
    {
        $reservation = FileQuotaReservation::where('file_job_id', $job->id)->first();
        if (! $reservation || $reservation->status !== 'reserved') {
            return;
        }

        DB::transaction(function () use ($reservation) {
            $reservation = FileQuotaReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'reserved') {
                return;
            }
            $quota = $this->lockedQuota($reservation);
            $quota->decrement('reserved_count', $reservation->units);
            $quota->increment('consumed_count', $reservation->units);
            $reservation->update([
                'consumed_units' => $reservation->units,
                'status' => 'consumed',
            ]);
        });
    }

    public function release(FileJob $job): void
    {
        $reservation = FileQuotaReservation::where('file_job_id', $job->id)->first();
        if (! $reservation || $reservation->status !== 'reserved') {
            return;
        }

        DB::transaction(function () use ($reservation) {
            $reservation = FileQuotaReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->status !== 'reserved') {
                return;
            }
            $quota = $this->lockedQuota($reservation);
            $quota->decrement('reserved_count', $reservation->units);
            $quota->increment('released_count', $reservation->units);
            $reservation->update([
                'released_units' => $reservation->units,
                'status' => 'released',
            ]);
        });
    }

    public function mergeGuestIntoUser(int $userId, ?string $guestKey = null): void
    {
        $guestKey ??= (string) session('file_quota_guest_key', '');
        if ($guestKey === '') {
            return;
        }

        $date = $this->usageDate();
        DB::transaction(function () use ($userId, $guestKey, $date) {
            $guest = DailyFileQuota::whereDate('usage_date', $date)
                ->where('subject_type', 'guest')
                ->where('subject_key', 'guest:'.$guestKey)
                ->lockForUpdate()
                ->first();
            if (! $guest) {
                return;
            }

            $user = DailyFileQuota::whereDate('usage_date', $date)
                ->where('subject_type', 'user')
                ->where('subject_key', 'user:'.$userId)
                ->lockForUpdate()
                ->first();
            if (! $user) {
                $user = DailyFileQuota::create([
                    'usage_date' => $date,
                    'subject_type' => 'user',
                    'subject_key' => 'user:'.$userId,
                    'user_id' => $userId,
                ]);
                $user = DailyFileQuota::whereKey($user->id)->lockForUpdate()->first();
            }

            $user->increment('reserved_count', $guest->reserved_count);
            $user->increment('consumed_count', $guest->consumed_count);
            $user->increment('released_count', $guest->released_count);
            if (($guest->consumed_count + $guest->reserved_count) >= (int) config('fileprocessor.daily_quota.guest', 20)) {
                $guest->increment('guest_sign_in_count');
            }

            FileQuotaReservation::whereDate('usage_date', $date)
                ->where('subject_type', 'guest')
                ->where('subject_key', 'guest:'.$guestKey)
                ->update([
                    'subject_type' => 'user',
                    'subject_key' => 'user:'.$userId,
                    'user_id' => $userId,
                ]);

            $guest->update([
                'reserved_count' => 0,
                'consumed_count' => 0,
                'released_count' => 0,
            ]);
        });
    }

    public function unitsForJob(FileJob $job): int
    {
        $meta = $job->input_metadata ?? [];
        foreach (['files', 'uploads'] as $key) {
            if (isset($meta[$key]) && is_array($meta[$key])) {
                return max(1, count($meta[$key]));
            }
        }

        return 1;
    }

    protected function lockedQuota(FileQuotaReservation $reservation): DailyFileQuota
    {
        return DailyFileQuota::whereDate('usage_date', $reservation->usage_date)
            ->where('subject_type', $reservation->subject_type)
            ->where('subject_key', $reservation->subject_key)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
