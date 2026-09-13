@props(['job', 'processedFiles'])

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
            <!-- Engine Transparency Callout (for PDF compression) -->
            @if(!empty($job->input_metadata['compression_level']) || $job->tool_type->value === 'compress_pdf')
                @php
                    $isDeep = app(\App\Services\System\SystemBinaryDetector::class)->hasGhostscript();
                @endphp
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

                        <!-- Download Button -->
                        <a href="{{ route('file.download', ['token' => $job->download_token, 'fileId' => $file->id]) }}"
                           class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-md shadow-indigo-600/20 hover:bg-indigo-700 transition active:scale-95">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                            </svg>
                            <span>{{ __('ui.download') }}</span>
                        </a>
                    </div>
                @endforeach
            </div>

            <!-- Server-Anchored Countdown & Delete Now -->
            <div class="rounded-xl border border-slate-200/60 bg-white p-4"
                 x-data="{
                    expiresAt: {{ $job->expires_at->timestamp }},
                    remainingSeconds: 0,
                    interval: null,
                    expired: false,
                    init() {
                        this.update();
                        this.interval = setInterval(() => this.update(), 1000);
                    },
                    update() {
                        const now = Math.floor(Date.now() / 1000);
                        this.remainingSeconds = Math.max(0, this.expiresAt - now);
                        if (this.remainingSeconds <= 0) {
                            this.expired = true;
                            clearInterval(this.interval);
                        }
                    },
                    formatTime() {
                        const m = Math.floor(this.remainingSeconds / 60);
                        const s = this.remainingSeconds % 60;
                        return `${m}:${s < 10 ? '0' : ''}${s}`;
                    }
                 }">
                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-2 text-slate-500">
                        <svg class="h-4 w-4 text-amber-500 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>{{ __('ui.expires_in') }}</span>
                        <span class="font-mono font-bold text-slate-800 text-sm" x-text="formatTime()"></span>
                    </div>

                    <!-- Delete Now Button -->
                    <button wire:click="deleteNow"
                            wire:loading.attr="disabled"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 font-semibold text-rose-700 hover:bg-rose-100 transition text-xs">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        <span>{{ __('ui.delete_now') }}</span>
                    </button>
                </div>
            </div>

            <!-- Try Another Action -->
            <div class="pt-2 text-center">
                <button wire:click="resetState"
                        type="button"
                        class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 transition">
                    &larr; {{ __('ui.try_another') }}
                </button>
            </div>
        </div>
    </div>
</div>
