@props(['job', 'processedFiles'])

@php
    $meta = $job->input_metadata ?? [];
    $resultMeta = array_merge($meta, $job->result_metadata ?? []);
    $toolVal = $job->tool_type instanceof \App\Enums\ToolType ? $job->tool_type->value : (string) $job->tool_type;
    $isImageTool = in_array($toolVal, ['compress-image', 'resize-image', 'convert-image', 'compress_image', 'resize_image', 'convert_image'], true);
    $isPdfCompress = in_array($toolVal, ['compress-pdf', 'compress_pdf'], true);
    $isJpgToPdf = in_array($toolVal, ['jpg-to-pdf', 'jpg_to_pdf'], true);
    $isBgRemoval = in_array($toolVal, ['remove-background', 'remove_background'], true);
    $isBgChange = in_array($toolVal, ['change-background', 'change_background'], true);

    $isDeep = !empty($meta['is_deep']) || ($isPdfCompress && app(\App\Services\System\SystemBinaryDetector::class)->hasGhostscript());
    $isTargetMode = !empty($meta['target_size_bytes']);

    // Strict truthfulness: Target is ONLY achieved if target_achieved is true OR processed_size <= target_size
    $firstFile = $processedFiles->first();
    $actualProcessedBytes = $firstFile ? $firstFile->processed_size_bytes : PHP_INT_MAX;
    $targetBytes = (int) ($meta['target_size_bytes'] ?? 0);
    $targetAchieved = !empty($meta['target_achieved']) || ($isTargetMode && $actualProcessedBytes <= $targetBytes);

    $explanation = $meta['target_explanation'] ?? null;
    $notice = $meta['notice'] ?? null;
@endphp

