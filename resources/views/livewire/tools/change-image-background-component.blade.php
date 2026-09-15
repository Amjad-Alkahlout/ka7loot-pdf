<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.change_background.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.change_background.desc') }}</p>
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
        <div class="space-y-8">
            <div class="max-w-4xl mx-auto rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <h3 class="text-lg font-bold text-slate-900 mb-4">{{ __('tools.change_background.result_ready') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @if($originalPreviewUri)
                        <div class="rounded-2xl border border-slate-200 p-3 bg-slate-50">
                            <span class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2 text-center">{{ __('tools.remove_background.preview_original') }}</span>
                            <div class="h-64 flex items-center justify-center overflow-hidden rounded-xl bg-slate-100">
                                <img src="{{ $originalPreviewUri }}" alt="Original" class="max-h-full max-w-full object-contain">
                            </div>
                        </div>
                    @endif

                    @if(!empty($this->processedFiles) && count($this->processedFiles) > 0)
                        <div class="rounded-2xl border border-indigo-200 p-3 bg-indigo-50/30">
                            <span class="block text-xs font-semibold text-indigo-700 uppercase tracking-wider mb-2 text-center">{{ __('tools.change_background.select_replacement') }}</span>
                            <div class="h-64 flex items-center justify-center overflow-hidden rounded-xl border border-slate-200" style="{{ $backgroundType === 'transparent' ? 'background-image: repeating-conic-gradient(#e2e8f0 0% 25%, #ffffff 0% 50%) 50% / 20px 20px;' : '' }}">
                                <img src="{{ route('file.download', ['token' => $this->fileJob->download_token, 'fileId' => $this->processedFiles[0]->id]) }}" alt="Result" class="max-h-full max-w-full object-contain">
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @include('livewire.tools.job-result', ['job' => $this->fileJob, 'processedFiles' => $this->processedFiles])
        </div>

    @elseif($jobStatus === 'failed')
        <div class="max-w-xl mx-auto rounded-3xl border border-rose-200 bg-rose-50/50 p-6 sm:p-8 text-center shadow-md">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h3 class="text-lg font-bold text-rose-900">{{ __('ui.status_failed') }}</h3>
            <p class="mt-1 text-sm text-rose-700">{{ $errorMessage }}</p>
            <button wire:click="resetWorkflow" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition cursor-pointer">
                &larr; {{ __('ui.try_another') }}
            </button>
        </div>

    @else
        @if ($errors->any())
            <div class="max-w-2xl mx-auto mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 shadow-xs">
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
                    'accept'       => 'image/jpeg,image/png,image/webp',
                    'maxSizeMb'    => $this->getMaxImageSizeMb(),
                    'formatsHint'  => 'JPG, PNG, WebP',
                ])
            </div>
        @else
            <div class="max-w-4xl mx-auto rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
                <!-- Replacement Selection Tabs -->
                <div class="mb-6">
                    <label class="block text-sm font-bold text-slate-900 mb-3">{{ __('tools.change_background.select_replacement') }}</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 rounded-2xl bg-slate-100 p-1.5">
                        <button type="button" wire:click="$set('backgroundType', 'solid')"
                            class="flex items-center justify-center gap-2 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold transition cursor-pointer {{ $backgroundType === 'solid' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            <span class="h-3.5 w-3.5 rounded-full border border-slate-300" style="background-color: {{ $solidColor }}"></span>
                            <span>{{ __('tools.change_background.tab_solid') }}</span>
                        </button>
                        <button type="button" wire:click="$set('backgroundType', 'gradient')"
                            class="flex items-center justify-center gap-2 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold transition cursor-pointer {{ $backgroundType === 'gradient' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            <span class="h-3.5 w-3.5 rounded-full" style="background: linear-gradient(135deg, {{ $gradientStart }}, {{ $gradientEnd }})"></span>
                            <span>{{ __('tools.change_background.tab_gradient') }}</span>
                        </button>
                        <button type="button" wire:click="$set('backgroundType', 'transparent')"
                            class="flex items-center justify-center gap-2 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold transition cursor-pointer {{ $backgroundType === 'transparent' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            <span class="h-3.5 w-3.5 rounded-full border border-slate-300" style="background-image: repeating-conic-gradient(#cbd5e1 0% 25%, #ffffff 0% 50%) 50% / 6px 6px;"></span>
                            <span>{{ __('tools.change_background.tab_transparent') }}</span>
                        </button>
                        <button type="button" wire:click="$set('backgroundType', 'image')"
                            class="flex items-center justify-center gap-2 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold transition cursor-pointer {{ $backgroundType === 'image' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <span>{{ __('tools.change_background.tab_image') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Customizer Panel per Tab -->
                <div class="mb-8 rounded-2xl border border-slate-200 p-5 bg-slate-50/50">
                    @if($backgroundType === 'solid')
                        <div>
                            <span class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">{{ __('tools.change_background.solid_presets') }}</span>
                            <div class="flex flex-wrap items-center gap-3">
                                @php
                                    $presets = [
                                        '#FFFFFF' => 'White',
                                        '#000000' => 'Black',
                                        '#64748B' => 'Slate',
                                        '#2563EB' => 'Blue',
                                        '#059669' => 'Emerald',
                                        '#E11D48' => 'Rose',
                                        '#D97706' => 'Amber',
                                        '#7C3AED' => 'Purple',
                                    ];
                                @endphp
                                @foreach($presets as $hex => $label)
                                    <button type="button" wire:click="setSolidPreset('{{ $hex }}')"
                                        class="h-9 w-9 rounded-xl border-2 transition transform hover:scale-110 cursor-pointer shadow-xs {{ strtolower($solidColor) === strtolower($hex) ? 'border-indigo-600 ring-2 ring-indigo-300' : 'border-slate-200' }}"
                                        style="background-color: {{ $hex }};"
                                        title="{{ $label }}">
                                    </button>
                                @endforeach

                                <div class="inline-flex items-center gap-2 ms-3 border-s border-slate-200 ps-3">
                                    <label class="text-xs font-semibold text-slate-600">{{ __('tools.change_background.custom_color') }}:</label>
                                    <input type="color" wire:model.live="solidColor" class="h-9 w-10 p-0.5 rounded-lg border border-slate-300 cursor-pointer">
                                    <span class="font-mono text-xs text-slate-500 uppercase">{{ $solidColor }}</span>
                                </div>
                            </div>
                        </div>

                    @elseif($backgroundType === 'gradient')
                        <div class="space-y-4">
                            <div>
                                <span class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">{{ __('tools.change_background.gradient_presets') }}</span>
                                <div class="flex flex-wrap gap-3">
                                    @php
                                        $gradPresets = [
                                            ['#4F46E5', '#06B6D4', 'Indigo-Cyan'],
                                            ['#F43F5E', '#FB923C', 'Rose-Orange'],
                                            ['#1E293B', '#0F172A', 'Dark-Slate'],
                                            ['#059669', '#0D9488', 'Emerald-Teal'],
                                            ['#D946EF', '#8B5CF6', 'Fuchsia-Purple'],
                                        ];
                                    @endphp
                                    @foreach($gradPresets as $gp)
                                        <button type="button" wire:click="setGradientPreset('{{ $gp[0] }}', '{{ $gp[1] }}')"
                                            class="h-9 w-14 rounded-xl border transition transform hover:scale-105 cursor-pointer shadow-xs {{ ($gradientStart === $gp[0] && $gradientEnd === $gp[1]) ? 'border-indigo-600 ring-2 ring-indigo-300' : 'border-slate-200' }}"
                                            style="background: linear-gradient(135deg, {{ $gp[0] }}, {{ $gp[1] }});"
                                            title="{{ $gp[2] }}">
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2 border-t border-slate-200">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">{{ __('tools.change_background.color_start') }}</label>
                                    <div class="flex items-center gap-2">
                                        <input type="color" wire:model.live="gradientStart" class="h-9 w-10 p-0.5 rounded-lg border border-slate-300 cursor-pointer">
                                        <span class="font-mono text-xs text-slate-500 uppercase">{{ $gradientStart }}</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">{{ __('tools.change_background.color_end') }}</label>
                                    <div class="flex items-center gap-2">
                                        <input type="color" wire:model.live="gradientEnd" class="h-9 w-10 p-0.5 rounded-lg border border-slate-300 cursor-pointer">
                                        <span class="font-mono text-xs text-slate-500 uppercase">{{ $gradientEnd }}</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">{{ __('tools.change_background.gradient_direction') }}</label>
                                    <select wire:model.live="gradientDirection" class="w-full h-9 rounded-lg border border-slate-300 text-xs px-2.5 bg-white">
                                        <option value="vertical">{{ __('tools.change_background.dir_vertical') }}</option>
                                        <option value="horizontal">{{ __('tools.change_background.dir_horizontal') }}</option>
                                        <option value="diagonal">{{ __('tools.change_background.dir_diagonal') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                    @elseif($backgroundType === 'transparent')
                        <div class="text-sm text-slate-600 flex items-center gap-2">
                            <span class="h-5 w-5 rounded-md border border-slate-300" style="background-image: repeating-conic-gradient(#cbd5e1 0% 25%, #ffffff 0% 50%) 50% / 6px 6px;"></span>
                            <span>{{ __('tools.remove_background.preview_transparent') }}</span>
                        </div>

                    @elseif($backgroundType === 'image')
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">{{ __('tools.change_background.upload_bg_label') }}</label>
                                <input type="file" wire:model="bgUpload" accept="image/jpeg,image/png,image/webp"
                                    class="block w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer">
                                <p class="text-xs text-slate-400 mt-1">{{ __('tools.change_background.upload_bg_hint') }}</p>
                            </div>

                            @if($bgPreviewUri)
                                <div class="flex items-center gap-4">
                                    <div class="h-16 w-24 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 flex items-center justify-center">
                                        <img src="{{ $bgPreviewUri }}" alt="Background Preview" class="max-h-full max-w-full object-cover">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-600 mb-1">{{ __('tools.change_background.bg_fit') }}</label>
                                        <div class="flex gap-2">
                                            <button type="button" wire:click="$set('bgFit', 'cover')"
                                                class="px-3 py-1 text-xs rounded-lg font-medium border transition cursor-pointer {{ $bgFit === 'cover' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-700 border-slate-300' }}">
                                                {{ __('tools.change_background.fit_cover') }}
                                            </button>
                                            <button type="button" wire:click="$set('bgFit', 'contain')"
                                                class="px-3 py-1 text-xs rounded-lg font-medium border transition cursor-pointer {{ $bgFit === 'contain' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-700 border-slate-300' }}">
                                                {{ __('tools.change_background.fit_contain') }}
                                            </button>
                                            <button type="button" wire:click="$set('bgFit', 'stretch')"
                                                class="px-3 py-1 text-xs rounded-lg font-medium border transition cursor-pointer {{ $bgFit === 'stretch' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-700 border-slate-300' }}">
                                                {{ __('tools.change_background.fit_stretch') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Preview and Tuning -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                    <!-- Image Card -->
                    <div class="rounded-2xl border border-slate-200 p-4 bg-slate-50 text-center">
                        <span class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">{{ __('tools.remove_background.preview_original') }}</span>
                        <div class="h-64 flex items-center justify-center overflow-hidden rounded-xl bg-white border border-slate-200">
                            @if($originalPreviewUri)
                                <img src="{{ $originalPreviewUri }}" alt="Preview" class="max-h-full max-w-full object-contain">
                            @endif
                        </div>
                        <div class="mt-3 flex justify-between items-center text-xs text-slate-500">
                            <span class="truncate max-w-[200px]">{{ $upload->getClientOriginalName() }}</span>
                            <button wire:click="resetWorkflow" class="text-rose-600 hover:text-rose-700 font-medium cursor-pointer">
                                {{ __('ui.cancel') }}
                            </button>
                        </div>
                    </div>

                    <!-- Tuning & Action -->
                    <div class="space-y-4">
                        <!-- Tolerance -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label for="change-tolerance" class="text-xs font-semibold text-slate-700">{{ __('tools.remove_background.tolerance') }}</label>
                                <span class="text-xs font-mono font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-md">{{ (int)$tolerance }}%</span>
                            </div>
                            <input type="range" id="change-tolerance" wire:model.live="tolerance" min="5" max="80" step="1"
                                class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                        </div>

                        <!-- Feather -->
                        <div>
                            <div class="flex justify-between items-center mb-1">
                                <label for="change-feather" class="text-xs font-semibold text-slate-700">{{ __('tools.remove_background.feather') }}</label>
                                <span class="text-xs font-mono font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-md">{{ $feather }} px</span>
                            </div>
                            <input type="range" id="change-feather" wire:model.live="feather" min="0" max="5" step="1"
                                class="w-full h-2 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                        </div>

                        <!-- Action Button -->
                        <button wire:click="process" wire:loading.attr="disabled"
                            class="w-full mt-4 inline-flex justify-center items-center gap-2 rounded-2xl bg-indigo-600 px-6 py-3.5 text-base font-bold text-white shadow-md hover:bg-indigo-700 transition cursor-pointer disabled:opacity-50">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                            </svg>
                            <span>{{ __('tools.change_background.action') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
