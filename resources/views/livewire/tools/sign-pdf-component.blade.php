<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
    <!-- Header -->
    <div class="text-center mb-6">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.sign_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-2xl mx-auto">{{ __('tools.sign_pdf.desc') }}</p>
    </div>

    <!-- Legal / Electronic Signature Notice -->
    <div class="max-w-3xl mx-auto mb-6 rounded-2xl border border-blue-200 bg-blue-50/60 p-4 text-sm text-blue-900 flex items-start gap-3">
        <svg class="w-5 h-5 text-blue-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <div>
            <span class="font-semibold">{{ __('tools.sign_pdf.disclaimer_title') }}:</span>
            <span>{{ __('tools.sign_pdf.disclaimer') }}</span>
        </div>
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
            <p class="mt-2 text-sm text-slate-500">{{ __('tools.sign_pdf.processing_hint') }}</p>
            <div class="mt-6 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full w-full bg-gradient-to-r from-indigo-500 to-violet-500 animate-pulse rounded-full"></div>
            </div>
        </div>

    <!-- Completed State -->
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
            <button wire:click="resetTool" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition cursor-pointer">
                &larr; {{ __('ui.try_another') }}
            </button>
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
        @else
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                <!-- Left Column: Document Preview & Interactive Signing Canvas -->
                <div class="lg:col-span-7 xl:col-span-8 flex flex-col items-center">
                    <!-- Page Navigation Controls -->
                    <div class="w-full mb-4 flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-3 shadow-xs">
                        <div class="flex items-center gap-2">
                            <button
                                wire:click="goToPage({{ max(1, $currentPage - 1) }})"
                                @disabled($currentPage <= 1)
                                class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer"
                            >
                                &larr; {{ __('tools.sign_pdf.prev_page') }}
                            </button>
                            <span class="text-xs font-medium text-slate-600">
                                {{ __('tools.sign_pdf.page') }} <span class="font-bold text-slate-900">{{ $currentPage }}</span> {{ __('tools.sign_pdf.of') }} {{ $pageCount }}
                            </span>
                            <button
                                wire:click="goToPage({{ min($pageCount, $currentPage + 1) }})"
                                @disabled($currentPage >= $pageCount)
                                class="inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 disabled:opacity-40 disabled:cursor-not-allowed transition cursor-pointer"
                            >
                                {{ __('tools.sign_pdf.next_page') }} &rarr;
                            </button>
                        </div>

                        <div class="text-xs text-slate-500 font-medium truncate max-w-[200px]" title="{{ $filename }}">
                            {{ $filename }} ({{ $fileSizeFormatted }})
                        </div>
                    </div>

                    <!-- PDF Page Container (Exact Aspect Ratio, Crisp Preview) -->
                    <div
                        id="pdf-page-container"
                        class="relative w-full max-w-2xl rounded-2xl border border-slate-300 bg-white shadow-md overflow-hidden select-none mx-auto"
                    >
                        @if(!empty($pagePreviews[$currentPage]))
                            <img
                                src="{{ $pagePreviews[$currentPage] }}"
                                alt="PDF Page {{ $currentPage }}"
                                class="w-full h-auto block select-none pointer-events-none"
                            />
                        @else
                            <!-- Placeholder while Poppler generates high-DPI preview -->
                            <div class="w-full aspect-[1/1.414] bg-gradient-to-b from-white to-slate-50 flex flex-col items-center justify-center p-8 text-center">
                                <svg class="w-16 h-16 text-slate-300 mb-3 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <span class="text-sm font-semibold text-slate-700">{{ __('tools.sign_pdf.page') }} {{ $currentPage }}</span>
                                <span class="text-xs text-slate-400 mt-1">{{ __('tools.sign_pdf.preview_doc_ready') }}</span>
                            </div>
                        @endif

                        <!-- Placed Signatures on Current Page -->
                        @foreach($placements as $p)
                            @if((int)$p['page'] === (int)$currentPage)
                                <div
                                    wire:key="placement-{{ $p['id'] }}"
                                    x-data="pdfPlacementItem({
                                        id: '{{ $p['id'] }}',
                                        x: {{ $p['x_ratio'] * 100 }},
                                        y: {{ $p['y_ratio'] * 100 }},
                                        w: {{ $p['width_ratio'] * 100 }},
                                        h: {{ $p['height_ratio'] * 100 }},
                                        rot: {{ $p['rotation'] ?? 0 }}
                                    })"
                                    class="absolute cursor-move border-2 border-indigo-500 bg-indigo-50/20 rounded group transition-shadow hover:shadow-lg hover:border-indigo-600 select-none z-10 touch-none"
                                    :style="`left: ${x}%; top: ${y}%; width: ${w}%; height: ${h}%; transform: rotate(${rot}deg);`"
                                    @pointerdown="startDrag($event)"
                                >
                                    <img
                                        src="{{ $p['signature_data'] }}"
                                        alt="Signature"
                                        class="w-full h-full object-contain pointer-events-none p-1 select-none"
                                    />

                                    <!-- Delete Button -->
                                    <button
                                        type="button"
                                        wire:click.stop="removePlacement('{{ $p['id'] }}')"
                                        class="action-control absolute -top-3 -right-3 h-6 w-6 rounded-full bg-rose-600 text-white flex items-center justify-center text-xs font-bold hover:bg-rose-700 shadow transition cursor-pointer z-20"
                                        title="{{ __('tools.sign_pdf.remove_signature') }}"
                                    >
                                        &times;
                                    </button>

                                    <!-- Rotate Button -->
                                    <button
                                        type="button"
                                        wire:click.stop="rotatePlacement('{{ $p['id'] }}', 90)"
                                        class="action-control absolute -top-3 -left-3 h-6 w-6 rounded-full bg-indigo-600 text-white flex items-center justify-center text-xs font-bold hover:bg-indigo-700 shadow transition cursor-pointer z-20"
                                        title="{{ __('tools.sign_pdf.rotate_btn') }}"
                                    >
                                        &#x21bb;
                                    </button>

                                    <!-- Resize Handle -->
                                    <div
                                        @pointerdown.stop="startResize($event)"
                                        class="action-control absolute -bottom-2 -right-2 h-4 w-4 bg-indigo-600 rounded-full border-2 border-white shadow cursor-nwse-resize z-20 flex items-center justify-center hover:scale-125 transition-transform"
                                        title="{{ __('tools.sign_pdf.resize_hint') }}"
                                    ></div>

                                    <!-- Hover Tooltip -->
                                    <div class="absolute -bottom-5 left-1/2 -translate-x-1/2 bg-slate-900/80 text-white text-[10px] px-1.5 py-0.5 rounded opacity-0 group-hover:opacity-100 transition whitespace-nowrap pointer-events-none">
                                        {{ __('tools.sign_pdf.drag_to_position') }}
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    <!-- Placement count badge -->
                    <div class="mt-3 text-xs text-slate-500">
                        {{ count(array_filter($placements, fn($p) => (int)$p['page'] === (int)$currentPage)) }} {{ __('tools.sign_pdf.signatures_on_page') }}
                    </div>
                </div>

                <!-- Right Column: Signature Tools & Document Placements -->
                <div class="lg:col-span-5 xl:col-span-4 space-y-6">
                    <!-- Signature Creation Card -->
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-bold text-slate-900 mb-3">{{ __('tools.sign_pdf.create_signature') }}</h2>

                        <!-- Method Tabs -->
                        <div class="flex items-center rounded-xl bg-slate-100 p-1 mb-5">
                            <button
                                type="button"
                                wire:click="setSignatureType('draw')"
                                class="flex-1 py-1.5 px-3 rounded-lg text-xs font-semibold transition cursor-pointer {{ $activeSignatureType === 'draw' ? 'bg-white text-indigo-600 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}"
                            >
                                {{ __('tools.sign_pdf.tab_draw') }}
                            </button>
                            <button
                                type="button"
                                wire:click="setSignatureType('upload')"
                                class="flex-1 py-1.5 px-3 rounded-lg text-xs font-semibold transition cursor-pointer {{ $activeSignatureType === 'upload' ? 'bg-white text-indigo-600 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}"
                            >
                                {{ __('tools.sign_pdf.tab_upload') }}
                            </button>
                            <button
                                type="button"
                                wire:click="setSignatureType('type')"
                                class="flex-1 py-1.5 px-3 rounded-lg text-xs font-semibold transition cursor-pointer {{ $activeSignatureType === 'type' ? 'bg-white text-indigo-600 shadow-xs' : 'text-slate-600 hover:text-slate-900' }}"
                            >
                                {{ __('tools.sign_pdf.tab_type') }}
                            </button>
                        </div>

                        <!-- TAB 1: DRAW SIGNATURE -->
                        @if($activeSignatureType === 'draw')
                            <div
                                x-data="pdfDrawSignature({
                                    emptyError: @js(__('tools.sign_pdf.draw_error_empty'))
                                })"
                                class="space-y-4"
                            >
                                <div class="relative border-2 border-dashed border-slate-300 rounded-2xl bg-slate-50/50 p-1 touch-none overflow-hidden">
                                    <canvas
                                        x-ref="sigCanvas"
                                        class="w-full h-40 bg-transparent cursor-crosshair block touch-none"
                                        @pointerdown="start($event)"
                                        @pointermove="move($event)"
                                        @pointerup="end($event)"
                                        @pointercancel="end($event)"
                                    ></canvas>
                                    <div class="absolute bottom-2 left-3 text-[11px] text-slate-400 select-none pointer-events-none">
                                        {{ __('tools.sign_pdf.draw_canvas_hint') }}
                                    </div>
                                </div>

                                <template x-if="errorMessage">
                                    <p class="text-xs text-rose-600 font-medium" x-text="errorMessage"></p>
                                </template>

                                <div class="flex items-center justify-between gap-3">
                                    <button
                                        type="button"
                                        @click="clear()"
                                        class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition cursor-pointer"
                                    >
                                        {{ __('tools.sign_pdf.btn_clear') }}
                                    </button>
                                    <button
                                        type="button"
                                        @click="save()"
                                        :disabled="!hasContent"
                                        class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition cursor-pointer"
                                    >
                                        {{ __('tools.sign_pdf.btn_add_to_page') }} &rarr;
                                    </button>
                                </div>
                            </div>

                        <!-- TAB 2: UPLOAD SIGNATURE IMAGE -->
                        @elseif($activeSignatureType === 'upload')
                            <div class="space-y-4">
                                <div class="border-2 border-dashed border-slate-300 rounded-2xl bg-slate-50/50 p-5 text-center">
                                    <input
                                        type="file"
                                        wire:model="signatureUpload"
                                        accept="image/png,image/jpeg,image/webp"
                                        id="sig-upload-input"
                                        class="hidden"
                                    />
                                    <label for="sig-upload-input" class="cursor-pointer block">
                                        <svg class="mx-auto h-10 w-10 text-slate-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <span class="text-xs font-semibold text-indigo-600 hover:text-indigo-700">{{ __('tools.sign_pdf.upload_click_select') }}</span>
                                        <p class="text-[11px] text-slate-400 mt-1">{{ __('tools.sign_pdf.upload_formats') }}</p>
                                    </label>
                                </div>

                                @if($activeSignaturePreview)
                                    <div class="rounded-xl border border-slate-200 bg-white p-3 text-center">
                                        <span class="text-[11px] font-semibold text-slate-500 mb-2 block">{{ __('tools.sign_pdf.preview_label') }}</span>
                                        <img src="{{ $activeSignaturePreview }}" alt="Uploaded Signature" class="max-h-20 mx-auto object-contain" />
                                    </div>
                                @endif
                            </div>

                        <!-- TAB 3: TYPE SIGNATURE WITH STYLE SELECTOR -->
                        @elseif($activeSignatureType === 'type')
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-1">{{ __('tools.sign_pdf.type_name_label') }}</label>
                                    <input
                                        type="text"
                                        wire:model.live.debounce.300ms="typedName"
                                        placeholder="{{ __('tools.sign_pdf.type_name_placeholder') }}"
                                        class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                    />
                                </div>

                                <!-- Style Choices (4 distinct signature fonts) -->
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700 mb-2">{{ __('tools.sign_pdf.select_style') }}</label>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                        @foreach($this->getAvailableStyles() as $sKey => $sInfo)
                                            @php
                                                $isSelected = ($typedStyle === $sKey);
                                                $displayName = app()->getLocale() === 'ar' ? $sInfo['name_ar'] : $sInfo['name_en'];
                                            @endphp
                                            <button
                                                type="button"
                                                wire:click="setTypedStyle('{{ $sKey }}')"
                                                class="relative text-left rtl:text-right rounded-xl border p-3 transition cursor-pointer flex flex-col justify-between {{ $isSelected ? 'border-indigo-600 bg-indigo-50/50 shadow-xs ring-1 ring-indigo-600' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/50' }}"
                                            >
                                                <div class="flex items-center justify-between w-full mb-1">
                                                    <span class="text-[11px] font-bold {{ $isSelected ? 'text-indigo-900' : 'text-slate-600' }}">{{ $displayName }}</span>
                                                    @if($isSelected)
                                                        <span class="h-4 w-4 rounded-full bg-indigo-600 text-white flex items-center justify-center text-[10px]">&check;</span>
                                                    @endif
                                                </div>
                                                <div
                                                    class="text-xl py-1 text-slate-900 select-none truncate w-full"
                                                    style="font-family: {{ $sInfo['css'] }};"
                                                >
                                                    {{ $typedName ?: 'John Doe' }}
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                @if(trim($typedName) !== '')
                                    <button
                                        type="button"
                                        wire:click="applyTypedSignature"
                                        class="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white hover:bg-indigo-700 transition cursor-pointer shadow-sm"
                                    >
                                        {{ __('tools.sign_pdf.btn_add_to_page') }} &rarr;
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>

                    <!-- Placed Signatures Summary Card -->
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-bold text-slate-900">
                                {{ __('tools.sign_pdf.placed_signatures') }} ({{ count($placements) }})
                            </h2>
                            @if(!empty($placements))
                                <button
                                    type="button"
                                    wire:click="clearPlacements"
                                    class="text-xs text-rose-600 hover:text-rose-700 font-semibold cursor-pointer"
                                >
                                    {{ __('tools.sign_pdf.btn_clear_all') }}
                                </button>
                            @endif
                        </div>

                        @if(empty($placements))
                            <p class="text-xs text-slate-500 text-center py-4">{{ __('tools.sign_pdf.no_signatures_yet') }}</p>
                        @else
                            <div class="space-y-2.5 max-h-56 overflow-y-auto pr-1">
                                @foreach($placements as $idx => $item)
                                    <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50/50 p-2.5 text-xs">
                                        <div class="flex items-center gap-3">
                                            <div class="h-8 w-12 bg-white rounded border border-slate-200 flex items-center justify-center overflow-hidden">
                                                <img src="{{ $item['signature_data'] }}" alt="Sig" class="max-h-6 max-w-full object-contain" />
                                            </div>
                                            <div>
                                                <span class="font-bold text-slate-900">{{ __('tools.sign_pdf.page') }} {{ $item['page'] }}</span>
                                                <span class="text-slate-400 mx-1">&bull;</span>
                                                <span class="text-slate-500 uppercase text-[10px]">{{ $item['signature_type'] }}</span>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                wire:click="goToPage({{ $item['page'] }})"
                                                class="text-indigo-600 hover:text-indigo-800 font-semibold cursor-pointer"
                                            >
                                                {{ __('tools.sign_pdf.view_on_page') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="removePlacement('{{ $item['id'] }}')"
                                                class="text-rose-600 hover:text-rose-800 cursor-pointer ml-1"
                                                title="{{ __('tools.sign_pdf.remove_signature') }}"
                                            >
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <!-- Action Buttons -->
                        <div class="mt-6 pt-4 border-t border-slate-100 space-y-3">
                            <button
                                type="button"
                                wire:click="process"
                                @disabled(empty($placements))
                                class="w-full flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 py-3.5 px-4 text-sm font-bold text-white shadow-md hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition cursor-pointer"
                            >
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                </svg>
                                {{ __('tools.sign_pdf.btn_process_sign') }}
                            </button>

                            <button
                                type="button"
                                wire:click="resetTool"
                                class="w-full text-center text-xs text-slate-500 hover:text-slate-800 py-1 transition cursor-pointer"
                            >
                                {{ __('ui.try_another') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