<div class="mx-auto max-w-2xl">
    <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/40">
        <!-- Header -->
        <div class="border-b border-slate-100 bg-gradient-to-r from-emerald-500/10 via-teal-500/10 to-indigo-500/10 px-6 py-6 text-center">
            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-500 text-white shadow-lg shadow-emerald-500/30">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <h2 class="text-2xl font-bold tracking-tight text-slate-900">{{ __('ui.status_completed') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ $job->tool_type->label() }}</p>
        </div>

        <!-- Body -->
        <div class="p-6 sm:p-8 space-y-6">
            <!-- Engine Attribution Callouts (Strictly Isolated & Accurate) -->
            @if($isImageTool)
                <div class="rounded-xl p-4 bg-indigo-50/70 border border-indigo-200 text-indigo-900">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 text-lg">🖼️</span>
                        <div class="text-xs">
                            <span class="font-bold">{{ __('ui.engine_used') }}:</span>
                            <span class="font-semibold">{{ __('ui.engine_image_gd') }}</span>
                        </div>
                    </div>
                </div>
            @elseif($isJpgToPdf)
                <div class="rounded-xl p-4 bg-slate-50 border border-slate-200 text-slate-800">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 text-lg">📄</span>
                        <div class="text-xs">
                            <span class="font-bold">{{ __('ui.engine_used') }}:</span>
                            <span class="font-semibold">{{ __('ui.engine_pdf_fpdi') }}</span>
                        </div>
                    </div>
                </div>
            @elseif($isPdfCompress)
                <div class="rounded-xl p-4 {{ $isDeep ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-amber-50 border border-amber-200 text-amber-800' }}">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 text-lg">{{ $isDeep ? '🚀' : 'ℹ️' }}</span>
                        <div class="text-xs">
                            <span class="font-bold">{{ __('ui.engine_used') }}:</span>
                            <span class="font-semibold">{{ $isDeep ? __('ui.deep_compression') : __('ui.limited_optimization') }}</span>
                            @if(!$isDeep)
                                <p class="mt-1 text-amber-700 leading-relaxed">{{ __('ui.limited_opt_notice') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @elseif($isBgRemoval || $isBgChange)
                @php
                    $isAi = !empty($resultMeta['is_ai']);
                    $engineId = $resultMeta['engine'] ?? '';
                    $isColorSampling = $engineId === 'color_sampling';
                @endphp
                <div class="rounded-xl p-4 {{ $isAi ? 'bg-violet-50/80 border border-violet-200 text-violet-950' : 'bg-indigo-50/70 border border-indigo-200 text-indigo-950' }}">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 text-lg">{{ $isAi ? '✨' : ($isColorSampling ? '🎯' : '🎨') }}</span>
                        <div class="text-xs">
                            <span class="font-bold">{{ __('ui.engine_used') }}:</span>
                            <span class="font-semibold">{{ $isAi ? __('ui.engine_bg_ai') : ($isColorSampling ? __('ui.engine_bg_color') : __('ui.engine_bg_advanced')) }}</span>
                            <p class="mt-0.5 {{ $isAi ? 'text-violet-700' : 'text-indigo-700' }} leading-relaxed">
                                {{ $isAi ? __('ui.engine_bg_ai_desc') : ($isColorSampling ? __('ui.engine_bg_color_desc') : __('ui.engine_bg_advanced_desc')) }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Informational Notice (if compressed output could not be reduced further) -->
            @if($notice)
                <div class="rounded-xl p-4 bg-amber-50 border border-amber-200 text-amber-900 text-xs flex items-start gap-2.5">
                    <span class="text-base">ℹ️</span>
                    <p class="leading-relaxed">{{ $notice }}</p>
                </div>
            @endif

            <!-- Target Size Result Metrics Card -->
            @if($isTargetMode)
                <div class="rounded-2xl border {{ $targetAchieved ? 'border-emerald-200 bg-emerald-50/40' : 'border-amber-200 bg-amber-50/40' }} p-5 space-y-4">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div class="flex items-center gap-2">
                            <span class="text-base">{{ $targetAchieved ? '🎯' : '⚠️' }}</span>
                            <span class="text-sm font-bold text-slate-800">{{ __('ui.target_status') }}:</span>
                            @if($targetAchieved)
                                <span class="inline-flex items-center rounded-md bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800">
                                    {{ __('ui.target_achieved') }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-md bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800">
                                    {{ __('ui.closest_result') }}
                                </span>
                            @endif
                        </div>

                        @if(!empty($meta['target_iterations']))
                            <span class="text-xs text-slate-500">
                                {{ __('ui.target_iterations') }}: <span class="font-semibold text-slate-700">{{ $meta['target_iterations'] }}</span>
                            </span>
                        @endif
                    </div>

                    <!-- Clear truthful explanation when target cannot be reached -->
                    @if(!$targetAchieved)
                        <p class="text-xs text-amber-900 leading-relaxed font-medium bg-amber-100/60 rounded-xl p-3 border border-amber-200/80">
                            {{ $explanation ?? __('ui.closest_result_desc') }}
                        </p>
                    @endif

                    <!-- Changes in Quality / Dimensions if applicable -->
                    @if(!empty($meta['dimension_changed']) && !empty($meta['dimensions']) && !empty($meta['original_dimensions']))
                        <div class="text-xs text-slate-600 bg-white/80 rounded-xl p-2.5 border border-slate-100 flex items-center justify-between">
                            <span class="font-medium text-slate-500">Dimensions:</span>
                            <span class="font-semibold text-slate-800">
                                {{ $meta['original_dimensions']['width'] }}×{{ $meta['original_dimensions']['height'] }} px &rarr; {{ $meta['dimensions']['width'] }}×{{ $meta['dimensions']['height'] }} px
                            </span>
                        </div>
                    @endif

                    <!-- Target Metrics Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                        <div class="rounded-xl bg-white p-3 border border-slate-100 shadow-xs">
                            <span class="block text-slate-400 font-medium">{{ __('ui.target_size') }}</span>
                            <span class="mt-1 block font-bold text-slate-800">{{ number_format($meta['target_size_bytes'] / 1024, 1) }} KB</span>
                        </div>
                        @if($firstFile)
                            <div class="rounded-xl bg-white p-3 border border-slate-100 shadow-xs">
                                <span class="block text-slate-400 font-medium">{{ __('ui.original_size') }}</span>
                                <span class="mt-1 block font-bold text-slate-800">{{ number_format($firstFile->original_size_bytes / 1024, 1) }} KB</span>
                            </div>
                            <div class="rounded-xl bg-white p-3 border border-slate-100 shadow-xs">
                                <span class="block text-slate-400 font-medium">{{ __('ui.processed_size') }}</span>
                                <span class="mt-1 block font-bold {{ $targetAchieved ? 'text-emerald-700' : 'text-slate-800' }}">{{ number_format($firstFile->processed_size_bytes / 1024, 1) }} KB</span>
                            </div>
                            <div class="rounded-xl bg-white p-3 border border-slate-100 shadow-xs">
                                <span class="block text-slate-400 font-medium">{{ __('ui.savings') }}</span>
                                <span class="mt-1 block font-bold text-emerald-600">-{{ $firstFile->savingsPercent() }}%</span>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Processed Files List -->
            <div class="space-y-3">
                @foreach($processedFiles as $file)
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-xl border border-slate-100 bg-slate-50/70 p-4 transition hover:bg-slate-50">
                        <div class="flex items-center gap-3.5 min-w-0">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h4 class="truncate font-semibold text-slate-800 text-sm" title="{{ $file->original_name }}">{{ $file->original_name }}</h4>
                                <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500">
                                    <span>{{ number_format($file->processed_size_bytes / 1024, 1) }} KB</span>
                                    @if($file->original_size_bytes > $file->processed_size_bytes)
                                        <span class="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 font-bold text-emerald-700">
                                            -{{ $file->savingsPercent() }}%
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Actions (Share + Download) -->
                        <div class="flex items-center gap-2">
                            <!-- Share via QR Button -->
                            <button wire:click="toggleShareSection('{{ $file->id }}')"
                                    type="button"
                                    class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50/60 px-3.5 py-2.5 text-sm font-semibold text-indigo-700 shadow-xs hover:bg-indigo-100 hover:text-indigo-800 transition active:scale-95 cursor-pointer"
                                    title="{{ __('tools.share.create_button') }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                                </svg>
                                <span>{{ __('tools.share.create_button') }}</span>
                            </button>

                            <!-- Download Button -->
                            <a href="{{ route('file.download', ['token' => $job->download_token, 'fileId' => $file->id]) }}"
                               class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-indigo-600/20 hover:bg-indigo-700 transition active:scale-95">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                <span>{{ __('ui.download') }}</span>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Temporary QR Share Section -->
            @if(!empty($showShareSection))
                <div class="rounded-2xl border border-indigo-100 bg-gradient-to-b from-indigo-50/40 to-slate-50/50 p-5 sm:p-6 space-y-4 shadow-xs">
                    <!-- Share Header -->
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-md shadow-indigo-600/20">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-slate-800">{{ __('tools.share.title') }}</h3>
                                <p class="text-xs text-slate-500">{{ __('tools.share.desc') }}</p>
                            </div>
                        </div>
                        <button wire:click="closeShareSection"
                                type="button"
                                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200/60 hover:text-slate-600 transition cursor-pointer"
                                title="{{ __('tools.share.close') }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    @if(!empty($shareMessage))
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 p-3 text-xs text-emerald-800 font-medium">
                            {{ $shareMessage }}
                        </div>
                    @endif

                    @if(empty($activeShareUrl))
                        <!-- Share Options Configuration -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">
                                    {{ __('tools.share.expiration') }}
                                </label>
                                <select wire:model="shareExpirationMinutes"
                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <option value="5">{{ __('tools.share.expiration_5m') }}</option>
                                    <option value="15">{{ __('tools.share.expiration_15m') }}</option>
                                    <option value="30">{{ __('tools.share.expiration_30m') }}</option>
                                    <option value="60">{{ __('tools.share.expiration_60m') }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-700 mb-1">
                                    {{ __('tools.share.max_downloads') }}
                                </label>
                                <select wire:model="shareMaxDownloads"
                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 shadow-xs focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500">
                                    <option value="">{{ __('tools.share.unlimited_downloads') }}</option>
                                    <option value="1">{{ __('tools.share.downloads_1') }}</option>
                                    <option value="3">{{ __('tools.share.downloads_3') }}</option>
                                    <option value="5">{{ __('tools.share.downloads_5') }}</option>
                                    <option value="10">{{ __('tools.share.downloads_10') }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="pt-2">
                            <button wire:click="generateShare"
                                    type="button"
                                    class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition active:scale-98 cursor-pointer">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                </svg>
                                <span>{{ __('tools.share.generate_link') }}</span>
                            </button>
                        </div>
                    @else
                        <!-- Active Share Display (QR + URL) -->
                        <div class="flex flex-col sm:flex-row items-center gap-5 pt-2 bg-white p-4 rounded-xl border border-indigo-100">
                            <!-- QR Code SVG -->
                            <div class="p-2 bg-white rounded-lg border border-slate-200 shrink-0 shadow-xs">
                                {!! $activeShareQrSvg !!}
                            </div>

                            <!-- URL and metadata -->
                            <div class="min-w-0 flex-1 space-y-2 text-center sm:text-left">
                                <span class="text-xs font-bold text-indigo-700 uppercase tracking-wider block">
                                    {{ __('tools.share.active_share') }}
                                </span>
                                <div class="flex items-center gap-2">
                                    <input type="text"
                                           readonly
                                           value="{{ $activeShareUrl }}"
                                           class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-1.5 text-xs font-mono text-slate-700 select-all" />
                                    <button onclick="navigator.clipboard.writeText('{{ $activeShareUrl }}')"
                                            type="button"
                                            class="shrink-0 px-3 py-1.5 text-xs font-semibold rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 transition active:scale-95"
                                            title="{{ __('tools.share.copy_link') }}">
                                        {{ __('tools.share.copy') }}
                                    </button>
                                </div>
                                <div class="flex items-center justify-center sm:justify-start gap-3 text-[11px] text-slate-400">
                                    <span>⏳ {{ __('tools.share.expires') }}: {{ $activeShareExpiresAt }}</span>
                                    <span>📥 {{ __('tools.share.remaining') }}: {{ $activeShareRemainingDownloads }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <!-- Action buttons -->
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-slate-100">
                <button wire:click="resetWorkflow"
                        type="button"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-xs hover:bg-slate-50 transition active:scale-95">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span>{{ __('ui.process_another') }}</span>
                </button>

                <button wire:click="deleteJobFiles"
                        type="button"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border border-rose-200 bg-rose-50/50 px-4 py-2.5 text-sm font-semibold text-rose-600 hover:bg-rose-100/60 transition active:scale-95"
                        title="{{ __('ui.delete_now') }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    <span>{{ __('ui.delete_now') }}</span>
                </button>
            </div>
        </div>
    </div>
</div>
