<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.compress_image.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.compress_image.desc') }}</p>
    </div>

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

    @elseif($jobStatus === 'completed' && $this->fileJob)
        @include('livewire.tools.job-result', ['job' => $this->fileJob, 'processedFiles' => $this->processedFiles])

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
            <div class="max-w-2xl mx-auto">
                <label class="group relative flex flex-col items-center justify-center rounded-3xl border-2 border-dashed border-slate-300 bg-white p-12 text-center shadow-xs transition hover:border-indigo-400 hover:bg-indigo-50/20 cursor-pointer">
                    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 group-hover:scale-110 transition">
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <span class="text-lg font-semibold text-slate-900">{{ __('ui.select_files') }}</span>
                    <span class="mt-1 text-sm text-slate-500">{{ __('ui.drop_files_here') }}</span>
                    <span class="mt-3 inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        JPG, PNG, WebP &bull; {{ __('ui.max_size_hint', ['size' => 25]) }}
                    </span>
                    <input type="file" wire:model="upload" accept="image/jpeg,image/png,image/webp" class="sr-only">
                </label>
            </div>
        @else
            <div class="max-w-2xl mx-auto space-y-6">
                <!-- File Pill -->
                <div class="flex items-center justify-between rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h4 class="truncate font-semibold text-slate-800 text-sm">{{ $upload->getClientOriginalName() }}</h4>
                            <p class="text-xs text-slate-400">{{ number_format($upload->getSize() / 1024, 1) }} KB</p>
                        </div>
                    </div>
                    <button wire:click="resetState" class="text-xs font-semibold text-rose-600 hover:underline">{{ __('ui.clear_all') }}</button>
                </div>

                <!-- Compression Level Cards -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-3">
                    <label class="block text-sm font-bold text-slate-900">{{ __('tools.compress_image.quality') }}</label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <label class="relative flex flex-col p-4 rounded-xl border-2 cursor-pointer transition {{ $level === 'low' ? 'border-indigo-600 bg-indigo-50/30' : 'border-slate-200 hover:border-slate-300' }}">
                            <input type="radio" wire:model.live="level" value="low" class="sr-only">
                            <span class="font-bold text-sm text-slate-900">{{ __('tools.compress_pdf.level_low') }}</span>
                            <span class="mt-1 text-xs text-slate-500">Quality ~85%</span>
                        </label>
                        <label class="relative flex flex-col p-4 rounded-xl border-2 cursor-pointer transition {{ $level === 'recommended' ? 'border-indigo-600 bg-indigo-50/30' : 'border-slate-200 hover:border-slate-300' }}">
                            <input type="radio" wire:model.live="level" value="recommended" class="sr-only">
                            <span class="font-bold text-sm text-slate-900">{{ __('tools.compress_pdf.level_recommended') }}</span>
                            <span class="mt-1 text-xs text-slate-500">Quality ~70%</span>
                        </label>
                        <label class="relative flex flex-col p-4 rounded-xl border-2 cursor-pointer transition {{ $level === 'extreme' ? 'border-indigo-600 bg-indigo-50/30' : 'border-slate-200 hover:border-slate-300' }}">
                            <input type="radio" wire:model.live="level" value="extreme" class="sr-only">
                            <span class="font-bold text-sm text-slate-900">{{ __('tools.compress_pdf.level_extreme') }}</span>
                            <span class="mt-1 text-xs text-slate-500">Quality ~50%</span>
                        </label>
                    </div>
                </div>

                <!-- Submit Button -->
                <button wire:click="process"
                        wire:loading.attr="disabled"
                        type="button"
                        class="w-full flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-700 transition active:scale-[0.99] disabled:opacity-50">
                    <span wire:loading.remove wire:target="process">{{ __('tools.compress_image.action') }}</span>
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
