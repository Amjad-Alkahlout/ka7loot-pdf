<x-app-layout :title="__('ui.account')" :description="__('ui.account_description')" :noindex="true">
    <div class="bg-slate-50 py-10 sm:py-14">
        <div class="mx-auto max-w-6xl space-y-8 px-4 sm:px-6 lg:px-8">
            <div>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900">{{ __('ui.account') }}</h1>
                <p class="mt-2 text-sm text-slate-600">{{ __('ui.account_description') }}</p>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="account-details">
                    <h2 id="account-details" class="text-lg font-bold text-slate-900">{{ __('ui.account_details') }}</h2>
                    <dl class="mt-5 space-y-4 text-sm">
                        <div class="flex items-start justify-between gap-4 border-b border-slate-100 pb-3">
                            <dt class="text-slate-500">{{ __('ui.name') }}</dt>
                            <dd class="text-end font-semibold text-slate-900">{{ $user->name }}</dd>
                        </div>
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-slate-500">{{ __('ui.email') }}</dt>
                            <dd class="break-all text-end font-semibold text-slate-900">{{ $user->email }}</dd>
                        </div>
                    </dl>
                </section>

                @php
                    $quotaUsed = $quota['used'] + $quota['reserved'];
                    $quotaPercent = $quota['limit'] > 0 ? min(100, (int) round(($quotaUsed / $quota['limit']) * 100)) : 0;
                @endphp
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="account-quota">
                    <div class="flex items-start justify-between gap-4">
                        <h2 id="account-quota" class="text-lg font-bold text-slate-900">{{ __('ui.daily_quota') }}</h2>
                        <span class="text-sm font-bold text-indigo-700">{{ $quota['remaining'] }}/{{ $quota['limit'] }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">{{ __('ui.daily_quota_account_hint') }}</p>
                    <div class="mt-5 h-3 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $quotaUsed }}" aria-valuemin="0" aria-valuemax="{{ $quota['limit'] }}" aria-label="{{ __('ui.daily_quota') }}">
                        <div class="h-full rounded-full bg-indigo-600 transition-all" style="width: {{ $quotaPercent }}%"></div>
                    </div>
                    <div class="mt-3 flex justify-between gap-4 text-xs text-slate-500">
                        <span>{{ __('ui.used') }}: {{ $quota['used'] }}</span>
                        @if($quota['reserved'] > 0)
                            <span>{{ __('ui.processing_reserved') }}: {{ $quota['reserved'] }}</span>
                        @endif
                        <span>{{ __('ui.remaining') }}: {{ $quota['remaining'] }}</span>
                    </div>
                </section>
            </div>

            <section class="rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="recent-jobs">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h2 id="recent-jobs" class="text-lg font-bold text-slate-900">{{ __('ui.recent_jobs') }}</h2>
                    <p class="mt-1 text-sm text-slate-600">{{ __('ui.recent_jobs_hint') }}</p>
                </div>

                @forelse($jobs as $job)
                    @php
                        $toolKey = str_replace('-', '_', $job->tool_type->value);
                        $toolLabel = match ($toolKey) {
                            'merge_pdf' => __('tools.merge_pdf.title'),
                            'split_pdf' => __('tools.split_pdf.title'),
                            'compress_pdf' => __('tools.compress_pdf.title'),
                            'pdf_to_jpg' => __('tools.pdf_to_jpg.title'),
                            'pdf_to_png' => __('tools.pdf_to_png.title'),
                            'jpg_to_pdf' => __('tools.jpg_to_pdf.title'),
                            'heic_to_pdf' => __('tools.heic_to_pdf.title'),
                            'organize_pdf' => __('tools.organize_pdf.title'),
                            'protect_pdf' => __('tools.protect_pdf.title'),
                            'unlock_pdf' => __('tools.unlock_pdf.title'),
                            'sign_pdf' => __('tools.sign_pdf.title'),
                            'edit_pdf' => __('tools.edit_pdf.title'),
                            'compress_image' => __('tools.compress_image.title'),
                            'resize_image' => __('tools.resize_image.title'),
                            'convert_image' => __('tools.convert_image.title'),
                            'remove_background' => __('tools.remove_background.title'),
                            'change_background' => __('tools.change_background.title'),
                            default => $job->tool_type->label(),
                        };
                        $statusLabel = match ($job->status) {
                            \App\Enums\JobStatus::PENDING => __('ui.account_status_pending'),
                            \App\Enums\JobStatus::PROCESSING => __('ui.account_status_processing'),
                            \App\Enums\JobStatus::COMPLETED => __('ui.account_status_completed'),
                            \App\Enums\JobStatus::FAILED => __('ui.account_status_failed'),
                            \App\Enums\JobStatus::EXPIRED => __('ui.status_expired'),
                        };
                        $expired = $job->isExpired();
                        $availableFiles = collect($availableFileIds[$job->id] ?? []);
                        $downloadable = $job->status === \App\Enums\JobStatus::COMPLETED && ! $expired && $availableFiles->isNotEmpty();
                    @endphp
                    <article class="border-b border-slate-100 px-6 py-5 last:border-b-0">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <h3 class="truncate font-semibold text-slate-900">{{ $toolLabel }}</h3>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $job->created_at?->translatedFormat('M j, Y H:i') }}
                                    @if($job->processedFiles->isNotEmpty())
                                        <span aria-hidden="true"> · </span>{{ trans_choice('ui.file_count', $job->processedFiles->count(), ['count' => $job->processedFiles->count()]) }}
                                    @endif
                                </p>
                            </div>
                            <span class="inline-flex w-fit items-center rounded-full px-3 py-1 text-xs font-bold
                                {{ $expired ? 'bg-slate-100 text-slate-600' : ($job->status === \App\Enums\JobStatus::COMPLETED ? 'bg-emerald-100 text-emerald-700' : ($job->status === \App\Enums\JobStatus::FAILED ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700')) }}">
                                {{ $expired ? __('ui.status_expired') : $statusLabel }}
                            </span>
                        </div>

                        <div class="mt-4 flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between">
                            @if($expired)
                                <span class="text-slate-500">{{ __('ui.files_expired_account') }}</span>
                            @elseif($downloadable)
                                <span class="text-slate-500">{{ __('ui.expires_at', ['time' => $job->expires_at->translatedFormat('M j, Y H:i')]) }}</span>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($job->processedFiles as $file)
                                        @if($availableFiles->contains($file->id))
                                            <a href="{{ route('file.download', ['token' => $job->download_token, 'fileId' => $file->id]) }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700">
                                                {{ __('ui.download') }}: {{ $file->original_name }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            @elseif($job->status === \App\Enums\JobStatus::COMPLETED)
                                <span class="text-slate-500">{{ __('ui.files_unavailable_account') }}</span>
                            @elseif($job->status === \App\Enums\JobStatus::FAILED)
                                <span class="text-rose-700">{{ __('ui.processing_failed_account') }}</span>
                            @else
                                <span class="text-slate-500">{{ __('ui.processing_in_progress_account') }}</span>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="px-6 py-12 text-center">
                        <h3 class="font-bold text-slate-900">{{ __('ui.no_jobs_yet') }}</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm text-slate-600">{{ __('ui.no_jobs_yet_hint') }}</p>
                        <a href="{{ route('home') }}" class="mt-5 inline-flex rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-indigo-700">{{ __('ui.start_processing') }}</a>
                    </div>
                @endforelse
            </section>

            @if($errors->has('account'))
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert">
                    {{ $errors->first('account') }}
                </div>
            @endif

            <section class="rounded-2xl border border-rose-200 bg-rose-50/60 p-6 shadow-sm" aria-labelledby="danger-zone">
                <h2 id="danger-zone" class="text-lg font-bold text-rose-900">{{ __('ui.account_danger_zone') }}</h2>
                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-rose-800">{{ __('ui.account_delete_warning') }}</p>
                <form method="POST" action="{{ route('account.destroy') }}" class="mt-5 max-w-xl space-y-4" data-delete-account-form data-confirm-message="{{ __('ui.account_delete_browser_confirm') }}">
                    @csrf
                    @method('DELETE')
                    <label for="account-delete-confirmation" class="block text-sm font-semibold text-rose-900">
                        {{ __('ui.account_delete_confirmation', ['phrase' => 'DELETE ACCOUNT']) }}
                    </label>
                    <input id="account-delete-confirmation" name="confirmation" type="text" required autocomplete="off"
                           class="w-full rounded-lg border-rose-300 bg-white text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500"
                           aria-describedby="account-delete-help">
                    <p id="account-delete-help" class="text-xs text-rose-700">{{ __('ui.account_delete_irreversible') }}</p>
                    <button type="submit" class="rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-rose-700">
                        {{ __('ui.delete_account') }}
                    </button>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
