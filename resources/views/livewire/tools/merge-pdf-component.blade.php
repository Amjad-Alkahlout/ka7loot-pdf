<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.merge_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.merge_pdf.desc') }}</p>
    </div>

    <!-- Processing State (Authoritative FileJob Status Polling) -->
    @if($jobStatus === 'processing')
        <div wire:poll.1s="pollJobStatus" class="max-w-xl mx-auto rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-lg">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                <svg class="h-8 w-8 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900">{{ $statusMessage ?? __('ui.processing') }}</h3>
            <p class="mt-2 text-sm text-slate-500">{{ __('ui.status_processing') }}</p>
            <!-- Indeterminate Progress Indicator -->
            <div class="mt-6 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full w-full bg-gradient-to-r from-indigo-500 to-violet-500 animate-pulse rounded-full"></div>
            </div>
        </div>

    <!-- Success State -->
    @elseif($jobStatus === 'completed' && $this->fileJob)
        @include('livewire.tools.job-result', ['job' => $this->fileJob, 'processedFiles' => $this->processedFiles])

    <!-- Failure State -->
    @elseif($jobStatus === 'failed')
        <div class="max-w-xl mx-auto rounded-2xl border border-rose-200 bg-rose-50/50 p-6 text-center shadow-md">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 class="text-lg font-bold text-rose-900">{{ __('ui.status_failed') }}</h3>
            <p class="mt-1 text-sm text-rose-700">{{ $errorMessage }}</p>
            <button wire:click="resetState" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition">
                &larr; {{ __('ui.try_another') }}
            </button>
        </div>

    <!-- Upload & Options State -->
    @else
        <!-- Validation Errors Callout -->
        @if ($errors->any())
            <div class="max-w-2xl mx-auto mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(empty($uploads))
            <!-- Empty State / Drag and Drop Area -->
            <div class="max-w-2xl mx-auto">
                <label class="group relative flex flex-col items-center justify-center rounded-3xl border-2 border-dashed border-slate-300 bg-white p-12 text-center shadow-xs transition hover:border-indigo-400 hover:bg-indigo-50/20 cursor-pointer">
                    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 group-hover:scale-110 transition">
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                    </div>
                    <span class="text-lg font-semibold text-slate-900">{{ __('ui.select_files') }}</span>
                    <span class="mt-1 text-sm text-slate-500">{{ __('ui.drop_files_here') }}</span>
                    <span class="mt-3 inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        {{ __('ui.max_size_hint', ['size' => 50]) }}
                    </span>
                    <input type="file" wire:model="uploads" multiple accept="application/pdf" class="sr-only">
                </label>
            </div>
        @else
            <!-- Ready State (Files Selected) -->
            <div class="max-w-2xl mx-auto space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-slate-700">{{ __('ui.files_selected', ['count' => count($uploads)]) }}</span>
                    <button wire:click="resetState" class="text-xs font-medium text-rose-600 hover:underline">{{ __('ui.clear_all') }}</button>
                </div>

                <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
                    @foreach($uploads as $idx => $file)
                        <div class="flex items-center justify-between p-3.5 hover:bg-slate-50/70 transition">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-xs font-bold text-indigo-600">{{ $idx + 1 }}</span>
                                <span class="truncate text-sm font-medium text-slate-800">{{ $file->getClientOriginalName() }}</span>
                            </div>
                            <button wire:click="removeUpload({{ $idx }})" type="button" class="text-slate-400 hover:text-rose-600 transition p-1">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    @endforeach
                </div>

                <!-- Add More Files -->
                <div class="text-center pt-2">
                    <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-800 cursor-pointer">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>{{ __('tools.merge_pdf.add_more') }}</span>
                        <input type="file" wire:model="uploads" multiple accept="application/pdf" class="sr-only">
                    </label>
                </div>

                <!-- Submit Action -->
                <div class="pt-4">
                    <button wire:click="process"
                            wire:loading.attr="disabled"
                            type="button"
                            class="w-full flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-700 transition active:scale-[0.99] disabled:opacity-50">
                        <span wire:loading.remove wire:target="process">{{ __('tools.merge_pdf.action') }}</span>
                        <span wire:loading wire:target="process" class="flex items-center gap-2">
                            <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span>{{ __('ui.uploading') }}</span>
                        </span>
                    </button>
                </div>
            </div>
        @endif
    @endif
</div>
