<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.compress_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.compress_pdf.desc') }}</p>
    </div>

    <!-- Processing State -->
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
        @if ($errors->any())
            <div class="max-w-2xl mx-auto mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!$upload)
            <!-- Empty State -->
            <div class="max-w-2xl mx-auto">
                <label class="group relative flex flex-col items-center justify-center rounded-3xl border-2 border-dashed border-slate-300 bg-white p-12 text-center shadow-xs transition hover:border-indigo-400 hover:bg-indigo-50/20 cursor-pointer">
                    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 group-hover:scale-110 transition">
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                        </svg>
                    </div>
                    <span class="text-lg font-semibold text-slate-900">{{ __('ui.select_files') }}</span>
                    <span class="mt-1 text-sm text-slate-500">{{ __('ui.drop_files_here') }}</span>
                    <span class="mt-3 inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        {{ __('ui.max_size_hint', ['size' => 100]) }}
                    </span>
                    <input type="file" wire:model="upload" accept="application/pdf" class="sr-only">
                </label>
            </div>
        @else
            <!-- Ready State (Options Selection) -->
            <div class="max-w-2xl mx-auto space-y-6">
                <!-- File Pill -->
                <div class="flex items-center justify-between rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h4 class="truncate font-semibold text-slate-800 text-sm">{{ $upload->getClientOriginalName() }}</h4>
                            <p class="text-xs text-slate-400">{{ number_format($upload->getSize() / 1024, 1) }} KB</p>
                        </div>
                    </div>
                    <button wire:click="resetState" class="text-xs font-semibold text-rose-600 hover:underline">{{ __('ui.clear_all') }}</button>
                </div>

                <!-- Engine Status Banner -->
                <div class="rounded-2xl border p-4 text-xs {{ $hasGhostscript ? 'border-emerald-200 bg-emerald-50/70 text-emerald-900' : 'border-amber-200 bg-amber-50/70 text-amber-900' }}">
                    <div class="flex items-start gap-3">
                        <span class="text-base">{{ $hasGhostscript ? '🚀' : 'ℹ️' }}</span>
                        <div>
                            <span class="font-bold">{{ __('ui.engine_used') }}:</span>
                            <span class="font-semibold">{{ $hasGhostscript ? __('ui.deep_compression') : __('ui.limited_optimization') }}</span>
                            @if(!$hasGhostscript)
                                <p class="mt-1 text-amber-800 leading-relaxed">{{ __('ui.limited_opt_notice') }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Compression Level Cards -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-3">
                    <label class="block text-sm font-bold text-slate-900">{{ __('tools.compress_pdf.level') }}</label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <label class="relative flex flex-col p-4 rounded-xl border-2 cursor-pointer transition {{ $level === 'low' ? 'border-indigo-600 bg-indigo-50/30' : 'border-slate-200 hover:border-slate-300' }}">
                            <input type="radio" wire:model.live="level" value="low" class="sr-only">
                            <span class="font-bold text-sm text-slate-900">{{ __('tools.compress_pdf.level_low') }}</span>
                            <span class="mt-1 text-xs text-slate-500">High quality resolution</span>
                        </label>
                        <label class="relative flex flex-col p-4 rounded-xl border-2 cursor-pointer transition {{ $level === 'recommended' ? 'border-indigo-600 bg-indigo-50/30' : 'border-slate-200 hover:border-slate-300' }}">
                            <input type="radio" wire:model.live="level" value="recommended" class="sr-only">
                            <span class="font-bold text-sm text-slate-900">{{ __('tools.compress_pdf.level_recommended') }}</span>
                            <span class="mt-1 text-xs text-slate-500">Balanced size & quality</span>
                        </label>
                        <label class="relative flex flex-col p-4 rounded-xl border-2 cursor-pointer transition {{ $level === 'extreme' ? 'border-indigo-600 bg-indigo-50/30' : 'border-slate-200 hover:border-slate-300' }}">
                            <input type="radio" wire:model.live="level" value="extreme" class="sr-only">
                            <span class="font-bold text-sm text-slate-900">{{ __('tools.compress_pdf.level_extreme') }}</span>
                            <span class="mt-1 text-xs text-slate-500">Maximum compression</span>
                        </label>
                    </div>
                </div>

                <!-- Submit Button -->
                <button wire:click="process"
                        wire:loading.attr="disabled"
                        type="button"
                        class="w-full flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-700 transition active:scale-[0.99] disabled:opacity-50">
                    <span wire:loading.remove wire:target="process">{{ __('tools.compress_pdf.action') }}</span>
                    <span wire:loading wire:target="process" class="flex items-center gap-2">
                        <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ __('ui.uploading') }}</span>
                    </span>
                </button>
            </div>
        @endif
    @endif
</div>
