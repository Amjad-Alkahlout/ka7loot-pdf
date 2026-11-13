<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.convert_image.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.convert_image.desc') }}</p>
    </div>

    @include('livewire.tools.workflow-stepper', ['jobStatus' => $jobStatus, 'hasUpload' => (bool)$upload])

    @if($jobStatus === 'processing')
        <div wire:poll.2500ms="pollJobStatus" class="max-w-xl mx-auto rounded-3xl border border-slate-200 bg-white p-8 sm:p-10 text-center shadow-lg">
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
                    'accept'       => 'image/jpeg,image/png,image/webp,image/gif,image/bmp,image/heic,image/heif,.heic,.heif',
                    'maxSizeMb'    => $this->getMaxImageSizeMb(),
                    'formatsHint'  => 'JPG, PNG, WebP, GIF, BMP, HEIC, HEIF',
                ])
            </div>
        @else
            <div class="max-w-4xl mx-auto rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                    <!-- Image Preview Card / HEIC Fallback -->
                    <div x-data="{
                             previewUrl: @js($this->getPreviewUrl()),
                             isHeic: @js($isHeic),
                             loaded: false,
                             failed: false,
                             init() {
                                 if (this.previewUrl) {
                                     this.loaded = true;
                                 }
                             }
                         }"
                         class="rounded-2xl border border-slate-200 p-4 bg-slate-50 text-center">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">{{ __('tools.convert_image.preview_original') }}</span>
                            @if($isHeic)
                                <span class="text-[11px] font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded-md border border-amber-200">{{ __('tools.convert_image.heic_badge') }}</span>
                            @elseif($imageWidth && $imageHeight)
                                <span class="text-[11px] font-mono text-slate-400 bg-white px-2 py-0.5 rounded-md border border-slate-200">{{ $imageWidth }}×{{ $imageHeight }} px</span>
                            @endif
                        </div>

                        @if($isHeic)
                            <!-- HEIC Format Card -->
                            <div class="h-64 flex flex-col items-center justify-center rounded-xl bg-amber-50/50 border border-amber-200 p-6 text-center">
                                <div class="h-16 w-16 mb-3 rounded-2xl bg-amber-100 flex items-center justify-center text-amber-700">
                                    <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-200 text-amber-900 mb-2">
                                    HEIC / HEIF
                                </span>
                                <p class="text-xs text-amber-800 max-w-xs leading-relaxed">
                                    {{ __('tools.convert_image.heic_preview_notice') }}
                                </p>
                            </div>
                        @else
                            <!-- Browser-Supported Native Image Preview -->
                            <div class="h-64 flex items-center justify-center overflow-hidden rounded-xl bg-white border border-slate-200 relative"
                                 style="background-image: repeating-conic-gradient(#f1f5f9 0% 25%, #ffffff 0% 50%) 50% / 16px 16px;">
                                <template x-if="previewUrl">
                                    <img :src="previewUrl"
                                         alt="{{ $filename ?: ($upload ? $upload->getClientOriginalName() : 'Preview') }}"
                                         class="max-h-full max-w-full object-contain relative z-10 transition-transform duration-200 hover:scale-105"
                                         x-on:load="loaded = true"
                                         x-on:error="failed = true">
                                </template>
                                <template x-if="!previewUrl || failed">
                                    <div class="flex flex-col items-center justify-center text-slate-400 p-4">
                                        <svg class="h-8 w-8 mb-1.5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <span class="text-xs font-medium">{{ __('ui.file_uploaded') }}</span>
                                    </div>
                                </template>
                            </div>
                        @endif

                        <div class="mt-3 flex justify-between items-center text-xs text-slate-500">
                            <div class="min-w-0 text-left">
                                <p class="truncate max-w-[200px] font-medium text-slate-700" title="{{ $filename ?: ($upload ? $upload->getClientOriginalName() : '') }}">{{ $filename ?: ($upload ? $upload->getClientOriginalName() : '') }}</p>
                                <p class="text-[11px] text-slate-400">{{ $fileSizeFormatted ?: ($upload ? number_format($upload->getSize() / 1024, 1) . ' KB' : '') }}</p>
                            </div>
                            <button wire:click="cancelToolUpload" type="button" class="text-rose-600 hover:text-rose-700 font-semibold cursor-pointer">
                                {{ __('ui.cancel') }}
                            </button>
                        </div>
                    </div>

                    <!-- Target Format & Settings -->
                    <div class="space-y-5">
                        <!-- Engine Badge -->
                        <div class="rounded-2xl border border-indigo-200 bg-indigo-50/70 p-3 text-xs text-indigo-950">
                            <div class="flex items-start gap-2.5">
                                <span class="text-base">🖼️</span>
                                <div>
                                    <span class="font-bold">{{ __('ui.engine_used') }}:</span>
                                    <span class="font-semibold">{{ __('tools.convert_image.engine') }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Target Format Options -->
                        <div class="rounded-2xl border border-slate-200/80 bg-slate-50/50 p-4 space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-800 uppercase tracking-wider mb-1">{{ __('tools.convert_image.target_format') }}</label>
                                <p class="text-xs text-slate-500 mb-3">{{ __('tools.convert_image.heic_note') }}</p>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                                    @foreach(['jpg' => 'JPG', 'png' => 'PNG', 'webp' => 'WebP', 'bmp' => 'BMP', 'gif' => 'GIF'] as $fmt => $fmtLabel)
                                        <label class="flex items-center justify-center p-3 rounded-xl border-2 cursor-pointer text-sm font-bold transition {{ $targetFormat === $fmt ? 'border-indigo-600 bg-white text-indigo-900 shadow-2xs' : 'border-slate-200 bg-white/60 text-slate-700 hover:border-slate-300' }}">
                                            <input type="radio" wire:model.live="targetFormat" value="{{ $fmt }}" class="sr-only">
                                            <span>{{ $fmtLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button wire:click="process"
                                wire:loading.attr="disabled"
                                type="button"
                                class="w-full inline-flex justify-center items-center gap-2 rounded-2xl bg-indigo-600 px-6 py-3.5 text-base font-bold text-white shadow-md hover:bg-indigo-700 transition cursor-pointer disabled:opacity-50">
                            <span wire:loading.remove wire:target="process">{{ __('tools.convert_image.action') }}</span>
                            <span wire:loading wire:target="process" class="flex items-center gap-2">
                                <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>{{ __('ui.processing') }}</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
