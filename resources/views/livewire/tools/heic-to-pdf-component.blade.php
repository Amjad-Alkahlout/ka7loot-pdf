<div class="py-10 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
    <div class="text-center mb-8">
        <h1 class="text-3xl sm:text-4xl font-extrabold text-slate-900 tracking-tight">{{ __('tools.heic_to_pdf.title') }}</h1>
        <p class="mt-2 text-base text-slate-600 max-w-xl mx-auto">{{ __('tools.heic_to_pdf.desc') }}</p>
    </div>

    @include('livewire.tools.workflow-stepper', ['jobStatus' => $jobStatus, 'hasUpload' => !empty($uploads)])

    @if($jobStatus === 'processing')
        <div wire:poll.2500ms="pollJobStatus" class="max-w-xl mx-auto rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-lg">
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600">
                <svg class="h-8 w-8 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/></svg>
            </div>
            <h3 class="text-xl font-bold text-slate-900">{{ $statusMessage ?? __('ui.processing') }}</h3>
            <div class="mt-6 h-2 w-full overflow-hidden rounded-full bg-slate-100"><div class="h-full w-full bg-gradient-to-r from-indigo-500 to-violet-500 animate-pulse rounded-full"></div></div>
        </div>
    @elseif($jobStatus === 'completed' && $this->fileJob)
        @include('livewire.tools.job-result', ['job' => $this->fileJob, 'processedFiles' => $this->processedFiles])
    @elseif($jobStatus === 'failed')
        <div class="max-w-xl mx-auto rounded-3xl border border-rose-200 bg-rose-50 p-8 text-center">
            <h3 class="text-lg font-bold text-rose-900">{{ __('ui.status_failed') }}</h3>
            <p class="mt-1 text-sm text-rose-700">{{ $errorMessage }}</p>
            <button wire:click="resetWorkflow" class="mt-4 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">{{ __('ui.try_another') }}</button>
        </div>
    @else
        @if($errors->any())
            <div class="max-w-3xl mx-auto mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"><ul class="list-disc list-inside">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div class="max-w-3xl mx-auto space-y-6">
            @if(empty($uploads))
                @include('livewire.tools.upload-progress', ['propertyName' => 'uploads', 'multiple' => true, 'accept' => '.heic,.heif,image/heic,image/heif', 'maxSizeMb' => $this->getMaxImageSizeMb(), 'formatsHint' => 'HEIC, HEIF'])
            @else
                <div class="flex items-center justify-between"><h3 class="font-bold text-slate-900">{{ __('ui.files_selected', ['count' => count($uploads)]) }}</h3><button wire:click="cancelToolUpload" class="text-xs font-semibold text-rose-600 hover:underline">{{ __('ui.clear_all') }}</button></div>
                <div class="space-y-3">
                    @foreach($uploads as $idx => $file)
                        <div class="flex items-center justify-between gap-4 p-4 rounded-2xl border border-slate-200 bg-white shadow-xs">
                            <div class="min-w-0"><span class="mr-2 inline-flex rounded-lg bg-indigo-50 px-2 py-1 text-xs font-bold text-indigo-700">{{ $idx + 1 }}</span><span class="truncate font-semibold text-slate-800 text-sm">{{ $file->getClientOriginalName() }}</span><div class="text-xs text-slate-400 mt-1">{{ number_format($file->getSize() / 1024, 1) }} KB</div></div>
                            <div class="flex gap-1"><button wire:click="moveUp({{ $idx }})" @if($idx === 0) disabled @endif class="rounded-lg border px-2 py-1 disabled:opacity-30">↑</button><button wire:click="moveDown({{ $idx }})" @if($idx === count($uploads)-1) disabled @endif class="rounded-lg border px-2 py-1 disabled:opacity-30">↓</button><button wire:click="removeUpload({{ $idx }})" class="rounded-lg border border-rose-200 px-2 py-1 text-rose-600">×</button></div>
                        </div>
                    @endforeach
                </div>
                @include('livewire.tools.upload-progress', ['propertyName' => 'uploads', 'multiple' => true, 'accept' => '.heic,.heif,image/heic,image/heif', 'maxSizeMb' => $this->getMaxImageSizeMb(), 'formatsHint' => 'HEIC, HEIF'])
                <div class="rounded-2xl border border-slate-200 bg-white p-6 space-y-4">
                    <h3 class="text-sm font-bold text-slate-900">{{ __('ui.options') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <label class="text-xs font-semibold text-slate-700">{{ __('tools.heic_to_pdf.page_size') }}<select wire:model="pageSize" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"><option value="a4">A4</option><option value="letter">Letter</option><option value="legal">Legal</option><option value="fit">{{ __('tools.heic_to_pdf.fit_image') }}</option></select></label>
                        <label class="text-xs font-semibold text-slate-700">{{ __('tools.heic_to_pdf.orientation') }}<select wire:model="orientation" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"><option value="auto">Auto</option><option value="portrait">Portrait</option><option value="landscape">Landscape</option></select></label>
                        <label class="text-xs font-semibold text-slate-700">{{ __('tools.heic_to_pdf.margin') }}<select wire:model="margin" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"><option value="0">{{ __('tools.heic_to_pdf.margin_none') }}</option><option value="10">{{ __('tools.heic_to_pdf.margin_small') }}</option><option value="20">{{ __('tools.heic_to_pdf.margin_big') }}</option></select></label>
                    </div>
                </div>
                <button wire:click="process" wire:loading.attr="disabled" class="w-full rounded-2xl bg-indigo-600 py-4 px-6 text-base font-bold text-white hover:bg-indigo-700 disabled:opacity-50"><span wire:loading.remove wire:target="process">{{ __('tools.heic_to_pdf.action') }}</span><span wire:loading wire:target="process">{{ __('ui.processing') }}</span></button>
            @endif
        </div>
    @endif
</div>
