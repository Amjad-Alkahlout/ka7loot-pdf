@props([
    'propertyName' => 'upload',
    'multiple' => false,
    'accept' => 'application/pdf',
    'maxSizeMb' => null,
    'formatsHint' => null,
    'fileType' => null,
])

@php
    $resolvedFileType = $fileType ?? (str_contains($accept, 'image') ? 'image' : 'pdf');
    $resolvedMaxSizeMb = (int) ($maxSizeMb ?? ($resolvedFileType === 'image'
        ? round(config('fileprocessor.limits.max_image_size_kb', 25600) / 1024)
        : round(config('fileprocessor.limits.max_pdf_size_kb', 51200) / 1024)));
    $maxBatchFiles = (int) config('fileprocessor.limits.max_batch_files', 20);
    $maxTotalBatchMb = (int) round(config('fileprocessor.limits.max_total_batch_kb', 102400) / 1024);

    $sizeErrorMessage = $resolvedFileType === 'image'
        ? __('ui.error_image_preupload_too_large', ['size' => $resolvedMaxSizeMb])
        : __('ui.error_file_too_large', ['size' => $resolvedMaxSizeMb]);
@endphp

<div x-data="fileUploadHandler({
        propertyName: '{{ $propertyName }}',
        multiple: {{ $multiple ? 'true' : 'false' }},
        accept: '{{ $accept }}',
        fileType: '{{ $resolvedFileType }}',
        maxSizeMb: {{ $resolvedMaxSizeMb }},
        maxSizeBytes: {{ $resolvedMaxSizeMb * 1024 * 1024 }},
        maxBatchFiles: {{ $maxBatchFiles }},
        maxTotalBatchBytes: {{ $maxTotalBatchMb * 1024 * 1024 }},
        maxTotalBatchMb: {{ $maxTotalBatchMb }},
        locale: '{{ app()->getLocale() }}',
        messages: {
            sizeExceeded: @js($sizeErrorMessage),
            emptyFile: @js(__('ui.error_file_empty')),
            unsupportedFormat: @js(__('ui.error_unsupported_format')),
            tooManyFiles: @js(__('ui.error_too_many_files', ['max' => $maxBatchFiles])),
            batchTooLarge: @js(__('ui.error_batch_too_large', ['max' => $maxTotalBatchMb])),
        }
    })"
     x-on:livewire-upload-start="onUploadStart($event)"
     x-on:livewire-upload-progress="onUploadProgress($event)"
     x-on:livewire-upload-finish="onUploadFinish($event)"
     x-on:livewire-upload-error="onUploadError($event)"
     class="w-full space-y-4">

    <!-- Pre-upload Client Validation Error Alert (Single File or Global Batch Error) -->
    <div x-show="errorMessage && (!rejectedFiles || rejectedFiles.length <= 1)"
         x-cloak
         class="rounded-2xl border border-rose-200 bg-rose-50/95 p-4 text-sm text-rose-800 shadow-xs">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600 mt-0.5">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <div>
                    <h5 class="font-bold text-rose-900" x-text="errorMessage"></h5>
                </div>
            </div>
            <button type="button"
                    @click="clearErrors()"
                    class="rounded-lg p-1 text-rose-500 hover:bg-rose-100 hover:text-rose-800 transition cursor-pointer"
                    aria-label="Dismiss">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    <!-- Pre-upload Client Validation Multi-File Rejection List -->
    <div x-show="rejectedFiles && rejectedFiles.length > 1"
         x-cloak
         class="rounded-2xl border border-rose-200 bg-rose-50/95 p-4 text-sm text-rose-800 shadow-xs space-y-2.5">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-center gap-2 font-bold text-rose-900">
                <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <span>{{ __('ui.error_title') }}</span>
            </div>
            <button type="button"
                    @click="clearErrors()"
                    class="rounded-lg p-1 text-rose-500 hover:bg-rose-100 hover:text-rose-800 transition cursor-pointer"
                    aria-label="Dismiss">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <ul class="space-y-1.5 ps-8 list-disc text-xs text-rose-800">
            <template x-for="(rf, idx) in rejectedFiles" :key="idx">
                <li>
                    <span class="font-bold text-slate-800" x-text="rf.name + ': '"></span>
                    <span x-text="rf.error"></span>
                </li>
            </template>
        </ul>
    </div>

    <!-- UPLOADING / SELECTED STATE: Real Browser Upload Progress -->
    <div x-show="uploadState === 'uploading' || uploadState === 'selected'"
         x-cloak
         class="rounded-3xl border border-indigo-100 bg-white p-6 sm:p-8 shadow-lg shadow-indigo-500/5 space-y-6">

        <!-- Header: State Badge, Filename, Real Percentage -->
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3.5 min-w-0">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                    <svg class="h-6 w-6 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-bold text-indigo-700">
                            {{ __('ui.state_uploading') }}
                        </span>
                    </div>
                    <h4 class="mt-1 truncate text-base font-bold text-slate-800" x-text="fileName"></h4>
                </div>
            </div>

            <!-- Real Percentage Counter -->
            <div class="text-end shrink-0">
                <span class="text-3xl sm:text-4xl font-black text-indigo-600 tracking-tight" x-text="progress + '%'"></span>
            </div>
        </div>

        <!-- Real Progress Bar Track & Fill -->
        <div class="space-y-2">
            <div class="h-3.5 w-full overflow-hidden rounded-full bg-slate-100 p-0.5 ring-1 ring-slate-200/50">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-600 via-indigo-500 to-violet-500 transition-all duration-200 ease-out"
                     :style="'width: ' + Math.max(2, progress) + '%'"
                     role="progressbar"
                     :aria-valuenow="progress"
                     aria-valuemin="0"
                     aria-valuemax="100"></div>
            </div>
        </div>

        <!-- Live Telemetry Metrics: Size, Speed, Remaining Time (Responsive Grid) -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 rounded-2xl bg-slate-50/80 p-4 border border-slate-100 text-xs text-slate-600">
            <!-- Metric 1: Uploaded Size / Total Size -->
            <div class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white shadow-xs text-slate-500">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7c0-2-1.5-3-3.5-3h-9C5.5 4 4 5 4 7z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <span class="block text-[11px] text-slate-400 font-medium">{{ __('ui.uploaded_size') }}</span>
                    <span class="font-bold text-slate-800 truncate" x-text="formatBytes(uploadedBytes) + ' / ' + formatBytes(totalBytes)"></span>
                </div>
            </div>

            <!-- Metric 2: Upload Speed -->
            <div class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white shadow-xs text-slate-500">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <span class="block text-[11px] text-slate-400 font-medium">{{ __('ui.upload_speed') }}</span>
                    <span class="font-bold text-slate-800" x-text="formatSpeed(speed) || '{{ __('ui.calculating') }}'"></span>
                </div>
            </div>

            <!-- Metric 3: Estimated Time Remaining -->
            <div class="flex items-center gap-2.5">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white shadow-xs text-slate-500">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <span class="block text-[11px] text-slate-400 font-medium">{{ __('ui.time_remaining') }}</span>
                    <span class="font-bold text-slate-800" x-text="formatTime(remainingSeconds, {{ app()->getLocale() === 'ar' ? 'true' : 'false' }}) || '{{ __('ui.calculating') }}'"></span>
                </div>
            </div>
        </div>

        <!-- Action Row: Upload Status & Cancel Action -->
        <div class="flex items-center justify-between pt-1">
            <span class="text-xs text-slate-500 flex items-center gap-2">
                <svg class="h-3.5 w-3.5 animate-spin text-indigo-600" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span>{{ __('ui.uploading') }}</span>
            </span>

            <button type="button"
                    @click="cancelUpload()"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-white px-3.5 py-2 text-xs font-semibold text-rose-600 shadow-xs hover:bg-rose-50 hover:border-rose-300 transition active:scale-95 cursor-pointer">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <span>{{ __('ui.cancel_upload') }}</span>
            </button>
        </div>
    </div>

    <!-- EMPTY / IDLE STATE: Drag and Drop Dropzone -->
    <div x-show="uploadState === 'idle'" class="w-full">
        <label @dragover.prevent="isDragging = true"
               @dragleave.prevent="isDragging = false"
               @drop.prevent="isDragging = false; onFileDrop($event)"
               :class="isDragging ? 'border-indigo-500 bg-indigo-50/40 ring-4 ring-indigo-500/10' : 'border-slate-300 bg-white hover:border-indigo-400 hover:bg-indigo-50/20'"
               class="group relative flex flex-col items-center justify-center rounded-3xl border-2 border-dashed p-12 text-center shadow-xs transition cursor-pointer">

            <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 group-hover:scale-110 transition">
                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
            </div>

            <span class="text-lg font-semibold text-slate-900">{{ __('ui.select_files') }}</span>
            <span class="mt-1 text-sm text-slate-500">{{ __('ui.drop_files_here') }}</span>

            <span class="mt-3 inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                @if($formatsHint)
                    {{ $formatsHint }} &bull;
                @endif
                {{ __('ui.max_size_hint', ['size' => $resolvedMaxSizeMb]) }}
            </span>

            <input type="file"
                   x-ref="fileInput"
                   wire:model="{{ $propertyName }}"
                   @change.capture="handleFileSelect($event)"
                   @if($multiple) multiple @endif
                   accept="{{ $accept }}"
                   class="sr-only">
        </label>
    </div>
</div>
