<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-6">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.edit_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-2xl mx-auto">{{ __('tools.edit_pdf.desc') }}</p>
    </div>

    <!-- Stepper -->
    @include('livewire.tools.workflow-stepper', ['jobStatus' => $jobStatus, 'hasUpload' => (bool)$upload])

    <!-- Processing State -->
    @if($jobStatus === 'processing')
        <div wire:poll.1s="pollJobStatus" class="max-w-xl mx-auto rounded-3xl border border-slate-200 bg-white p-8 sm:p-10 text-center shadow-lg">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                <svg class="h-8 w-8 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900">{{ $statusMessage ?? __('ui.status_processing') }}</h3>
            <p class="mt-2 text-sm text-slate-500">{{ __('tools.edit_pdf.processing_hint') }}</p>
            <div class="mt-6 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full w-full bg-gradient-to-r from-indigo-500 to-violet-500 animate-pulse rounded-full"></div>
            </div>
        </div>

    <!-- Completed State -->
    @elseif($jobStatus === 'completed' && ($this->fileJob || $completedJob))
        @include('livewire.tools.job-result', ['job' => $this->fileJob ?? $completedJob, 'processedFiles' => $this->processedFiles])

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
            <button wire:click="resetTool" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition cursor-pointer">
                &larr; {{ __('ui.try_another') }}
            </button>
        </div>

    <!-- Analyzing State -->
    @elseif($jobStatus === 'analyzing')
        <div class="max-w-xl mx-auto rounded-3xl border border-slate-200 bg-white p-8 sm:p-10 text-center shadow-lg">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                <svg class="h-8 w-8 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900">{{ __('tools.edit_pdf.doc_info') }}</h3>
            <p class="mt-2 text-sm text-slate-500">Analyzing native PDF text objects and structure...</p>
        </div>

    <!-- Upload & Workspace State -->
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
            <!-- Dedicated Upload Component -->
            <div class="max-w-2xl mx-auto">
                @include('livewire.tools.upload-progress', [
                    'propertyName' => 'upload',
                    'multiple'     => false,
                    'accept'       => 'application/pdf',
                    'maxSizeMb'    => $this->getMaxPdfSizeMb(),
                    'formatsHint'  => 'PDF',
                ])
            </div>
        @elseif($passwordRequired)
            <!-- Encrypted PDF Password Prompt -->
            <div class="max-w-md mx-auto rounded-3xl border border-amber-200 bg-amber-50/70 p-6 sm:p-8 text-center shadow-md">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-100 text-amber-600">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-amber-900">{{ __('tools.edit_pdf.encrypted_notice') }}</h3>
                @if($passwordError)
                    <p class="mt-2 text-xs text-rose-600 font-semibold">{{ $passwordError }}</p>
                @endif
                <div class="mt-4 space-y-3">
                    <input
                        type="password"
                        wire:model="password"
                        wire:keydown.enter="unlockWithPassword"
                        placeholder="{{ __('tools.edit_pdf.password_placeholder') }}"
                        class="w-full rounded-xl border border-amber-300 px-4 py-2.5 text-sm bg-white text-slate-900 focus:ring-2 focus:ring-amber-500 focus:outline-hidden"
                    />
                    <button
                        wire:click="unlockWithPassword"
                        class="w-full rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-amber-700 transition cursor-pointer"
                    >
                        {{ __('tools.edit_pdf.unlock_button') }}
                    </button>
                </div>
            </div>
        @else
            <!-- Scanned / Image-Only Alert -->
            @if($isScanned)
                <div class="max-w-4xl mx-auto mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 flex items-start gap-3 shadow-xs">
                    <svg class="w-6 h-6 text-rose-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div>
                        <span class="font-bold block text-base">{{ __('tools.edit_pdf.scanned_warning_title') }}</span>
                        <span class="mt-1 block text-slate-700">{{ __('tools.edit_pdf.scanned_warning') }}</span>
                    </div>
                </div>
            @endif

            <!-- Digitally Signed Warning -->
            @if($isSigned)
                <div class="max-w-4xl mx-auto mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 flex items-start gap-3 shadow-xs">
                    <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <span class="font-bold">{{ __('tools.edit_pdf.signed_warning_title') }}:</span>
                        <span>{{ __('tools.edit_pdf.signed_warning') }}</span>
                    </div>
                </div>
            @endif

            <!-- Main Editor Workspace -->
            <div
                x-data="pdfEditorPreview({
                    initialText: @js($editText),
                    initialFontSize: @js($editFontSize ?? ($selectedGroup['font_size'] ?? $selectedObject['font_size'] ?? 12.0)),
                    initialColor: @js($editColor ?: '#000000'),
                    initialAutoFit: @js($autoFit),
                    origText: @js($selectedGroup['text'] ?? $selectedObject['text'] ?? ''),
                    baseFontSize: @js($selectedGroup['font_size'] ?? $selectedObject['font_size'] ?? 12.0),
                    direction: @js($selectedGroup['direction'] ?? null),
                    zoomScale: @js($zoomLevel / 100)
                })"
                class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start"
            >
                <!-- Left Column: Document Preview & Interactive Overlays -->
                <div class="lg:col-span-7 xl:col-span-8 flex flex-col items-center">
                    <!-- Top Control Toolbar: Navigation & Zoom -->
                    <div class="w-full mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-xs">
                        <!-- Page Navigation -->
                        <div class="flex items-center gap-2">
                            <button
                                wire:click="goToPage({{ max(1, $currentPage - 1) }})"
                                @disabled($currentPage <= 1)
                                class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer"
                            >
                                &larr; {{ __('tools.edit_pdf.prev_page') }}
                            </button>
                            <span class="text-xs font-medium text-slate-600">
                                {{ __('tools.edit_pdf.page_indicator', ['current' => $currentPage, 'total' => $pageCount]) }}
                            </span>
                            <button
                                wire:click="goToPage({{ min($pageCount, $currentPage + 1) }})"
                                @disabled($currentPage >= $pageCount)
                                class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer"
                            >
                                {{ __('tools.edit_pdf.next_page') }} &rarr;
                            </button>
                        </div>

                        <!-- Zoom Controls -->
                        <div class="flex items-center gap-1 bg-slate-100 p-1 rounded-lg text-xs font-medium text-slate-700">
                            <button wire:click="setZoom({{ max(50, $zoomLevel - 25) }})" class="px-2 py-1 hover:bg-white rounded cursor-pointer">-</button>
                            <span class="px-2 font-bold">{{ $zoomLevel }}%</span>
                            <button wire:click="setZoom({{ min(200, $zoomLevel + 25) }})" class="px-2 py-1 hover:bg-white rounded cursor-pointer">+</button>
                            <button wire:click="setZoom(100)" class="ml-1 px-2 py-1 text-[11px] hover:bg-white rounded cursor-pointer">{{ __('tools.edit_pdf.fit_width') }}</button>
                        </div>

                        <!-- Document Stats Badges -->
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            @if(!$this->isCurrentPageNativeEditable())
                                <span class="inline-flex items-center gap-1 rounded-md bg-amber-100 px-2 py-1 text-[11px] font-semibold text-amber-800">
                                    &#128274; {{ __('tools.edit_pdf.read_only_badge') }}
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 text-[11px] font-semibold text-indigo-700">
                                {{ count($currentTextGroups) }} {{ __('tools.edit_pdf.text_regions_count') }}
                            </span>
                        </div>
                    </div>

                    <!-- Unsafe Page / Type 3 Warning Banner -->
                    @if(!$this->isCurrentPageNativeEditable())
                        <div class="w-full mb-4 rounded-2xl border border-amber-300 bg-amber-50/90 p-4 text-sm text-amber-900 flex items-start gap-3 shadow-xs">
                            <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-amber-950">{{ __('tools.edit_pdf.type3_warning_title') }}</span>
                                    <span class="text-[10px] font-semibold uppercase bg-amber-200 text-amber-900 px-2 py-0.5 rounded-md">
                                        {{ __('tools.edit_pdf.read_only_badge') }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-amber-900/90 leading-relaxed">
                                    {{ __('tools.edit_pdf.type3_warning_message') }}
                                </p>
                                <details class="mt-2 text-[11px] text-amber-800 cursor-pointer">
                                    <summary class="font-semibold hover:text-amber-950">{{ __('tools.edit_pdf.technical_details_toggle') }}</summary>
                                    <div class="mt-1 p-2 bg-amber-100/70 rounded-lg text-amber-950 font-mono text-[10px] leading-tight">
                                        {{ $this->currentPageWarning() ?: __('tools.edit_pdf.type3_technical_details') }}
                                    </div>
                                </details>
                            </div>
                        </div>
                    @endif

                    <!-- Canvas Viewport with Text Overlays -->
                    <div class="w-full overflow-auto rounded-2xl border border-slate-300 bg-slate-200/60 p-4 sm:p-6 flex justify-center shadow-inner min-h-[500px]">
                        <div
                            id="pdf-page-viewport"
                            class="relative bg-white shadow-2xl transition-all duration-150 origin-top"
                            style="width: {{ ($pageDimensions['width'] ?? 595.28) * ($zoomLevel / 100) }}px; height: {{ ($pageDimensions['height'] ?? 841.89) * ($zoomLevel / 100) }}px;"
                        >
                            <!-- Rendered Poppler Page Image -->
                            @if(!empty($pagePreviews[$currentPage]))
                                <img
                                    src="{{ $pagePreviews[$currentPage] }}"
                                    alt="PDF Page {{ $currentPage }}"
                                    class="w-full h-full object-contain pointer-events-none select-none"
                                    draggable="false"
                                />
                            @else
                                <div class="w-full h-full flex flex-col items-center justify-center text-slate-400 gap-2">
                                    <svg class="h-8 w-8 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    <span class="text-xs">Loading high-resolution page render...</span>
                                </div>
                            @endif

                            <!-- Text Selection & WYSIWYG Live Preview Layer -->
                            @if(!$isScanned)
                                @php
                                    $pWidth = max(1.0, (float)($pageDimensions['width'] ?? 595.28));
                                    $pHeight = max(1.0, (float)($pageDimensions['height'] ?? 841.89));
                                    $selectableItems = !empty($currentTextGroups) ? $currentTextGroups : $currentTextObjects;
                                    $zoomScale = $zoomLevel / 100;
                                    $activeSelectionId = $selectedGroupId ?? $selectedObjectId;
                                @endphp

                                <!-- 1. Interactive Selection Overlays -->
                                @foreach($selectableItems as $item)
                                    @php
                                        $isGroup = isset($item['group_id']);
                                        $itemId = $isGroup ? $item['group_id'] : ($item['object_id'] ?? $item['id']);
                                        $bbox = $isGroup ? ($item['bounding_box'] ?? []) : ($item['bbox'] ?? []);
                                        $xPct = max(0, min(100, (($bbox['left'] ?? 0) / $pWidth) * 100));
                                        $yPct = max(0, min(100, (($pHeight - ($bbox['top'] ?? 0)) / $pHeight) * 100));
                                        $wPct = max(0.5, min(100, (($bbox['width'] ?? 20) / $pWidth) * 100));
                                        $hPct = max(0.5, min(100, (($bbox['height'] ?? 10) / $pHeight) * 100));

                                        $isSelected = ($activeSelectionId === $itemId);
                                        $isEdited = isset($editManifest[$itemId]);
                                    @endphp
                                    <div
                                        wire:key="clickable_{{ $itemId }}"
                                        wire:click="{{ $isGroup ? 'selectTextGroup' : 'selectTextObject' }}('{{ $itemId }}')"
                                        class="absolute cursor-pointer transition select-none {{ $isSelected ? 'ring-2 ring-indigo-600 bg-indigo-500/10 z-20' : ($isEdited ? 'ring-1 ring-emerald-500 bg-emerald-500/10 z-15' : 'hover:ring-1 hover:ring-indigo-400 hover:bg-indigo-400/10 z-10') }}"
                                        style="left: {{ $xPct }}%; top: {{ $yPct }}%; width: {{ $wPct }}%; height: {{ $hPct }}%;"
                                        title="{{ $isEdited ? 'Edited: ' . ($editManifest[$itemId]['replacement_text'] ?? '') : ($item['text'] ?? '') }}"
                                    ></div>
                                @endforeach

                                <!-- 2. Saved / Pending Edits from editManifest on Current Page -->
                                @foreach($editManifest as $mId => $mItem)
                                    @if(((int)($mItem['page'] ?? (($mItem['page_index'] ?? 0) + 1))) === $currentPage)
                                        @if($mId !== $activeSelectionId)
                                            @php
                                                $mBbox = $mItem['bbox'] ?? [];
                                                $mxPct = max(0, min(100, (($mBbox['left'] ?? 0) / $pWidth) * 100));
                                                $myPct = max(0, min(100, (($pHeight - ($mBbox['top'] ?? 0)) / $pHeight) * 100));
                                                $mwPct = max(0.5, min(100, (($mBbox['width'] ?? 20) / $pWidth) * 100));
                                                $mhPct = max(0.5, min(100, (($mBbox['height'] ?? 10) / $pHeight) * 100));
                                                $mText = (string)($mItem['replacement_text'] ?? '');
                                                $mAutoFit = (bool)($mItem['auto_fit'] ?? true);
                                                $mBaseSize = (float)($mItem['font_size'] ?? 12.0);
                                                $mTargetText = (string)($mItem['target_text'] ?? '');
                                                $mEffSize = $this->computeEffectiveFontSize($mBaseSize, $mTargetText, $mText, $mAutoFit);
                                                $mSizePx = max(5, $mEffSize * $zoomScale);
                                                $mColor = $mItem['color'] ?? '#000000';
                                                $mIsRtl = $this->isRtlText($mText, $mItem['direction'] ?? null);
                                            @endphp
                                            <div
                                                wire:key="manifest_preview_{{ $mId }}"
                                                wire:click="{{ isset($mItem['group_id']) ? 'selectTextGroup' : 'selectTextObject' }}('{{ $mId }}')"
                                                class="absolute z-25 bg-white border border-emerald-400 rounded-xs shadow-xs px-1 cursor-pointer flex items-center {{ $mIsRtl ? 'justify-end' : 'justify-start' }}"
                                                style="left: {{ $mxPct }}%; top: {{ $myPct }}%; width: {{ $mwPct }}%; min-height: {{ $mhPct }}%; font-size: {{ $mSizePx }}px; line-height: 1.15; color: {{ $mColor }}; font-family: {{ $mIsRtl ? "'Cairo', 'Amiri', 'Arial', sans-serif" : "'Arial', 'Helvetica', sans-serif" }}; direction: {{ $mIsRtl ? 'rtl' : 'ltr' }}; {{ $mAutoFit ? 'overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' : 'overflow: visible; white-space: nowrap;' }}"
                                                title="Queued Edit (Click to edit): {{ $mText }}"
                                            >
                                                <span class="inline-block select-text">{{ $mText }}</span>
                                                <span class="absolute -top-3.5 left-0 text-[8px] font-bold bg-emerald-600 text-white px-1 py-0.5 rounded shadow-xs pointer-events-none uppercase">
                                                    {{ __('tools.edit_pdf.edit_badge') }}
                                                </span>
                                            </div>
                                        @endif
                                    @endif
                                @endforeach

                                <!-- 3. Active Live Preview for Currently Selected Group/Object -->
                                @if($activeSelectionId && ($selectedGroup || $selectedObject))
                                    @php
                                        $activeItem = $selectedGroup ?? $selectedObject;
                                        $aBbox = $activeItem['bounding_box'] ?? ($activeItem['bbox'] ?? []);
                                        $axPct = max(0, min(100, (($aBbox['left'] ?? 0) / $pWidth) * 100));
                                        $ayPct = max(0, min(100, (($pHeight - ($aBbox['top'] ?? 0)) / $pHeight) * 100));
                                        $awPct = max(0.5, min(100, (($aBbox['width'] ?? 20) / $pWidth) * 100));
                                        $ahPct = max(0.5, min(100, (($aBbox['height'] ?? 10) / $pHeight) * 100));

                                        $aOrigText = (string)($activeItem['text'] ?? '');
                                        $aBaseSize = (float)($editFontSize ?? ($activeItem['font_size'] ?? 12.0));
                                        $aEffSize = $this->computeEffectiveFontSize($aBaseSize, $aOrigText, $editText, $autoFit);
                                        $aSizePx = max(5, $aEffSize * $zoomScale);
                                        $aColor = $editColor ?: '#000000';
                                        $aIsRtl = $this->isRtlText($editText, $activeItem['direction'] ?? null);
                                    @endphp
                                    <div
                                        id="active-live-preview-box"
                                        wire:key="active_preview_{{ $activeSelectionId }}"
                                        class="absolute z-30 bg-white ring-2 ring-indigo-600 rounded-xs shadow-md px-1 pointer-events-none flex items-center {{ $aIsRtl ? 'justify-end' : 'justify-start' }}"
                                        style="left: {{ $axPct }}%; top: {{ $ayPct }}%; width: {{ $awPct }}%; min-height: {{ $ahPct }}%; font-size: {{ $aSizePx }}px; line-height: 1.15; color: {{ $aColor }}; font-family: {{ $aIsRtl ? "'Cairo', 'Amiri', 'Arial', sans-serif" : "'Arial', 'Helvetica', sans-serif" }}; direction: {{ $aIsRtl ? 'rtl' : 'ltr' }}; {{ $autoFit ? 'overflow: hidden; text-overflow: ellipsis; white-space: nowrap;' : 'overflow: visible; white-space: nowrap;' }}"
                                    >
                                        <span class="inline-block select-text" x-text="$wire.editText">{{ $editText }}</span>
                                        <span class="absolute -top-3.5 left-0 text-[8px] font-bold bg-indigo-600 text-white px-1 py-0.5 rounded shadow-xs pointer-events-none uppercase tracking-wide">
                                            {{ __('tools.edit_pdf.live_preview') }}
                                        </span>
                                    </div>
                                @endif
                            @endif

                            <!-- Additive Shapes on Current Page -->
                            @foreach($addedShapes as $sId => $shape)
                                @if(($shape['page'] ?? 1) === $currentPage)
                                    @php
                                        $sX = ($shape['x_ratio'] ?? 0.1) * 100;
                                        $sY = ($shape['y_ratio'] ?? 0.1) * 100;
                                        $sW = ($shape['width_ratio'] ?? 0.2) * 100;
                                        $sH = ($shape['height_ratio'] ?? 0.05) * 100;
                                        $sType = $shape['type'] ?? 'rect';
                                    @endphp
                                    <div
                                        wire:key="shape_{{ $sId }}"
                                        class="absolute pointer-events-none z-15"
                                        style="left: {{ $sX }}%; top: {{ $sY }}%; width: {{ $sW }}%; height: {{ $sH }}%;"
                                    >
                                        @if($sType === 'highlight')
                                            <div class="w-full h-full bg-yellow-300/40 rounded-xs"></div>
                                        @elseif($sType === 'line')
                                            <div class="w-full border-b-2" style="border-color: {{ $shape['stroke_color'] ?? '#ef4444' }};"></div>
                                        @else
                                            <div class="w-full h-full border-2 rounded-xs" style="border-color: {{ $shape['stroke_color'] ?? '#2563eb' }}; background-color: {{ (!empty($shape['fill_color']) && $shape['fill_color'] !== 'none') ? $shape['fill_color'] : 'transparent' }};"></div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach

                            <!-- Additive Text Items on Current Page -->
                            @foreach($addedTexts as $tId => $txtItem)
                                @if(($txtItem['page'] ?? 1) === $currentPage)
                                    @php
                                        $tX = ($txtItem['x_ratio'] ?? 0.1) * 100;
                                        $tY = ($txtItem['y_ratio'] ?? 0.1) * 100;
                                    @endphp
                                    <div
                                        wire:key="add_txt_{{ $tId }}"
                                        class="absolute pointer-events-none z-25 font-sans whitespace-nowrap"
                                        style="left: {{ $tX }}%; top: {{ $tY }}%; font-size: {{ $txtItem['font_size'] ?? 14 }}px; color: {{ $txtItem['color'] ?? '#000000' }};"
                                    >
                                        {{ $txtItem['text'] }}
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <!-- Page Thumbnails Strip -->
                    @if($pageCount > 1 && !empty($pageThumbnails))
                        <div class="w-full mt-4 flex items-center gap-3 overflow-x-auto p-2 bg-slate-100 rounded-2xl border border-slate-200">
                            @for($p = 1; $p <= $pageCount; $p++)
                                <button
                                    wire:click="goToPage({{ $p }})"
                                    class="shrink-0 flex flex-col items-center gap-1 p-1 rounded-xl transition cursor-pointer {{ $currentPage === $p ? 'ring-2 ring-indigo-600 bg-white shadow-xs' : 'opacity-70 hover:opacity-100' }}"
                                >
                                    @if(isset($pageThumbnails[$p]))
                                        <img src="{{ $pageThumbnails[$p] }}" alt="Thumb {{ $p }}" class="h-16 w-12 object-cover rounded border border-slate-300" />
                                    @else
                                        <div class="h-16 w-12 bg-white rounded border border-slate-300 flex items-center justify-center text-[10px] text-slate-400">
                                            {{ $p }}
                                        </div>
                                    @endif
                                    <span class="text-[10px] font-semibold text-slate-700">{{ $p }}</span>
                                </button>
                            @endfor
                        </div>
                    @endif
                </div>

                <!-- Right Column: Editor Sidebar & Action Tabs -->
                <div class="lg:col-span-5 xl:col-span-4 flex flex-col gap-6">
                    <!-- Tab Selector -->
                    <div class="rounded-2xl border border-slate-200 bg-white p-1.5 flex gap-1 shadow-xs">
                        <button
                            wire:click="setActiveTab('text')"
                            class="flex-1 py-2 text-xs font-semibold rounded-xl transition cursor-pointer {{ $activeTab === 'text' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100' }}"
                        >
                            {{ __('tools.edit_pdf.tab_edit_text') }}
                        </button>
                        <button
                            wire:click="setActiveTab('add_text')"
                            class="flex-1 py-2 text-xs font-semibold rounded-xl transition cursor-pointer {{ $activeTab === 'add_text' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100' }}"
                        >
                            {{ __('tools.edit_pdf.tab_add_text') }}
                        </button>
                        <button
                            wire:click="setActiveTab('shapes')"
                            class="flex-1 py-2 text-xs font-semibold rounded-xl transition cursor-pointer {{ $activeTab === 'shapes' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100' }}"
                        >
                            {{ __('tools.edit_pdf.tab_shapes') }}
                        </button>
                        <button
                            wire:click="setActiveTab('review')"
                            class="flex-1 py-2 text-xs font-semibold rounded-xl transition cursor-pointer relative {{ $activeTab === 'review' ? 'bg-indigo-600 text-white shadow-xs' : 'text-slate-600 hover:bg-slate-100' }}"
                        >
                            {{ __('tools.edit_pdf.tab_review', ['count' => $this->totalQueuedModificationsCount]) }}
                        </button>
                    </div>

                    <!-- Tab 1: Edit Existing Text -->
                    @if($activeTab === 'text')
                        @php
                            $hasSelected = ($selectedGroupId && $selectedGroup) || ($selectedObjectId && $selectedObject);
                            $activeItem = $selectedGroup ?? $selectedObject;
                            $activeId = $selectedGroupId ?? $selectedObjectId;
                            $origText = (string)($activeItem['text'] ?? '');
                            $origFontSize = round((float)($activeItem['font_size'] ?? 12), 1);
                            $hasUnsaved = $this->hasUnsavedActiveEdit();
                        @endphp
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs flex flex-col gap-4">
                            @if($hasSelected)
                                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-bold text-slate-900">
                                            {{ $selectedGroupId ? __('tools.edit_pdf.selected_group') : __('tools.edit_pdf.selected_object') }}
                                        </h3>
                                        @if(!$this->isCurrentPageNativeEditable())
                                            <span class="text-[10px] font-semibold uppercase bg-amber-100 text-amber-800 px-1.5 py-0.5 rounded">
                                                {{ __('tools.edit_pdf.read_only_badge') }}
                                            </span>
                                        @elseif($hasUnsaved)
                                            <span class="text-[10px] font-semibold bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded">
                                                {{ __('tools.edit_pdf.unsaved_badge') }}
                                            </span>
                                        @endif
                                    </div>
                                    <span class="text-[11px] font-mono text-slate-400 truncate max-w-[150px]">{{ $activeId }}</span>
                                </div>

                                <!-- Original Text Display -->
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                        {{ __('tools.edit_pdf.original_text') }}
                                    </label>
                                    <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-700 font-medium select-text break-words">
                                        {{ $origText }}
                                    </div>
                                </div>

                                <!-- Replacement Text Input -->
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1">
                                        {{ __('tools.edit_pdf.replacement_text') }}
                                    </label>
                                    <textarea
                                        wire:model.live.debounce.100ms="editText"
                                        rows="3"
                                        @disabled(!$this->isCurrentPageNativeEditable())
                                        class="w-full rounded-xl p-3 text-sm focus:outline-hidden font-sans {{ !$this->isCurrentPageNativeEditable() ? 'bg-slate-100 text-slate-500 border border-slate-200 cursor-not-allowed' : 'border border-indigo-300 text-slate-900 focus:ring-2 focus:ring-indigo-500' }}"
                                        placeholder="{{ !$this->isCurrentPageNativeEditable() ? 'Editing disabled on this page' : 'Type replacement text here...' }}"
                                    ></textarea>
                                    <div class="mt-1 flex items-center justify-between text-[11px] text-slate-500">
                                        <span>{{ __('tools.edit_pdf.chars_count', ['count' => mb_strlen($editText)]) }}</span>
                                        <span class="font-mono text-slate-400">Orig: {{ $origFontSize }}pt</span>
                                    </div>
                                </div>

                                <!-- Live Controls: Font Size & Font Color -->
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 uppercase tracking-wider mb-1">
                                            {{ __('tools.edit_pdf.font_size') }} (pt)
                                        </label>
                                        <input
                                            type="number"
                                            wire:model.live.debounce.100ms="editFontSize"
                                            min="4"
                                            max="144"
                                            step="0.5"
                                            @disabled(!$this->isCurrentPageNativeEditable())
                                            class="w-full rounded-xl border border-slate-200 p-2 text-xs text-slate-900 focus:ring-2 focus:ring-indigo-500 focus:outline-hidden"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 uppercase tracking-wider mb-1">
                                            {{ __('tools.edit_pdf.color') }}
                                        </label>
                                        <div class="flex items-center gap-2 border border-slate-200 rounded-xl p-1 bg-white">
                                            <input
                                                type="color"
                                                wire:model.live="editColor"
                                                @disabled(!$this->isCurrentPageNativeEditable())
                                                class="h-7 w-9 rounded-lg border-0 cursor-pointer bg-transparent"
                                            />
                                            <span class="text-xs font-mono text-slate-600 uppercase">{{ $editColor }}</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Auto-fit Option -->
                                <div class="p-3 bg-indigo-50/70 border border-indigo-100 rounded-xl flex items-start gap-2.5">
                                    <input
                                        type="checkbox"
                                        id="autoFitCheck"
                                        wire:model.live="autoFit"
                                        @disabled(!$this->isCurrentPageNativeEditable())
                                        class="mt-0.5 rounded border-indigo-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer"
                                    />
                                    <label for="autoFitCheck" class="text-xs text-indigo-950 font-medium cursor-pointer">
                                        <span class="font-bold block">{{ __('tools.edit_pdf.auto_fit') }}</span>
                                        <span class="text-[11px] text-indigo-800/80 leading-relaxed block mt-0.5">
                                            {{ __('tools.edit_pdf.auto_fit_hint') }}
                                        </span>
                                    </label>
                                </div>

                                <!-- Unicode / Arabic Notice -->
                                <div class="text-[11px] text-slate-500 bg-slate-50 p-2.5 rounded-xl border border-slate-200 flex items-start gap-2">
                                    <span class="text-indigo-600 text-sm">&#10022;</span>
                                    <span>{{ __('tools.edit_pdf.fallback_font_notice') }}</span>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-2 pt-2">
                                    @if($this->isCurrentPageNativeEditable())
                                        <button
                                            wire:click="saveCurrentEdit"
                                            class="flex-1 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-xs hover:bg-indigo-700 transition cursor-pointer"
                                        >
                                            {{ __('tools.edit_pdf.save_edit') }}
                                        </button>
                                    @else
                                        <button
                                            disabled
                                            class="flex-1 rounded-xl bg-slate-200 px-4 py-2.5 text-xs font-bold text-slate-400 cursor-not-allowed"
                                            title="{{ __('tools.edit_pdf.type3_button_disabled_tooltip') }}"
                                        >
                                            {{ __('tools.edit_pdf.save_edit') }}
                                        </button>
                                    @endif
                                    <button
                                        wire:click="cancelEdit"
                                        class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition cursor-pointer"
                                    >
                                        {{ __('tools.edit_pdf.cancel_edit') }}
                                    </button>
                                </div>
                                @if(!$this->isCurrentPageNativeEditable())
                                    <div class="p-2.5 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 flex items-start gap-2">
                                        <span class="text-amber-600 font-bold shrink-0">&#9888;</span>
                                        <span class="text-[11px] leading-relaxed">
                                            {{ __('tools.edit_pdf.type3_edit_disabled_notice') }}
                                        </span>
                                    </div>
                                @endif
                            @else
                                <div class="py-8 text-center text-slate-400 flex flex-col items-center gap-3">
                                    <div class="h-12 w-12 rounded-2xl bg-indigo-50 text-indigo-500 flex items-center justify-center">
                                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" />
                                        </svg>
                                    </div>
                                    <p class="text-xs text-slate-600 max-w-xs font-medium">
                                        {{ __('tools.edit_pdf.select_text_hint') }}
                                    </p>
                                </div>
                            @endif
                        </div>

                    <!-- Tab 2: Add New Text -->
                    @elseif($activeTab === 'add_text')
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs flex flex-col gap-4">
                            <button
                                wire:click="addTextItem"
                                class="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-xs hover:bg-indigo-700 transition cursor-pointer"
                            >
                                {{ __('tools.edit_pdf.add_text_button') }}
                            </button>

                            @php
                                $pageTexts = array_filter($addedTexts, fn($t) => ($t['page'] ?? 1) === $currentPage);
                            @endphp

                            @if(empty($pageTexts))
                                <div class="py-6 text-center text-xs text-slate-400">
                                    No added text boxes on Page {{ $currentPage }}.
                                </div>
                            @else
                                <div class="space-y-3">
                                    @foreach($pageTexts as $tId => $item)
                                        <div wire:key="panel_txt_{{ $tId }}" class="p-3 rounded-xl border border-slate-200 bg-slate-50 flex flex-col gap-2">
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-bold text-slate-700">Text Item</span>
                                                <button wire:click="removeTextItem('{{ $tId }}')" class="text-rose-600 text-xs font-semibold hover:underline cursor-pointer">
                                                    &#10005;
                                                </button>
                                            </div>
                                            <input
                                                type="text"
                                                wire:change="updateTextItem('{{ $tId }}', { text: $event.target.value })"
                                                value="{{ $item['text'] }}"
                                                class="w-full rounded-lg border border-slate-300 p-2 text-xs bg-white"
                                            />
                                            <div class="flex items-center gap-2 text-xs">
                                                <label class="text-slate-500">Size:</label>
                                                <input
                                                    type="number"
                                                    wire:change="updateTextItem('{{ $tId }}', { font_size: $event.target.value })"
                                                    value="{{ $item['font_size'] }}"
                                                    min="8" max="72"
                                                    class="w-16 rounded border border-slate-300 p-1 text-xs bg-white"
                                                />
                                                <label class="text-slate-500 ml-2">Color:</label>
                                                <input
                                                    type="color"
                                                    wire:change="updateTextItem('{{ $tId }}', { color: $event.target.value })"
                                                    value="{{ $item['color'] }}"
                                                    class="h-6 w-8 rounded border border-slate-300 cursor-pointer"
                                                />
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                    <!-- Tab 3: Shapes & Annotations -->
                    @elseif($activeTab === 'shapes')
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs flex flex-col gap-4">
                            <div class="grid grid-cols-3 gap-2">
                                <button wire:click="addShape('rect')" class="py-2 px-2 text-center rounded-xl border border-indigo-200 bg-indigo-50/70 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold transition cursor-pointer">
                                    {{ __('tools.edit_pdf.add_rect_button') }}
                                </button>
                                <button wire:click="addShape('line')" class="py-2 px-2 text-center rounded-xl border border-indigo-200 bg-indigo-50/70 hover:bg-indigo-100 text-indigo-700 text-xs font-semibold transition cursor-pointer">
                                    {{ __('tools.edit_pdf.add_line_button') }}
                                </button>
                                <button wire:click="addShape('highlight')" class="py-2 px-2 text-center rounded-xl border border-amber-200 bg-amber-50/70 hover:bg-amber-100 text-amber-700 text-xs font-semibold transition cursor-pointer">
                                    {{ __('tools.edit_pdf.add_highlight_button') }}
                                </button>
                            </div>

                            @php
                                $pageShapes = array_filter($addedShapes, fn($s) => ($s['page'] ?? 1) === $currentPage);
                            @endphp

                            @if(empty($pageShapes))
                                <div class="py-6 text-center text-xs text-slate-400">
                                    No shapes on Page {{ $currentPage }}.
                                </div>
                            @else
                                <div class="space-y-3">
                                    @foreach($pageShapes as $sId => $shape)
                                        <div wire:key="panel_shp_{{ $sId }}" class="p-3 rounded-xl border border-slate-200 bg-slate-50 flex items-center justify-between text-xs">
                                            <div class="flex items-center gap-2">
                                                <span class="font-bold capitalize text-slate-800">{{ $shape['type'] }}</span>
                                                <span class="text-[11px] text-slate-500">Page {{ $shape['page'] }}</span>
                                            </div>
                                            <button wire:click="removeShape('{{ $sId }}')" class="text-rose-600 font-semibold hover:underline cursor-pointer">
                                                &#10005;
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                    <!-- Tab 4: Review Changes -->
                    @elseif($activeTab === 'review')
                        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs flex flex-col gap-4">
                            <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                                <h3 class="text-sm font-bold text-slate-900">{{ __('tools.edit_pdf.edits_manifest_title') }}</h3>
                                @if(!empty($editManifest) || !empty($addedTexts) || !empty($addedShapes))
                                    <button wire:click="clearAllEdits" class="text-xs text-rose-600 font-semibold hover:underline cursor-pointer">
                                        Clear All
                                    </button>
                                @endif
                            </div>

                            @if(empty($editManifest) && empty($addedTexts) && empty($addedShapes) && !$this->hasUnsavedActiveEdit())
                                <div class="py-8 text-center text-xs text-slate-400">
                                    {{ __('tools.edit_pdf.no_edits_queued') }}
                                </div>
                            @else
                                <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                                    @if($this->hasUnsavedActiveEdit())
                                        @php
                                            $activeItem = $selectedGroup ?? $selectedObject;
                                            $activeOrig = (string)($activeItem['text'] ?? '');
                                        @endphp
                                        <div class="p-3 rounded-xl border border-indigo-200 bg-indigo-50/50 flex flex-col gap-1 text-xs">
                                            <div class="flex items-center justify-between">
                                                <span class="font-bold text-indigo-800">Page {{ $currentPage }} &bull; Current Text Edit</span>
                                                <span class="text-[10px] font-semibold bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded">
                                                    {{ __('tools.edit_pdf.unsaved_badge') }}
                                                </span>
                                            </div>
                                            <div class="line-through text-slate-400 text-[11px]">{{ $activeOrig }}</div>
                                            <div class="font-semibold text-indigo-950" x-text="localText">{{ $editText }}</div>
                                        </div>
                                    @endif
                                    <!-- In-place text replacements -->
                                    @foreach($editManifest as $mId => $item)
                                        <div wire:key="review_m_{{ $mId }}" class="p-3 rounded-xl border border-emerald-200 bg-emerald-50/50 flex flex-col gap-1 text-xs">
                                            <div class="flex items-center justify-between">
                                                <span class="font-bold text-emerald-800">Page {{ $item['page'] }} &bull; Text Edit</span>
                                                <button wire:click="removeEdit('{{ $mId }}')" class="text-rose-600 font-bold hover:underline cursor-pointer">
                                                    &#10005;
                                                </button>
                                            </div>
                                            <div class="line-through text-slate-400 text-[11px]">{{ $item['target_text'] }}</div>
                                            <div class="font-semibold text-emerald-900">{{ $item['replacement_text'] }}</div>
                                        </div>
                                    @endforeach

                                    <!-- Added texts -->
                                    @foreach($addedTexts as $tId => $item)
                                        <div wire:key="review_t_{{ $tId }}" class="p-2.5 rounded-xl border border-indigo-200 bg-indigo-50/50 flex items-center justify-between text-xs">
                                            <div>
                                                <span class="font-bold text-indigo-800">Page {{ $item['page'] }} &bull; Added Text:</span>
                                                <span class="text-slate-700 ml-1">&ldquo;{{ $item['text'] }}&rdquo;</span>
                                            </div>
                                            <button wire:click="removeTextItem('{{ $tId }}')" class="text-rose-600 font-bold hover:underline cursor-pointer">
                                                &#10005;
                                            </button>
                                        </div>
                                    @endforeach

                                    <!-- Added shapes -->
                                    @foreach($addedShapes as $sId => $shape)
                                        <div wire:key="review_s_{{ $sId }}" class="p-2.5 rounded-xl border border-amber-200 bg-amber-50/50 flex items-center justify-between text-xs">
                                            <div>
                                                <span class="font-bold text-amber-800">Page {{ $shape['page'] }} &bull; Shape:</span>
                                                <span class="capitalize text-slate-700 ml-1">{{ $shape['type'] }}</span>
                                            </div>
                                            <button wire:click="removeShape('{{ $sId }}')" class="text-rose-600 font-bold hover:underline cursor-pointer">
                                                &#10005;
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Process & Save Card -->
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs flex flex-col gap-3">
                        <div class="flex items-center justify-between text-xs font-semibold text-slate-600">
                            <span>Total Queued Modifications:</span>
                            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 font-mono font-bold text-slate-900">
                                {{ $this->totalQueuedModificationsCount }}
                            </span>
                        </div>

                        <button
                            wire:click="process"
                            wire:loading.attr="disabled"
                            wire:target="process"
                            @disabled($jobStatus === 'processing' || (empty($editManifest) && empty($addedTexts) && empty($addedShapes)))
                            class="w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-bold text-white shadow-md hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer flex items-center justify-center gap-2"
                        >
                            <svg wire:loading.remove wire:target="process" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg wire:loading wire:target="process" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            <span wire:loading.remove wire:target="process">{{ __('tools.edit_pdf.action') }}</span>
                            <span wire:loading wire:target="process">{{ __('ui.status_processing') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
