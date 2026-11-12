<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\File\DailyFileQuotaService;
use App\Services\File\FileStorageManager;
use App\Services\Account\AccountDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\View\View;

class AccountController extends Controller
{
    public function show(DailyFileQuotaService $quota, FileStorageManager $storage): View
    {
        /** @var User $user */
        $user = auth()->user();

        $jobs = $user->fileJobs()
            ->with('processedFiles')
            ->latest()
            ->limit(10)
            ->get();
        $availableFileIds = [];
        foreach ($jobs as $job) {
            $availableFileIds[$job->id] = $job->processedFiles
                ->filter(fn ($file) => $storage->hasJobFile($job->id, $file->stored_filename))
                ->pluck('id')
                ->all();
        }

        return view('pages.account', [
            'user' => $user,
            'quota' => $quota->summary(),
            'jobs' => $jobs,
            'availableFileIds' => $availableFileIds,
        ]);
    }

    public function destroy(Request $request, AccountDeletionService $deletion): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:DELETE ACCOUNT'],
        ]);

        try {
            $deletion->delete($request->user());
        } catch (\Throwable $e) {
            Log::warning('Account deletion failed.', [
                'user_id' => $request->user()->id,
                'reason' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'account' => __('ui.account_deletion_failed'),
            ]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', __('ui.account_deleted'));
    }
}
