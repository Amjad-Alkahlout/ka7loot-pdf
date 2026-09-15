<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-6xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.split_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-2xl mx-auto">{{ __('tools.split_pdf.desc') }}</p>
    </div>

    <!-- Authoritative State Stepper -->
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
            <div class="max-w-4xl mx-auto mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 shadow-xs">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(!$upload)
            <div class="max-w-3xl mx-auto">
                @include('livewire.tools.upload-progress', [
                    'propertyName' => 'upload',
                    'multiple'     => false,
                    'accept'       => 'application/pdf',
                    'maxSizeMb'    => $this->getMaxPdfSizeMb(),
                    'formatsHint'  => 'PDF',
                ])
            </div>
        @else
            <div class="space-y-8">
                <!-- Document Overview Bar -->
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs font-semibold text-slate-400">{{ __('tools.split_pdf.document_info') }}:</span>
                                <h3 class="truncate font-bold text-slate-900 text-base" title="{{ $filename }}">{{ $filename }}</h3>
                                <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 border border-emerald-200">
                                    {{ __('ui.state_uploaded') }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3 mt-1 text-xs text-slate-500">
                                <span>{{ $fileSizeFormatted }}</span>
                                <span>&bull;</span>
                                <span class="font-semibold text-indigo-600">{{ __('tools.split_pdf.total_pages', ['count' => $pageCount]) }}</span>
                            </div>
                        </div>
                    </div>
                    <button wire:click="cancelToolUpload" type="button" class="text-xs font-semibold text-rose-600 hover:text-rose-700 bg-rose-50 hover:bg-rose-100 rounded-xl px-3 py-2 transition cursor-pointer">
                        {{ __('ui.clear_all') }}
                    </button>
                </div>

                <!-- 3 Split Modes Selector -->
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                    <h3 class="text-base font-bold text-slate-900 mb-4">{{ __('tools.split_pdf.title') }} - {{ __('tools.split_pdf.action') }}</h3>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <!-- Mode A: Range -->
                        <div wire:click="setMode('range')"
                             class="rounded-2xl border-2 p-4 cursor-pointer transition flex flex-col justify-between {{ $mode === 'range' ? 'border-indigo-600 bg-indigo-50/30 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300 bg-white' }}">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold uppercase tracking-wider px-2 py-0.5 rounded-md {{ $mode === 'range' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600' }}">
                                        Mode A
                                    </span>
                                    <input type="radio" name="split_mode" value="range" @checked($mode === 'range') class="text-indigo-600 pointer-events-none">
                                </div>
                                <h4 class="font-bold text-slate-900 text-sm mb-1">{{ __('tools.split_pdf.mode_range') }}</h4>
                                <p class="text-xs text-slate-500 leading-relaxed">{{ __('tools.split_pdf.mode_range_desc') }}</p>
                            </div>
                        </div>

                        <!-- Mode B: Every N pages -->
                        <div wire:click="setMode('every_n')"
                             class="rounded-2xl border-2 p-4 cursor-pointer transition flex flex-col justify-between {{ $mode === 'every_n' ? 'border-indigo-600 bg-indigo-50/30 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300 bg-white' }}">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold uppercase tracking-wider px-2 py-0.5 rounded-md {{ $mode === 'every_n' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600' }}">
                                        Mode B
                                    </span>
                                    <input type="radio" name="split_mode" value="every_n" @checked($mode === 'every_n') class="text-indigo-600 pointer-events-none">
                                </div>
                                <h4 class="font-bold text-slate-900 text-sm mb-1">{{ __('tools.split_pdf.mode_every_n') }}</h4>
                                <p class="text-xs text-slate-500 leading-relaxed">{{ __('tools.split_pdf.mode_every_n_desc') }}</p>
                            </div>
                        </div>

                        <!-- Mode C: All individual pages -->
                        <div wire:click="setMode('all')"
                             class="rounded-2xl border-2 p-4 cursor-pointer transition flex flex-col justify-between {{ $mode === 'all' ? 'border-indigo-600 bg-indigo-50/30 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300 bg-white' }}">
                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold uppercase tracking-wider px-2 py-0.5 rounded-md {{ $mode === 'all' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600' }}">
                                        Mode C
                                    </span>
                                    <input type="radio" name="split_mode" value="all" @checked($mode === 'all') class="text-indigo-600 pointer-events-none">
                                </div>
                                <h4 class="font-bold text-slate-900 text-sm mb-1">{{ __('tools.split_pdf.mode_all') }}</h4>
                                <p class="text-xs text-slate-500 leading-relaxed">{{ __('tools.split_pdf.mode_all_desc') }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Mode Inputs -->
                    <div class="mt-6 pt-6 border-t border-slate-100">
                        @if($mode === 'range')
                            <div class="space-y-2">
                                <label class="block text-sm font-bold text-slate-900">
                                    {{ __('tools.split_pdf.mode_range') }}
                                </label>
                                <div class="relative">
                                    <input type="text"
                                           wire:model.live.debounce.300ms="range"
                                           placeholder="{{ __('tools.split_pdf.range_placeholder') }}"
                                           class="w-full rounded-xl border px-4 py-3 text-sm transition {{ $rangeError ? 'border-rose-300 bg-rose-50/30 focus:border-rose-500 focus:ring-rose-500' : 'border-slate-300 bg-white focus:border-indigo-500 focus:ring-indigo-500' }}">
                                </div>
                                @if($rangeError)
                                    <p class="text-xs font-semibold text-rose-600 flex items-center gap-1.5 mt-1.5">
                                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        {{ $rangeError }}
                                    </p>
                                @else
                                    <p class="text-xs text-slate-500 mt-1">{{ __('tools.split_pdf.range_hint') }}</p>
                                @endif
                            </div>

                        @elseif($mode === 'every_n')
                            <div class="space-y-2">
                                <label class="block text-sm font-bold text-slate-900">
                                    {{ __('tools.split_pdf.every_n_label') }} N {{ __('tools.split_pdf.pages_unit') }}
                                </label>
                                <div class="flex items-center gap-3 max-w-xs">
                                    <input type="number"
                                           wire:model.live.debounce.300ms="everyN"
                                           min="1"
                                           max="{{ max(1, $pageCount) }}"
                                           class="w-32 rounded-xl border px-4 py-2.5 text-center font-bold text-base transition {{ $everyNError ? 'border-rose-300 bg-rose-50/30 focus:border-rose-500' : 'border-slate-300 bg-white focus:border-indigo-500' }}">
                                    <span class="text-sm text-slate-600 font-medium">{{ __('tools.split_pdf.pages_unit') }}</span>
                                </div>
                                @if($everyNError)
                                    <p class="text-xs font-semibold text-rose-600 flex items-center gap-1.5 mt-1.5">
                                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        {{ $everyNError }}
                                    </p>
                                @endif
                            </div>

                        @elseif($mode === 'all')
                            <div class="rounded-xl bg-slate-50 border border-slate-200/80 p-4 text-sm text-slate-700 flex items-center gap-3">
                                <div class="h-8 w-8 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center shrink-0">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                    </svg>
                                </div>
                                <div>
                                    <p class="font-bold text-slate-900">{{ __('tools.split_pdf.individual_summary', ['pages' => $pageCount, 'files' => $pageCount]) }}</p>
                                    <p class="text-xs text-slate-500 mt-0.5">{{ __('tools.split_pdf.zip_package_notice') }}</p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Visual Page Structure & Thumbnail Grid -->
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4 pb-3 border-b border-slate-100">
                        <div>
                            <h3 class="text-base font-bold text-slate-900">{{ __('tools.split_pdf.page_grid_title') }}</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ __('tools.split_pdf.page_grid_subtitle') }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 text-xs text-slate-500">
                                <span class="h-2.5 w-2.5 rounded-full bg-indigo-600 inline-block"></span>
                                {{ __('tools.split_pdf.included') }}
                            </span>
                            @if($mode === 'range')
                                <span class="inline-flex items-center gap-1.5 text-xs text-slate-500 ml-2 rtl:mr-2">
                                    <span class="h-2.5 w-2.5 rounded-full bg-slate-300 inline-block"></span>
                                    {{ __('tools.split_pdf.excluded') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Grid of Pages -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 sm:gap-4 max-h-[460px] overflow-y-auto p-1">
                        @for($p = 1; $p <= $pageCount; $p++)
                            @php
                                $groupInfo = $pageGroupMap[$p] ?? null;
                                $isIncluded = ($groupInfo !== null);
                                $colorClasses = match($groupInfo['colorIndex'] ?? 0) {
                                    0 => ['border' => 'border-indigo-500', 'badge' => 'bg-indigo-600 text-white', 'ring' => 'ring-2 ring-indigo-500/30'],
                                    1 => ['border' => 'border-emerald-500', 'badge' => 'bg-emerald-600 text-white', 'ring' => 'ring-2 ring-emerald-500/30'],
                                    2 => ['border' => 'border-amber-500', 'badge' => 'bg-amber-600 text-white', 'ring' => 'ring-2 ring-amber-500/30'],
                                    3 => ['border' => 'border-purple-500', 'badge' => 'bg-purple-600 text-white', 'ring' => 'ring-2 ring-purple-500/30'],
                                    4 => ['border' => 'border-rose-500', 'badge' => 'bg-rose-600 text-white', 'ring' => 'ring-2 ring-rose-500/30'],
                                    default => ['border' => 'border-sky-500', 'badge' => 'bg-sky-600 text-white', 'ring' => 'ring-2 ring-sky-500/30'],
                                };
                            @endphp

                            <div wire:click="togglePage({{ $p }})"
                                 title="{{ $mode === 'range' ? 'Click to toggle page ' . $p : 'Page ' . $p }}"
                                 class="group relative flex flex-col rounded-xl border-2 transition overflow-hidden bg-slate-50 {{ $mode === 'range' ? 'cursor-pointer hover:shadow-md' : '' }} {{ $isIncluded ? ($colorClasses['border'] . ' ' . $colorClasses['ring'] . ' bg-white') : 'border-slate-200 opacity-40 hover:opacity-75 grayscale' }}">

                                <!-- Top Bar: Page No + Group Badge -->
                                <div class="p-2 flex items-center justify-between bg-slate-50/80 border-b border-slate-100 text-[11px] font-bold">
                                    <span class="text-slate-700">{{ __('tools.split_pdf.page_badge', ['page' => $p]) }}</span>
                                    @if($isIncluded)
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-extrabold tracking-tight {{ $colorClasses['badge'] }}">
                                            {{ $groupInfo['label'] }}
                                        </span>
                                    @else
                                        <span class="text-[10px] text-slate-400 font-normal">
                                            -
                                        </span>
                                    @endif
                                </div>

                                <!-- Thumbnail Preview Area -->
                                <div class="relative aspect-[3/4] w-full flex items-center justify-center p-2 bg-slate-100/50">
                                    @if(!empty($thumbnails[$p]))
                                        <img src="{{ $thumbnails[$p] }}" alt="Page {{ $p }}" class="h-full w-full object-contain rounded shadow-2xs">
                                    @else
                                        <!-- Clean SVG Document Mockup -->
                                        <div class="h-full w-full rounded border border-slate-200 bg-white p-2 flex flex-col justify-between shadow-2xs">
                                            <div class="space-y-1">
                                                <div class="h-1.5 w-3/4 bg-slate-200 rounded"></div>
                                                <div class="h-1.5 w-full bg-slate-100 rounded"></div>
                                                <div class="h-1.5 w-5/6 bg-slate-100 rounded"></div>
                                            </div>
                                            <div class="text-center">
                                                <span class="text-lg font-black text-slate-300 select-none">{{ $p }}</span>
                                            </div>
                                            <div class="h-1 w-1/2 bg-slate-100 rounded"></div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endfor
                    </div>
                </div>

                <!-- Live Split Preview Section -->
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xs">
                    <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
                        <div>
                            <h3 class="text-base font-bold text-slate-900">{{ __('tools.split_pdf.live_preview_title') }}</h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ __('tools.split_pdf.live_preview_subtitle') }}</p>
                        </div>
                        <span class="inline-flex items-center rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">
                            {{ count($splitGroups) }} {{ trans_choice('file|files', count($splitGroups)) }}
                        </span>
                    </div>

                    @if(empty($splitGroups))
                        <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-slate-400 text-sm">
                            {{ $rangeError ?? __('tools.split_pdf.error_empty_range') }}
                        </div>
                    @else
                        <div class="space-y-3">
                            @foreach($splitGroups as $idx => $group)
                                @php
                                    $colorClasses = match(($group['id'] - 1) % 6) {
                                        0 => ['border' => 'border-indigo-200 bg-indigo-50/20', 'pill' => 'bg-indigo-600 text-white'],
                                        1 => ['border' => 'border-emerald-200 bg-emerald-50/20', 'pill' => 'bg-emerald-600 text-white'],
                                        2 => ['border' => 'border-amber-200 bg-amber-50/20', 'pill' => 'bg-amber-600 text-white'],
                                        3 => ['border' => 'border-purple-200 bg-purple-50/20', 'pill' => 'bg-purple-600 text-white'],
                                        4 => ['border' => 'border-rose-200 bg-rose-50/20', 'pill' => 'bg-rose-600 text-white'],
                                        default => ['border' => 'border-sky-200 bg-sky-50/20', 'pill' => 'bg-sky-600 text-white'],
                                    };
                                @endphp

                                <div class="rounded-xl border p-4 transition {{ $colorClasses['border'] }} flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div class="flex items-center gap-3">
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-extrabold shadow-2xs {{ $colorClasses['pill'] }}">
                                            {{ $group['label'] }}
                                        </span>
                                        <div>
                                            <h4 class="font-bold text-sm text-slate-800">
                                                @if($group['count'] === 1)
                                                    {{ __('tools.split_pdf.single_page', ['page' => $group['range']]) }}
                                                @else
                                                    {{ __('tools.split_pdf.pages_range', ['range' => $group['range'], 'count' => $group['count']]) }}
                                                @endif
                                            </h4>
                                        </div>
                                    </div>

                                    <!-- Thumbnail strip of pages in this group -->
                                    <div class="flex items-center gap-1.5 overflow-x-auto py-1 max-w-full">
                                        @foreach(array_slice($group['pages'], 0, 10) as $pageNo)
                                            <div class="relative h-12 w-9 shrink-0 rounded border border-slate-200 overflow-hidden bg-white shadow-2xs flex items-center justify-center">
                                                @if(!empty($thumbnails[$pageNo]))
                                                    <img src="{{ $thumbnails[$pageNo] }}" alt="P{{ $pageNo }}" class="h-full w-full object-contain">
                                                @else
                                                    <span class="text-[10px] font-bold text-slate-400">{{ $pageNo }}</span>
                                                @endif
                                                <div class="absolute bottom-0 inset-x-0 bg-slate-900/70 text-white text-[8px] font-bold text-center leading-tight">
                                                    {{ $pageNo }}
                                                </div>
                                            </div>
                                        @endforeach
                                        @if(count($group['pages']) > 10)
                                            <span class="text-xs font-bold text-slate-400 px-1.5">+{{ count($group['pages']) - 10 }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <!-- Submit Action Button -->
                <div>
                    <button wire:click="process"
                            wire:loading.attr="disabled"
                            type="button"
                            @disabled(!empty($rangeError) || !empty($everyNError) || empty($splitGroups))
                            class="w-full flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-700 transition active:scale-[0.99] disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer">
                        <span wire:loading.remove wire:target="process">
                            {{ $submitSummaryText }}
                        </span>
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
        @endif
    @endif
</div>
