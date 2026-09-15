<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-6xl mx-auto">
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.organize_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.organize_pdf.desc') }}</p>
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
            <div class="max-w-2xl mx-auto">
                @include('livewire.tools.upload-progress', [
                    'propertyName' => 'upload',
                    'multiple'     => false,
                    'accept'       => 'application/pdf',
                    'maxSizeMb'    => $this->getMaxPdfSizeMb(),
                    'formatsHint'  => 'PDF',
                ])
            </div>
        @else
            <div class="space-y-6">
                <!-- Document Info Card -->
                <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h4 class="truncate font-bold text-slate-800 text-base">{{ $filename }}</h4>
                            <div class="flex items-center gap-3 text-xs text-slate-500 mt-0.5">
                                <span>{{ $fileSizeFormatted }}</span>
                                <span>&bull;</span>
                                <span class="font-medium text-slate-700">{{ $pageCount }} {{ __('tools.split_pdf.pages_label') }}</span>
                                <span>&bull;</span>
                                <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">
                                    {{ __('tools.organize_pdf.pages_retained', ['count' => $this->activeCount]) }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="resetPages" type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition cursor-pointer">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                            {{ __('tools.organize_pdf.reset_all') }}
                        </button>
                        <button wire:click="cancelToolUpload" type="button" class="text-xs font-semibold text-rose-600 hover:underline cursor-pointer">
                            {{ __('ui.clear_all') }}
                        </button>
                    </div>
                </div>

                <!-- Page Management Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    @foreach($pages as $index => $page)
                        @php
                            $isDeleted = $page['deleted'];
                            $rotation = $page['rotation'];
                            $origPage = $page['original_page'];
                            $isSelected = ($selectedPageId === $page['id']);
                        @endphp

                        <div wire:key="page-card-{{ $page['id'] }}"
                             wire:click="selectPage({{ $page['id'] }})"
                             class="group relative flex flex-col justify-between rounded-2xl border transition-all duration-200 bg-white p-3 shadow-xs
                                    {{ $isSelected ? 'border-indigo-500 ring-2 ring-indigo-500/20' : 'border-slate-200 hover:border-slate-300 hover:shadow-sm' }}
                                    {{ $isDeleted ? 'opacity-40 grayscale bg-slate-50/80 border-dashed' : '' }}">

                            <!-- Header Bar: Order # & Original Page -->
                            <div class="flex items-center justify-between gap-1 mb-2 text-xs">
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-900 text-[10px] font-bold text-white shadow-xs">
                                        {{ $index + 1 }}
                                    </span>
                                    <span class="font-medium text-slate-600">
                                        {{ __('tools.organize_pdf.page_label') }} {{ $origPage }}
                                    </span>
                                </div>

                                @if($isDeleted)
                                    <span class="inline-flex items-center rounded-md bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-700">
                                        {{ __('tools.organize_pdf.page_deleted') }}
                                    </span>
                                @elseif($rotation > 0)
                                    <span class="inline-flex items-center rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">
                                        ↻ {{ $rotation }}°
                                    </span>
                                @endif
                            </div>

                            <!-- Thumbnail Viewport with CSS Live Rotation -->
                            <div class="relative w-full aspect-[3/4] bg-slate-100 rounded-xl overflow-hidden flex items-center justify-center border border-slate-200/60 p-2">
                                @if(isset($thumbnails[$origPage]))
                                    <img src="{{ $thumbnails[$origPage] }}"
                                         alt="Page {{ $origPage }}"
                                         class="max-w-full max-h-full object-contain drop-shadow-sm transition-transform duration-300 ease-in-out"
                                         style="transform: rotate({{ $rotation }}deg);"
                                         loading="lazy" />
                                @else
                                    <div class="text-center p-3 transition-transform duration-300" style="transform: rotate({{ $rotation }}deg);">
                                        <svg class="h-8 w-8 mx-auto text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                        </svg>
                                        <span class="text-[11px] font-bold text-slate-500 mt-1 block">{{ __('tools.organize_pdf.page_label') }} {{ $origPage }}</span>
                                    </div>
                                @endif

                                @if($isDeleted)
                                    <div class="absolute inset-0 bg-slate-900/20 backdrop-blur-[1px] flex items-center justify-center">
                                        <button wire:click.stop="restorePage({{ $index }})"
                                                type="button"
                                                class="rounded-xl bg-white/95 px-3 py-1.5 text-xs font-bold text-slate-900 shadow-md hover:bg-white transition cursor-pointer">
                                            {{ __('tools.organize_pdf.restore_page') }}
                                        </button>
                                    </div>
                                @endif
                            </div>

                            <!-- Per-Page Action Toolbar -->
                            <div class="mt-3 pt-2 border-t border-slate-100 flex items-center justify-between gap-1">
                                <!-- Reordering Buttons -->
                                <div class="flex items-center gap-1">
                                    <button wire:click.stop="moveUp({{ $index }})"
                                            type="button"
                                            {{ $index === 0 ? 'disabled' : '' }}
                                            title="{{ __('tools.organize_pdf.move_up') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-slate-50 text-slate-600 hover:bg-slate-200 transition disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer text-xs font-bold">
                                        &larr;
                                    </button>
                                    <button wire:click.stop="moveDown({{ $index }})"
                                            type="button"
                                            {{ $index === count($pages) - 1 ? 'disabled' : '' }}
                                            title="{{ __('tools.organize_pdf.move_down') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-slate-50 text-slate-600 hover:bg-slate-200 transition disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer text-xs font-bold">
                                        &rarr;
                                    </button>
                                </div>

                                <!-- Rotation & Delete Buttons -->
                                <div class="flex items-center gap-1">
                                    <button wire:click.stop="rotateLeft({{ $index }})"
                                            type="button"
                                            title="{{ __('tools.organize_pdf.rotate_left') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition cursor-pointer text-xs font-bold">
                                        ↺
                                    </button>
                                    <button wire:click.stop="rotateRight({{ $index }})"
                                            type="button"
                                            title="{{ __('tools.organize_pdf.rotate_right') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700 hover:bg-indigo-100 transition cursor-pointer text-xs font-bold">
                                        ↻
                                    </button>
                                    <button wire:click.stop="toggleDelete({{ $index }})"
                                            type="button"
                                            title="{{ $isDeleted ? __('tools.organize_pdf.restore_page') : __('tools.organize_pdf.delete_page') }}"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-lg {{ $isDeleted ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'bg-rose-50 text-rose-600 hover:bg-rose-100' }} transition cursor-pointer text-xs font-bold">
                                        {{ $isDeleted ? '✓' : '✕' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Final Arrangement Live Summary Bar -->
                <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 shadow-xs">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="space-y-1">
                            <h5 class="text-xs font-bold uppercase tracking-wider text-slate-500">
                                {{ __('tools.organize_pdf.final_summary') }}
                            </h5>
                            <div class="flex flex-wrap items-center gap-1.5 text-xs">
                                @forelse($this->activePages as $activeIdx => $ap)
                                    <span class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2 py-1 font-semibold text-slate-700 shadow-2xs">
                                        <span class="text-slate-400">#{{ $activeIdx + 1 }}:</span>
                                        <span>{{ __('tools.organize_pdf.page_label') }} {{ $ap['original_page'] }}</span>
                                        @if($ap['rotation'] > 0)
                                            <span class="text-indigo-600 font-bold">({{ $ap['rotation'] }}°)</span>
                                        @endif
                                    </span>
                                    @if(!$loop->last)
                                        <span class="text-slate-400">&rarr;</span>
                                    @endif
                                @empty
                                    <span class="font-semibold text-rose-600">
                                        {{ __('tools.organize_pdf.error_no_pages') }}
                                    </span>
                                @endforelse
                            </div>
                        </div>

                        <div class="text-right shrink-0">
                            <span class="text-xs font-bold text-slate-700">
                                {{ __('tools.organize_pdf.pages_retained', ['count' => $this->activeCount]) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Submit Process Button -->
                <button wire:click="process"
                        wire:loading.attr="disabled"
                        type="button"
                        {{ $this->activeCount === 0 ? 'disabled' : '' }}
                        class="w-full flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-700 transition active:scale-[0.99] disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                    <span wire:loading.remove wire:target="process">{{ __('tools.organize_pdf.action') }}</span>
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
