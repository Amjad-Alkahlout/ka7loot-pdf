@props([
    'jobStatus' => 'empty',
    'hasUpload' => false,
    'isRtl' => null,
])

@php
    $isRtl = $isRtl ?? (app()->getLocale() === 'ar');
    $dir = $isRtl ? 'rtl' : 'ltr';

    $step = 1;
    if ($jobStatus === 'completed' || $jobStatus === 'failed') {
        $step = 4;
    } elseif ($jobStatus === 'processing') {
        $step = 3;
    } elseif ($hasUpload || in_array($jobStatus, ['uploaded', 'ready'])) {
        $step = 2;
    }

    $progressWidth = match($step) {
        1 => '10%',
        2 => '38%',
        3 => '72%',
        4 => 'calc(100% - 3rem)',
    };
@endphp

<div class="max-w-2xl mx-auto mb-8 px-2" dir="{{ $dir }}" data-testid="workflow-stepper" data-direction="{{ $dir }}">
    <div class="flex items-center justify-between relative">
        <!-- Connecting Background Track -->
        <div class="absolute left-6 right-6 top-4 -translate-y-1/2 h-0.5 bg-slate-200 -z-0" style="left: 1.5rem; right: 1.5rem;"></div>

        <!-- Connecting Active Track (direction-aware: advances left in RTL, right in LTR) -->
        <div class="absolute top-4 -translate-y-1/2 h-0.5 bg-indigo-600 transition-all duration-300 -z-0 {{ $isRtl ? 'right-6 left-auto' : 'left-6 right-auto' }}"
             data-testid="stepper-active-track"
             style="{{ $isRtl ? 'right: 1.5rem; left: auto;' : 'left: 1.5rem; right: auto;' }} width: {{ $progressWidth }};"></div>

        <!-- Step 1: Uploading / Selected -->
        <div class="relative z-10 flex flex-col items-center" data-step="1">
            <div class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all {{ $step > 1 ? 'bg-indigo-600 text-white shadow-xs' : ($step === 1 ? 'bg-indigo-600 text-white ring-4 ring-indigo-100 shadow-xs' : 'bg-white border-2 border-slate-300 text-slate-500') }}">
                @if($step > 1)
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                @else
                    1
                @endif
            </div>
            <span class="mt-1.5 text-[11px] font-semibold {{ $step >= 1 ? 'text-indigo-900' : 'text-slate-400' }}">
                {{ __('ui.state_uploading') }}
            </span>
        </div>

        <!-- Step 2: Uploaded & Options -->
        <div class="relative z-10 flex flex-col items-center" data-step="2">
            <div class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all {{ $step > 2 ? 'bg-indigo-600 text-white shadow-xs' : ($step === 2 ? 'bg-indigo-600 text-white ring-4 ring-indigo-100 shadow-xs' : 'bg-white border-2 border-slate-300 text-slate-500') }}">
                @if($step > 2)
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                @else
                    2
                @endif
            </div>
            <span class="mt-1.5 text-[11px] font-semibold {{ $step >= 2 ? 'text-indigo-900' : 'text-slate-400' }}">
                {{ __('ui.state_uploaded') }}
            </span>
        </div>

        <!-- Step 3: Processing -->
        <div class="relative z-10 flex flex-col items-center" data-step="3">
            <div class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all {{ $step > 3 ? 'bg-indigo-600 text-white shadow-xs' : ($step === 3 ? 'bg-indigo-600 text-white ring-4 ring-indigo-100 shadow-xs animate-pulse' : 'bg-white border-2 border-slate-300 text-slate-500') }}">
                @if($step > 3)
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                @else
                    3
                @endif
            </div>
            <span class="mt-1.5 text-[11px] font-semibold {{ $step >= 3 ? 'text-indigo-900' : 'text-slate-400' }}">
                {{ __('ui.state_processing') }}
            </span>
        </div>

        <!-- Step 4: Result (Completed / Failed) -->
        <div class="relative z-10 flex flex-col items-center" data-step="4">
            <div class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold transition-all {{ $jobStatus === 'failed' ? 'bg-rose-600 text-white shadow-xs' : ($step === 4 ? 'bg-emerald-600 text-white ring-4 ring-emerald-100 shadow-xs' : 'bg-white border-2 border-slate-300 text-slate-500') }}">
                @if($jobStatus === 'failed')
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                @elseif($step === 4)
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                @else
                    4
                @endif
            </div>
            <span class="mt-1.5 text-[11px] font-semibold {{ $jobStatus === 'failed' ? 'text-rose-600' : ($step === 4 ? 'text-emerald-700' : 'text-slate-400') }}">
                {{ $jobStatus === 'failed' ? __('ui.state_failed') : __('ui.state_completed') }}
            </span>
        </div>
    </div>
</div>
