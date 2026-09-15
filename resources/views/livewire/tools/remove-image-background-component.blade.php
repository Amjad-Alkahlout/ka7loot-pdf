<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.remove_background.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.remove_background.desc') }}</p>
    </div>

    @include('livewire.tools.workflow-stepper', ['jobStatus' => $jobStatus, 'hasUpload' => (bool)$upload])

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

    @elseif($jobStatus === 'completed' && $this->fileJob)
        <div class="space-y-8">
            <div class="max-w-4xl mx-auto rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900 mb-4">{{ __('tools.remove_background.result_ready') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @if($originalPreviewUri)
                        <div class="rounded-2xl border border-slate-200 p-3 bg-slate-50">
                            <span class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2 text-center">{{ __('tools.remove_background.preview_original') }}</span>
                            <div class="h-64 flex items-center justify-center overflow-hidden rounded-xl bg-slate-100">
                                <img src="{{ $originalPreviewUri }}" alt="Original Image" class="max-h-full max-w-full object-contain">
                            </div>
                        </div>
                    @endif

                    @if(!empty($this->processedFiles) && count($this->processedFiles) > 0)
                        <div class="rounded-2xl border border-emerald-200 p-3 bg-emerald-50/30">
                            <span class="block text-xs font-semibold text-emerald-700 uppercase tracking-wider mb-2 text-center">{{ __('tools.remove_background.preview_result') }}</span>
                            <div class="h-64 flex items-center justify-center overflow-hidden rounded-xl border border-slate-200" style="background-image: repeating-conic-gradient(#e2e8f0 0% 25%, #ffffff 0% 50%) 50% / 20px 20px;">
                                <img src="{{ route('file.download', ['token' => $this->fileJob->download_token, 'fileId' => $this->processedFiles[0]->id]) }}" alt="Transparent Result" class="max-h-full max-w-full object-contain">
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @include('livewire.tools.job-result', ['job' => $this->fileJob, 'processedFiles' => $this->processedFiles])
        </div>

    @elseif($jobStatus === 'failed')
        <div class="max-w-xl mx-auto rounded-3xl border border-rose-200 bg-rose-50/50 p-6 sm:p-8 text-center shadow-md">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 class="text-lg font-bold text-rose-900">{{ __('ui.status_failed') }}</h3>
            <p class="mt-1 text-sm text-rose-700">{{ $errorMessage }}</p>
            <button wire:click="resetWorkflow" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition cursor-pointer">
                &larr; {{ __('ui.try_another') }}
            </button>
        </div>

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
            <div class="max-w-2xl mx-auto">
                @include('livewire.tools.upload-progress', [
                    'propertyName' => 'upload',
                    'multiple'     => false,
                    'accept'       => 'image/jpeg,image/png,image/webp',
                    'maxSizeMb'    => $this->getMaxImageSizeMb(),
                    'formatsHint'  => 'JPG, PNG, WebP',
                ])
            </div>
        @else
            <div class="max-w-3xl mx-auto rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <!-- Engine Notice Badge -->
                <div class="mb-6 rounded-2xl bg-indigo-50/70 border border-indigo-100 p-4 flex items-start gap-3">
                    <svg class="h-5 w-5 text-indigo-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <h4 class="text-sm font-semibold text-indigo-900">{{ __('tools.remove_background.engine_notice') }}</h4>
                        <p class="text-xs text-indigo-700 mt-0.5">{{ __('tools.remove_background.engine_notice_desc') }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                    <!-- Image Preview Card -->
                    <div class="rounded-2xl border border-slate-200 p-4 bg-slate-50 text-center">
                        <span class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">{{ __('tools.remove_background.preview_original') }}</span>
                        <div class="h-64 flex items-center justify-center overflow-hidden rounded-xl bg-white border border-slate-200">
                            @if($originalPreviewUri)
                                <img src="{{ $originalPreviewUri }}" alt="Preview" class="max-h-full max-w-full object-contain">
                            @else
                                <span class="text-sm text-slate-400">{{ __('ui.file_uploaded') }}</span>
                            @endif
                        </div>
                        <div class="mt-3 flex justify-between items-center text-xs text-slate-500">
                            <span class="truncate max-w-[200px]">{{ $upload->getClientOriginalName() }}</span>
                            <button wire:click="resetWorkflow" class="text-rose-600 hover:text-rose-700 font-medium cursor-pointer">
                                {{ __('ui.cancel') }}
                            </button>
                        </div>
                    </div>

                    <!-- Tuning Controls -->
                    <div class="space-y-5">
                        <!-- Tolerance Slider -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label for="tolerance" class="text-sm font-semibold text-slate-800">{{ __('tools.remove_background.tolerance') }}</label>
                                <span class="text-xs font-mono font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-md">{{ (int)$tolerance }}%</span>
                            </div>
                            <input type="range" id="tolerance" wire:model.live="tolerance" min="5" max="80" step="1"
                                class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                            <p class="text-xs text-slate-500 mt-1">{{ __('tools.remove_background.tolerance_hint') }}</p>
                        </div>

                        <!-- Feather Slider -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label for="feather" class="text-sm font-semibold text-slate-800">{{ __('tools.remove_background.feather') }}</label>
                                <span class="text-xs font-mono font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-md">{{ $feather }} px</span>
                            </div>
                            <input type="range" id="feather" wire:model.live="feather" min="0" max="5" step="1"
                                class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                            <p class="text-xs text-slate-500 mt-1">{{ __('tools.remove_background.feather_hint') }}</p>
                        </div>

                        <!-- Connected Flood Fill Toggle -->
                        <div class="rounded-xl border border-slate-200 p-3 bg-slate-50">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" wire:model.live="floodFill" class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                <div>
                                    <span class="text-sm font-medium text-slate-900 block">{{ __('tools.remove_background.flood_fill') }}</span>
                                    <span class="text-xs text-slate-500 block mt-0.5">{{ __('tools.remove_background.flood_fill_hint') }}</span>
                                </div>
                            </label>
                        </div>

                        <!-- Action Button -->
                        <button wire:click="process" wire:loading.attr="disabled"
                            class="w-full inline-flex justify-center items-center gap-2 rounded-2xl bg-indigo-600 px-6 py-3.5 text-base font-bold text-white shadow-md hover:bg-indigo-700 transition cursor-pointer disabled:opacity-50">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                            <span>{{ __('tools.remove_background.action') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
