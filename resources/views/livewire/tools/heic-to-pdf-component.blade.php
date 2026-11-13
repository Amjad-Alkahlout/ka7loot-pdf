<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto"
     x-data="fileUploadHandler({
         propertyName: 'newFiles',
         multiple: true,
         queueBatches: true,
         accept: '.heic,.heif,image/heic,image/heif',
         fileType: 'image',
         maxSizeMb: {{ $this->getMaxImageSizeMb() }},
         maxSizeBytes: {{ $this->getMaxImageSizeMb() * 1024 * 1024 }},
         maxBatchFiles: {{ (int) config('fileprocessor.limits.max_batch_files', 50) }},
         maxTotalBatchBytes: {{ (int) config('fileprocessor.limits.max_total_batch_kb', 102400) * 1024 }},
         maxTotalBatchMb: {{ (int) round(config('fileprocessor.limits.max_total_batch_kb', 102400) / 1024) }},
         locale: '{{ app()->getLocale() }}',
         messages: {
             sizeExceeded: @js(__('ui.error_image_preupload_too_large', ['size' => $this->getMaxImageSizeMb()])),
             emptyFile: @js(__('ui.error_file_empty')),
             unsupportedFormat: @js(__('ui.error_unsupported_format')),
             tooManyFiles: @js(__('ui.error_too_many_files', ['max' => config('fileprocessor.limits.max_batch_files', 50)])),
             batchTooLarge: @js(__('ui.error_batch_too_large', ['max' => round(config('fileprocessor.limits.max_total_batch_kb', 102400) / 1024)])),
             uploadTimedOut: @js(__('ui.upload_timed_out')),
             uploadFailed: @js(__('ui.upload_failed')),
         }
     })"
     x-on:livewire-upload-start="onUploadStart($event)"
     x-on:livewire-upload-progress="onUploadProgress($event)"
     x-on:livewire-upload-finish="onUploadFinish($event)"
     x-on:livewire-upload-error="onUploadError($event)">
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.heic_to_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.heic_to_pdf.desc') }}</p>
    </div>

    @include('livewire.tools.workflow-stepper', ['jobStatus' => $jobStatus, 'hasUpload' => !empty($uploads)])

    @if($jobStatus === 'processing')
        <div wire:poll.2500ms="pollJobStatus" class="max-w-xl mx-auto rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-lg">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                <svg class="h-8 w-8 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/></svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900">{{ $statusMessage ?? __('ui.processing') }}</h3>
            <div class="mt-6 h-2 w-full overflow-hidden rounded-full bg-slate-100"><div class="h-full w-full bg-gradient-to-r from-indigo-500 to-violet-500 animate-pulse rounded-full"></div></div>
        </div>
    @elseif($jobStatus === 'completed' && $this->fileJob)
        @include('livewire.tools.job-result', ['job' => $this->fileJob, 'processedFiles' => $this->processedFiles])
    @elseif($jobStatus === 'failed')
        <div class="max-w-xl mx-auto rounded-3xl border border-rose-200 bg-rose-50 p-8 text-center shadow-sm">
            <h3 class="text-lg font-bold text-rose-900">{{ __('ui.status_failed') }}</h3>
            <p class="mt-1 text-sm text-rose-700">{{ $errorMessage }}</p>
            <button wire:click="resetWorkflow" class="mt-4 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition cursor-pointer">{{ __('ui.try_another') }}</button>
        </div>
    @else
        @if($errors->any())
            <div class="max-w-3xl mx-auto mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 shadow-xs">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="max-w-3xl mx-auto space-y-6">
            @php
                $dailyQuota = app(\App\Services\File\DailyFileQuotaService::class)->summary();
                $previewUrls = $this->previewUrls;
            @endphp

            @if(empty($uploads))
                <!-- 1. EMPTY STATE: Large Initial Dropzone -->
                <div wire:key="heic-upload-empty">
                    @include('livewire.tools.upload-progress', [
                        'propertyName' => 'newFiles',
                        'multiple' => true,
                        'queueBatches' => true,
                        'accept' => '.heic,.heif,image/heic,image/heif',
                        'maxSizeMb' => $this->getMaxImageSizeMb(),
                        'formatsHint' => 'HEIC, HEIF',
                        'compact' => false,
                        'hideQuota' => false,
                        'standalone' => false,
                    ])
                </div>
            @else
                <!-- 2. POPULATED STATE: Quota Notice & Warnings -->
                <div class="rounded-2xl border border-indigo-100 bg-indigo-50/70 px-4 py-3 text-sm text-indigo-950">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4 text-indigo-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <span>{{ __('ui.daily_quota_remaining', ['remaining' => $dailyQuota['remaining'], 'limit' => $dailyQuota['limit']]) }}</span>
                        </div>
                        @if($dailyQuota['guest'])
                            <button type="button" data-clerk-sign-in class="font-bold text-indigo-700 hover:underline cursor-pointer">{{ __('ui.sign_in_for_more') }}</button>
                        @endif
                    </div>
                </div>

                @if(count($uploads) > $dailyQuota['remaining'])
                    <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-xs sm:text-sm text-amber-900 shadow-xs flex items-start gap-3">
                        <svg class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <div>
                            <span class="font-bold block text-amber-950">{{ __('ui.quota_exceeded_warning_title') }}</span>
                            <p class="mt-0.5 text-amber-800">{{ __('ui.quota_exceeded_warning_desc', ['count' => count($uploads), 'remaining' => $dailyQuota['remaining']]) }}</p>
                        </div>
                    </div>
                @endif

                <!-- Files Header Row -->
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h3 class="font-bold text-slate-900 text-base sm:text-lg">
                            {{ __('ui.files_selected', ['count' => count($uploads)]) }}
                        </h3>
                        <span class="text-slate-300 hidden sm:inline" aria-hidden="true">&bull;</span>
                        <span class="text-xs sm:text-sm font-semibold text-slate-600 bg-slate-100 rounded-lg px-2.5 py-1">
                            {{ $this->totalSizeFormatted }}
                        </span>
                    </div>
                    <button wire:click="cancelToolUpload"
                            class="text-xs font-semibold text-rose-600 hover:text-rose-700 hover:underline transition cursor-pointer">
                        {{ __('ui.clear_all') }}
                    </button>
                </div>

                <p class="text-xs text-slate-500 -mt-3">
                    {{ __('tools.heic_to_pdf.reorder_hint') }}
                </p>

                <!-- Reorderable Files Gallery Grid with High-Performance Previews & Drag-and-Drop -->
                <div x-data="{
                         onReorder(items) {
                             $wire.reorderFiles(items);
                         }
                     }"
                     x-sortable="onReorder"
                     data-sortable-handle=".drag-handle"
                     class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    @foreach($uploads as $idx => $file)
                        @php
                            $previewUrl = $previewUrls[$idx] ?? null;
                            $fileSizeBytes = is_object($file) && method_exists($file, 'getSize') ? $file->getSize() : 0;
                            $formattedFileSize = number_format($fileSizeBytes / 1024, 1) . ' KB';
                            $originalName = is_object($file) && method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : 'image.heic';
                            $fileKey = is_object($file) && method_exists($file, 'getFilename') ? $file->getFilename() : (string) $idx;
                        @endphp
                        <div wire:key="heic-file-item-{{ $idx }}-{{ $fileKey }}"
                             data-id="{{ $fileKey }}"
                             class="group relative flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-3 shadow-xs hover:border-indigo-300 hover:shadow-md transition">
                            
                            <!-- Card Header: Index Badge, Drag Handle, Remove Button -->
                            <div class="flex items-center justify-between gap-1 pb-2">
                                <div class="flex items-center gap-1.5">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-indigo-50 text-xs font-black text-indigo-700">
                                        {{ $idx + 1 }}
                                    </span>
                                    <button type="button"
                                            class="drag-handle text-slate-400 hover:text-slate-700 p-1 cursor-grab active:cursor-grabbing rounded-md hover:bg-slate-100 transition"
                                            title="{{ __('ui.reorder') }}"
                                            aria-label="{{ __('ui.reorder') }}">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" />
                                        </svg>
                                    </button>
                                </div>

                                <button type="button"
                                        wire:click="removeFile({{ $idx }})"
                                        aria-label="{{ __('ui.remove') }}"
                                        title="{{ __('ui.remove') }}"
                                        class="text-slate-400 hover:text-rose-600 p-1 rounded-md hover:bg-rose-50 transition cursor-pointer">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Thumbnail (Sequential non-blocking loading & neutral fallback, object-contain for aspect preservation) -->
                            <div x-data="{ loaded: false, failed: false }"
                                 x-init="enqueueThumbnail(@js($previewUrl), () => { loaded = true; }, () => { failed = true; })"
                                 class="relative w-full aspect-[4/3] rounded-xl overflow-hidden bg-slate-100 border border-slate-200/60 flex items-center justify-center p-1.5">
                                <button type="button"
                                        x-on:click="@js((bool)$previewUrl) ? openPreview(@js($previewUrl), @js($originalName), @js($formattedFileSize)) : null"
                                        class="relative h-full w-full overflow-hidden rounded-lg group focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 cursor-pointer block"
                                        title="{{ __('ui.click_to_preview') }}">
                                    
                                    <!-- Placeholder Skeleton while loading -->
                                    <div x-show="!loaded && !failed"
                                         class="absolute inset-0 flex items-center justify-center bg-slate-200 animate-pulse text-slate-400">
                                        <svg class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    </div>

                                    @if($previewUrl)
                                        <!-- Real Image Preview (object-contain preserves aspect ratio, never crops) -->
                                        <template x-if="loaded && !failed">
                                            <img src="{{ $previewUrl }}"
                                                 alt="{{ $originalName }}"
                                                 class="h-full w-full object-contain transition-transform duration-200 group-hover:scale-105" />
                                        </template>
                                    @endif

                                    <!-- Fallback Neutral File Icon if thumbnail fails (Never breaks the list) -->
                                    <div x-show="failed || !@js((bool)$previewUrl)"
                                         class="absolute inset-0 flex flex-col items-center justify-center bg-slate-100 text-slate-400 p-1">
                                        <svg class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 mt-0.5">HEIC</span>
                                    </div>

                                    <!-- Zoom Icon Overlay on hover -->
                                    <div x-show="loaded && !failed"
                                         class="absolute inset-0 bg-slate-900/30 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-white">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                                        </svg>
                                    </div>
                                </button>
                            </div>

                            <!-- Card Footer: File Information & Accessible Move Controls -->
                            <div class="pt-2.5 space-y-1.5">
                                <div class="min-w-0">
                                    <h4 class="truncate font-semibold text-slate-800 text-xs sm:text-sm" title="{{ $originalName }}">
                                        {{ $originalName }}
                                    </h4>
                                    <div class="flex items-center gap-2 text-xs text-slate-400 mt-0.5">
                                        <span>{{ $formattedFileSize }}</span>
                                        <span>&bull;</span>
                                        <span class="uppercase">HEIC</span>
                                    </div>
                                </div>

                                <!-- Accessible Reorder Controls -->
                                <div class="flex items-center justify-end gap-1 pt-1 border-t border-slate-100">
                                    <button type="button"
                                            wire:click="moveUp({{ $idx }})"
                                            {{ $idx === 0 ? 'disabled' : '' }}
                                            aria-label="{{ __('ui.move_up') }}"
                                            title="{{ __('ui.move_up') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-indigo-600 disabled:opacity-25 disabled:pointer-events-none transition cursor-pointer shadow-2xs">
                                        <svg class="h-3.5 w-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                        </svg>
                                    </button>

                                    <button type="button"
                                            wire:click="moveDown({{ $idx }})"
                                            {{ $idx === count($uploads) - 1 ? 'disabled' : '' }}
                                            aria-label="{{ __('ui.move_down') }}"
                                            title="{{ __('ui.move_down') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-indigo-600 disabled:opacity-25 disabled:pointer-events-none transition cursor-pointer shadow-2xs">
                                        <svg class="h-3.5 w-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- 3. COMPACT ADD MORE BAR (Below File List) -->
                <div wire:key="heic-upload-compact">
                    @include('livewire.tools.upload-progress', [
                        'propertyName' => 'newFiles',
                        'multiple' => true,
                        'queueBatches' => true,
                        'accept' => '.heic,.heif,image/heic,image/heif',
                        'maxSizeMb' => $this->getMaxImageSizeMb(),
                        'formatsHint' => 'HEIC, HEIF',
                        'compact' => true,
                        'hideQuota' => true,
                        'standalone' => false,
                    ])
                </div>

                <!-- PDF Options Card -->
                <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-4 shadow-xs">
                    <h3 class="text-sm font-bold text-slate-900">{{ __('ui.options') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <label class="text-xs font-semibold text-slate-700">
                            {{ __('tools.heic_to_pdf.page_size') }}
                            <select wire:model="pageSize" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="a4">A4</option>
                                <option value="letter">Letter</option>
                                <option value="legal">Legal</option>
                                <option value="fit">{{ __('tools.heic_to_pdf.fit_image') }}</option>
                            </select>
                        </label>
                        <label class="text-xs font-semibold text-slate-700">
                            {{ __('tools.heic_to_pdf.orientation') }}
                            <select wire:model="orientation" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="auto">Auto</option>
                                <option value="portrait">Portrait</option>
                                <option value="landscape">Landscape</option>
                            </select>
                        </label>
                        <label class="text-xs font-semibold text-slate-700">
                            {{ __('tools.heic_to_pdf.margin') }}
                            <select wire:model="margin" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="0">{{ __('tools.heic_to_pdf.margin_none') }}</option>
                                <option value="10">{{ __('tools.heic_to_pdf.margin_small') }}</option>
                                <option value="20">{{ __('tools.heic_to_pdf.margin_big') }}</option>
                            </select>
                        </label>
                    </div>
                </div>

                <!-- Convert Action Button -->
                <button wire:click="process"
                        wire:loading.attr="disabled"
                        class="w-full rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white hover:bg-indigo-700 active:scale-[0.99] disabled:opacity-50 transition cursor-pointer shadow-md shadow-indigo-500/10">
                    <span wire:loading.remove wire:target="process">{{ __('tools.heic_to_pdf.action') }}</span>
                    <span wire:loading wire:target="process" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                        <span>{{ __('ui.processing') }}</span>
                    </span>
                </button>
            @endif
        </div>

        <!-- Larger Image Preview Lightbox / Modal -->
        <div x-show="activePreview !== null"
             x-cloak
             role="dialog"
             aria-modal="true"
             aria-labelledby="heic-preview-modal-title"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/80 backdrop-blur-xs"
             @keydown.escape.window="closePreview()"
             @click.self="closePreview()">
            
            <div class="relative w-full max-w-3xl rounded-3xl bg-white shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 bg-slate-50/70">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600 shrink-0">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </span>
                        <h4 id="heic-preview-modal-title" class="truncate font-bold text-slate-900 text-sm sm:text-base" x-text="activeFileName"></h4>
                        <span class="text-xs font-semibold text-slate-500 bg-slate-200/80 rounded-md px-2 py-0.5 shrink-0" x-text="activeFileSize"></span>
                    </div>
                    <button type="button"
                            @click="closePreview()"
                            aria-label="{{ __('ui.close') }}"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-xl text-slate-400 hover:bg-slate-200 hover:text-slate-700 transition cursor-pointer">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body with Image -->
                <div class="p-4 sm:p-6 overflow-y-auto flex items-center justify-center bg-slate-950/5 min-h-[250px]">
                    <img :src="activePreview"
                         :alt="activeFileName"
                         class="max-h-[70vh] w-auto max-w-full rounded-2xl object-contain shadow-md" />
                </div>
            </div>
        </div>
    @endif
</div>
