<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.merge_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.merge_pdf.desc') }}</p>
    </div>

    <!-- Authoritative State Stepper -->
    @include('livewire.tools.workflow-stepper', ['jobStatus' => $jobStatus, 'hasUpload' => !empty($uploads)])

    <!-- Processing State (Authoritative FileJob Status Polling) -->
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

    <!-- Upload & Options State -->
    @else
        <!-- Validation Errors Callout -->
        @if ($errors->any())
            <div class="max-w-2xl mx-auto mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 shadow-xs">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(empty($uploads))
            <!-- Dedicated Upload Component (Dropzone + Real Upload Progress + Cancel) -->
            <div class="max-w-2xl mx-auto">
                @include('livewire.tools.upload-progress', [
                    'propertyName' => 'uploads',
                    'multiple'     => true,
                    'accept'       => 'application/pdf',
                    'maxSizeMb'    => $this->getMaxPdfSizeMb(),
                    'formatsHint'  => 'PDF',
                ])
            </div>
        @else
            <!-- Uploaded & Ready State (Multi-File Gallery Grid) -->
            <div class="max-w-4xl mx-auto space-y-6">
                <!-- Header: Count, Size, Reorder Hint, Clear All -->
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <div class="flex items-center gap-2.5">
                            <h3 class="text-base sm:text-lg font-bold text-slate-900">{{ __('ui.files_selected', ['count' => count($uploads)]) }}</h3>
                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700">
                                {{ __('ui.state_uploaded') }}
                            </span>
                            <span class="text-xs sm:text-sm font-semibold text-slate-600 bg-slate-100 rounded-lg px-2.5 py-0.5">
                                {{ $this->totalSizeFormatted }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-1">{{ __('tools.merge_pdf.reorder_hint') }}</p>
                    </div>
                    <button wire:click="cancelToolUpload" class="text-xs font-semibold text-rose-600 hover:underline cursor-pointer">{{ __('ui.clear_all') }}</button>
                </div>

                <!-- Reorderable Responsive Gallery Grid -->
                <div x-data="{
                         onReorder(items) {
                             $wire.reorderUploads(items);
                         }
                     }"
                     x-sortable="onReorder"
                     data-sortable-handle=".drag-handle"
                     class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                    @foreach($uploads as $idx => $file)
                        @php
                            $previewUrl = $this->getPreviewUrl($idx, 1, 120);
                            $highResUrl = $this->getHighResPreviewUrl($idx, 1);
                            $fileKey = is_object($file) && method_exists($file, 'getFilename') ? $file->getFilename() : (string) $idx;
                            $originalName = is_object($file) && method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : 'Document.pdf';
                            $fileSizeBytes = is_object($file) && method_exists($file, 'getSize') ? $file->getSize() : 0;
                            $fileSizeFormatted = number_format($fileSizeBytes / 1024, 1) . ' KB';
                            $pageCount = $this->getPageCountForUpload($idx);
                        @endphp

                        <div wire:key="merge-file-{{ $fileKey }}"
                             data-id="{{ $fileKey }}"
                             class="group relative flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-3 shadow-xs hover:border-indigo-300 hover:shadow-md transition">
                            
                            <!-- Card Header: Index badge, Drag Handle, Remove -->
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

                                <button wire:click="removeFile({{ $idx }})"
                                        type="button"
                                        class="text-slate-400 hover:text-rose-600 p-1 rounded-md hover:bg-rose-50 transition cursor-pointer"
                                        title="{{ __('tools.merge_pdf.remove') }}"
                                        aria-label="{{ __('tools.merge_pdf.remove') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Page 1 Preview Container (Preserves Document 3/4 Aspect Ratio) -->
                            <div class="relative w-full aspect-[3/4] rounded-xl overflow-hidden bg-slate-50 border border-slate-100 flex items-center justify-center"
                                 x-data="pdfProgressivePreview({
                                     url: @js($previewUrl),
                                     highResUrl: @js($highResUrl),
                                     page: 1,
                                     refreshUrl: () => $wire.getPreviewUrlForFilename(@js($fileKey), 1, 120),
                                     refreshHighResUrl: () => $wire.getPreviewUrlForFilename(@js($fileKey), 1, 220)
                                 })">
                                
                                <!-- Skeleton while loading -->
                                <div x-show="!loaded && !error"
                                     class="absolute inset-0 flex flex-col items-center justify-center bg-slate-100 animate-pulse text-slate-400 p-2">
                                    <svg class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <span class="text-[10px] text-slate-400 mt-1 font-medium">{{ __('tools.split_pdf.preview_loading') }}</span>
                                </div>

                                <!-- Real Rendered Page 1 Image -->
                                <img x-show="loaded && !error"
                                     :src="url"
                                     alt="{{ $originalName }}"
                                     class="h-full w-full object-contain transition-transform duration-200 group-hover:scale-[1.02]" />

                                <!-- Error State / Retry -->
                                <div x-show="error"
                                     x-cloak
                                     class="absolute inset-0 flex flex-col items-center justify-center bg-slate-50 p-2 text-center text-slate-400">
                                    <svg class="h-8 w-8 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <button type="button"
                                            @click.stop="retry()"
                                            class="mt-1 text-[11px] font-bold text-indigo-600 hover:underline">
                                        {{ __('tools.split_pdf.retry') }}
                                    </button>
                                </div>

                                <!-- High-Res Inspection Button on Hover -->
                                @if($highResUrl)
                                    <div class="absolute inset-0 bg-slate-900/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center pointer-events-none">
                                        <button type="button"
                                                @click.stop="$dispatch('open-pdf-modal', { page: 1, url: @js($highResUrl), title: @js($originalName) })"
                                                class="pointer-events-auto rounded-xl bg-white/90 px-3 py-1.5 text-xs font-bold text-slate-900 shadow-md backdrop-blur-xs hover:bg-white hover:scale-105 transition">
                                            {{ __('tools.merge_pdf.inspect_file') }}
                                        </button>
                                    </div>
                                @endif
                            </div>

                            <!-- Card Footer: Info & Accessible Move Controls -->
                            <div class="pt-2.5 space-y-1.5">
                                <div class="min-w-0">
                                    <h4 class="truncate text-xs font-bold text-slate-800" title="{{ $originalName }}">
                                        {{ $originalName }}
                                    </h4>
                                    <div class="flex items-center gap-1.5 text-[11px] text-slate-400">
                                        <span>{{ $fileSizeFormatted }}</span>
                                        @if($pageCount)
                                            <span>&bull;</span>
                                            <span>{{ __('tools.merge_pdf.pages_count', ['count' => $pageCount]) }}</span>
                                        @endif
                                    </div>
                                </div>

                                <!-- Accessible Reorder Controls -->
                                <div class="flex items-center justify-between pt-1 border-t border-slate-100">
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                        {{ __('tools.merge_pdf.file_badge', ['num' => $idx + 1]) }}
                                    </span>
                                    <div class="flex items-center gap-1">
                                        <button wire:click="moveUp({{ $idx }})"
                                                type="button"
                                                @if($idx === 0) disabled @endif
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-indigo-600 disabled:opacity-25 disabled:pointer-events-none transition cursor-pointer"
                                                title="{{ __('tools.merge_pdf.move_up') }}"
                                                aria-label="{{ __('tools.merge_pdf.move_up') }}">
                                            <svg class="h-3.5 w-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" />
                                            </svg>
                                        </button>
                                        <button wire:click="moveDown({{ $idx }})"
                                                type="button"
                                                @if($idx === count($uploads) - 1) disabled @endif
                                                class="flex h-7 w-7 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-indigo-600 disabled:opacity-25 disabled:pointer-events-none transition cursor-pointer"
                                                title="{{ __('tools.merge_pdf.move_down') }}"
                                                aria-label="{{ __('tools.merge_pdf.move_down') }}">
                                            <svg class="h-3.5 w-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Add More Files with Dedicated Upload Progress -->
                <div class="pt-2">
                    @include('livewire.tools.upload-progress', [
                        'propertyName' => 'newFiles',
                        'multiple'     => true,
                        'compact'      => true,
                        'hideQuota'    => true,
                        'accept'       => 'application/pdf',
                        'maxSizeMb'    => $this->getMaxPdfSizeMb(),
                        'formatsHint'  => 'PDF',
                    ])
                </div>

                <!-- Submit Action -->
                <div class="pt-4">
                    <button wire:click="process"
                            wire:loading.attr="disabled"
                            type="button"
                            class="w-full flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-700 transition active:scale-[0.99] disabled:opacity-50 cursor-pointer">
                        <span wire:loading.remove wire:target="process">{{ __('tools.merge_pdf.action') }}</span>
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

            @include('livewire.tools.pdf-inspect-modal')
        @endif
    @endif
</div>
