<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.protect_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.protect_pdf.desc') }}</p>
    </div>

    <!-- Authoritative State Stepper -->
    @include('livewire.tools.workflow-stepper', ['jobStatus' => $jobStatus, 'hasUpload' => (bool)$upload])

    <!-- Processing State -->
    @if($jobStatus === 'processing')
        <div wire:poll.1s="pollJobStatus" class="max-w-xl mx-auto rounded-3xl border border-slate-200 bg-white p-8 sm:p-10 text-center shadow-lg">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                <svg class="h-8 w-8 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900">{{ $statusMessage ?? __('ui.processing') }}</h3>
            <p class="mt-2 text-sm text-slate-500">{{ __('ui.status_processing') }}</p>
            <div class="mt-6 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full w-full bg-gradient-to-r from-indigo-500 to-violet-500 animate-pulse rounded-full"></div>
            </div>
        </div>

    <!-- Success State -->
    @elseif($jobStatus === 'completed' && $this->fileJob)
        @include('livewire.tools.job-result', ['job' => $this->fileJob, 'processedFiles' => $this->processedFiles])

    <!-- Failure State -->
    @elseif($jobStatus === 'failed')
        <div class="max-w-xl mx-auto rounded-3xl border border-rose-200 bg-rose-50/50 p-6 sm:p-8 text-center shadow-md">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 class="text-lg font-bold text-rose-900">{{ __('ui.status_failed') }}</h3>
            <p class="mt-1 text-sm text-rose-700">{{ $errorMessage }}</p>
            <button wire:click="resetState" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition cursor-pointer">
                &larr; {{ __('ui.try_another') }}
            </button>
        </div>

    <!-- Upload & Configuration State -->
    @else
        @if ($errors->any())
            <div class="max-w-2xl mx-auto mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 shadow-xs">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!$upload)
            <!-- Dedicated Upload Component -->
            <div class="max-w-2xl mx-auto">
                @include('livewire.tools.upload-progress', [
                    'propertyName' => 'upload',
                    'multiple'     => false,
                    'accept'       => 'application/pdf',
                    'maxSizeMb'    => $this->getMaxPdfSizeMb(),
                    'formatsHint'  => 'PDF',
                ])
            </div>
        @else
            <div class="max-w-2xl mx-auto space-y-6">
                <!-- File Pill -->
                <div class="flex items-center justify-between rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h4 class="truncate font-semibold text-slate-800 text-sm">{{ $upload->getClientOriginalName() }}</h4>
                                <span class="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">
                                    {{ __('ui.state_uploaded') }}
                                </span>
                            </div>
                            <p class="text-xs text-slate-400">{{ number_format($upload->getSize() / 1024, 1) }} KB</p>
                        </div>
                    </div>
                    <button wire:click="cancelToolUpload" class="text-xs font-semibold text-rose-600 hover:underline cursor-pointer">{{ __('ui.clear_all') }}</button>
                </div>

                <!-- Password Protection Card -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-6">
                    <div class="space-y-4">
                        <div>
                            <label for="doc-password" class="flex items-center gap-1 text-sm font-semibold text-slate-800 mb-1.5">
                                <span>{{ __('tools.protect_pdf.password_label') }}</span>
                                <span class="text-rose-500 font-bold" aria-hidden="true">*</span>
                            </label>
                            <input
                                id="doc-password"
                                type="password"
                                wire:model="password"
                                autocomplete="new-password"
                                placeholder="{{ __('tools.protect_pdf.password_placeholder') }}"
                                class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none transition"
                            />
                            @error('password')
                                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="doc-password-confirm" class="flex items-center gap-1 text-sm font-semibold text-slate-800 mb-1.5">
                                <span>{{ __('tools.protect_pdf.confirm_password_label') }}</span>
                                <span class="text-rose-500 font-bold" aria-hidden="true">*</span>
                            </label>
                            <input
                                id="doc-password-confirm"
                                type="password"
                                wire:model="password_confirmation"
                                autocomplete="new-password"
                                placeholder="{{ __('tools.protect_pdf.confirm_password_placeholder') }}"
                                class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none transition"
                            />
                            @error('password_confirmation')
                                <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Permissions Selection -->
                    <div class="border-t border-slate-100 pt-5 space-y-3">
                        <h4 class="text-sm font-bold text-slate-800">{{ __('tools.protect_pdf.permissions_title') }}</h4>

                        <div class="space-y-2.5">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" wire:model="allowPrinting" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700">{{ __('tools.protect_pdf.allow_printing') }}</span>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" wire:model="allowCopying" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700">{{ __('tools.protect_pdf.allow_copying') }}</span>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" wire:model="allowModifying" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700">{{ __('tools.protect_pdf.allow_modifying') }}</span>
                            </label>
                        </div>
                    </div>

                    <!-- Action Button -->
                    <button
                        wire:click="process"
                        wire:loading.attr="disabled"
                        class="w-full rounded-2xl bg-indigo-600 py-3.5 px-6 text-base font-semibold text-white shadow-lg shadow-indigo-600/20 hover:bg-indigo-700 transition disabled:opacity-50 cursor-pointer flex items-center justify-center gap-2"
                    >
                        <span wire:loading.remove wire:target="process" class="flex items-center gap-2">
                            <svg class="h-5 w-5 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            <span>{{ __('tools.protect_pdf.action') }}</span>
                        </span>
                        <span wire:loading wire:target="process" class="inline-flex items-center gap-2">
                            <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>{{ __('ui.processing') }}</span>
                        </span>
                    </button>
                </div>
            </div>
        @endif
    @endif
</div>
