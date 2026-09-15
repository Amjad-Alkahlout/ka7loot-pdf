<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.jpg_to_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.jpg_to_pdf.desc') }}</p>
    </div>

    <!-- Authoritative State Stepper -->
    @include('livewire.tools.workflow-stepper', ['jobStatus' => $jobStatus, 'hasUpload' => !empty($uploads)])

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
            <div class="max-w-3xl mx-auto mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 shadow-xs">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(empty($uploads))
            <div class="max-w-2xl mx-auto">
                @include('livewire.tools.upload-progress', [
                    'propertyName' => 'uploads',
                    'multiple'     => true,
                    'accept'       => 'image/jpeg,image/png,image/webp',
                    'maxSizeMb'    => $this->getMaxImageSizeMb(),
                    'formatsHint'  => 'JPG, PNG, WebP',
                ])
            </div>
        @else
            <div class="max-w-3xl mx-auto space-y-6">
                <!-- Header: Selected count + reorder hint -->
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-bold text-slate-900">{{ __('ui.files_selected', ['count' => count($uploads)]) }}</h3>
                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700">
                                {{ __('ui.state_uploaded') }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">{{ __('tools.jpg_to_pdf.reorder_hint') }}</p>
                    </div>
                    <button wire:click="cancelToolUpload" class="text-xs font-semibold text-rose-600 hover:underline cursor-pointer">{{ __('ui.clear_all') }}</button>
                </div>

                <!-- Reorderable Image Cards Grid/List -->
                <div class="space-y-3">
                    @foreach($uploads as $idx => $file)
                        @php
                            $previewUrl = null;
                            try {
                                $previewUrl = $file->temporaryUrl();
                            } catch (\Throwable) {}

                            $dims = $this->getImageDimensions($file);
                        @endphp

                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl border border-slate-200/90 bg-white shadow-xs hover:border-indigo-200 transition">
                            <div class="flex items-center gap-4 min-w-0">
                                <!-- Image Thumbnail / Preview -->
                                <div class="relative h-20 w-20 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-slate-100 flex items-center justify-center">
                                    @if($previewUrl)
                                        <img src="{{ $previewUrl }}" alt="Preview" class="h-full w-full object-cover">
                                    @else
                                        <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                    @endif
                                    <span class="absolute top-1 start-1 flex h-5 w-5 items-center justify-center rounded-md bg-slate-900/80 text-[10px] font-bold text-white shadow-xs">
                                        {{ $idx + 1 }}
                                    </span>
                                </div>

                                <!-- File Metadata -->
                                <div class="min-w-0 flex-1 space-y-1">
                                    <!-- Page Order Visual Indicator -->
                                    <div class="inline-flex items-center rounded-lg bg-indigo-50 px-2.5 py-0.5 text-xs font-bold text-indigo-700 border border-indigo-100">
                                        {{ __('tools.jpg_to_pdf.image_to_page', ['img' => $idx + 1, 'page' => $idx + 1]) }}
                                    </div>

                                    <h4 class="truncate font-semibold text-slate-800 text-sm" title="{{ $file->getClientOriginalName() }}">
                                        {{ $file->getClientOriginalName() }}
                                    </h4>

                                    <div class="flex items-center gap-2 text-xs text-slate-400">
                                        <span>{{ number_format($file->getSize() / 1024, 1) }} KB</span>
                                        @if($dims)
                                            <span>&bull;</span>
                                            <span>{{ __('tools.jpg_to_pdf.dimensions', ['width' => $dims['width'], 'height' => $dims['height']]) }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <!-- Controls: Move Up, Move Down, Remove -->
                            <div class="flex items-center justify-end gap-1.5 shrink-0 pt-2 sm:pt-0 border-t sm:border-t-0 border-slate-100">
                                <button wire:click="moveUp({{ $idx }})"
                                        type="button"
                                        @if($idx === 0) disabled @endif
                                        title="{{ __('tools.jpg_to_pdf.move_up') }}"
                                        class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-xs hover:border-indigo-400 hover:text-indigo-600 disabled:opacity-30 disabled:cursor-not-allowed transition">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7" />
                                    </svg>
                                </button>

                                <button wire:click="moveDown({{ $idx }})"
                                        type="button"
                                        @if($idx === count($uploads) - 1) disabled @endif
                                        title="{{ __('tools.jpg_to_pdf.move_down') }}"
                                        class="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-xs hover:border-indigo-400 hover:text-indigo-600 disabled:opacity-30 disabled:cursor-not-allowed transition">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>

                                <button wire:click="removeUpload({{ $idx }})"
                                        type="button"
                                        class="flex h-9 w-9 items-center justify-center rounded-xl border border-rose-200 bg-rose-50 text-rose-600 shadow-xs hover:bg-rose-100 transition ml-2">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Add More Images Progress -->
                <div class="pt-2">
                    @include('livewire.tools.upload-progress', [
                        'propertyName' => 'uploads',
                        'multiple'     => true,
                        'accept'       => 'image/jpeg,image/png,image/webp',
                        'maxSizeMb'    => $this->getMaxImageSizeMb(),
                        'formatsHint'  => 'JPG, PNG, WebP',
                    ])
                </div>

                <!-- Page Layout Options -->
                <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs space-y-4">
                    <h3 class="text-sm font-bold text-slate-900">{{ __('ui.options') }}</h3>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Page Size -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5">{{ __('tools.jpg_to_pdf.page_size') }}</label>
                            <select wire:model="pageSize" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <option value="a4">A4</option>
                                <option value="letter">Letter</option>
                                <option value="legal">Legal</option>
                                <option value="fit">{{ __('tools.jpg_to_pdf.fit_image') }}</option>
                            </select>
                        </div>

                        <!-- Orientation -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5">{{ __('tools.jpg_to_pdf.orientation') }}</label>
                            <select wire:model="orientation" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <option value="auto">Auto</option>
                                <option value="portrait">Portrait</option>
                                <option value="landscape">Landscape</option>
                            </select>
                        </div>

                        <!-- Margin -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1.5">{{ __('tools.jpg_to_pdf.margin') }}</label>
                            <select wire:model="margin" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <option value="0">{{ __('tools.jpg_to_pdf.margin_none') }}</option>
                                <option value="10">{{ __('tools.jpg_to_pdf.margin_small') }}</option>
                                <option value="20">{{ __('tools.jpg_to_pdf.margin_big') }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <button wire:click="process"
                        wire:loading.attr="disabled"
                        type="button"
                        class="w-full flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-700 transition active:scale-[0.99] disabled:opacity-50 cursor-pointer">
                    <span wire:loading.remove wire:target="process">{{ __('tools.jpg_to_pdf.action') }}</span>
                    <span wire:loading wire:target="process" class="flex items-center gap-2">
                        <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>{{ __('ui.processing') }}</span>
                    </span>
                </button>
            </div>
        @endif
    @endif
</div>
