<?php

namespace App\Services\Account;

use App\Models\DailyFileQuota;
use App\Models\FileJob;
use App\Models\FileQuotaReservation;
use App\Models\ToolUsage;
use App\Models\User;
use App\Services\Auth\ClerkSessionService;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountDeletionService
{
    public function __construct(
        protected ClerkSessionService $clerk,
        protected FileStorageManager $storage,
    ) {}

    public function delete(User $user): void
    {
        if (blank($user->clerk_id)) {
            throw new RuntimeException('This account is not linked to Clerk.');
        }

        $lock = Cache::lock('account-deletion:user:'.$user->id, 120);
        if (! $lock->get()) {
            throw new RuntimeException('Account deletion is already in progress.');
        }

        try {
            $this->clerk->deleteUser($user->clerk_id);

            $jobIds = FileJob::where('user_id', $user->id)->pluck('id')->all();
            foreach ($jobIds as $jobId) {
                if (! $this->storage->deleteJobDirectory($jobId)) {
                    throw new RuntimeException('Temporary account files could not be deleted.');
                }
            }

            DB::transaction(function () use ($user, $jobIds): void {
                if ($jobIds !== []) {
                    ToolUsage::whereIn('file_job_id', $jobIds)->delete();
                    FileJob::whereIn('id', $jobIds)->where('user_id', $user->id)->delete();
                }

                FileQuotaReservation::where('user_id', $user->id)->delete();
                DailyFileQuota::where('user_id', $user->id)->delete();
                User::whereKey($user->getKey())->delete();
            });
        } finally {
            $lock->release();
        }
    }
}
