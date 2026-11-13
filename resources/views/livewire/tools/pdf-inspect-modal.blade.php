<!-- High-Resolution Document Page Inspection Modal -->
<div id="pdf-inspect-modal"
     x-data="pdfInspectModal()"
     x-cloak
     x-show="isOpen"
     @keydown.escape.window="close()"
     role="dialog"
     aria-modal="true"
     class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/80 backdrop-blur-xs flex items-center justify-center p-4">
    <div @click.away="close()"
         class="relative max-w-4xl w-full bg-white rounded-3xl shadow-2xl border border-slate-200 overflow-hidden flex flex-col max-h-[90vh]">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50/90">
            <div class="flex items-center gap-3 min-w-0">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 font-bold text-sm shrink-0">
                    <span x-text="page"></span>
                </span>
                <div class="min-w-0">
                    <h3 class="font-bold text-slate-900 text-sm truncate">
                        <span x-text="title || ('{{ __('ui.page') }} ' + page)"></span>
                    </h3>
                    <span class="text-xs text-slate-500">{{ __('tools.split_pdf.high_res_inspection') }}</span>
                </div>
            </div>

            <!-- Zoom Controls & Close Button -->
            <div class="flex items-center gap-2">
                <button @click="zoomOut()"
                        type="button"
                        title="{{ __('tools.split_pdf.zoom_out') }}"
                        class="h-8 w-8 rounded-lg border border-slate-200 bg-white flex items-center justify-center text-slate-700 hover:bg-slate-100 font-bold text-base cursor-pointer">
                    -
                </button>
                <span class="text-xs font-semibold text-slate-600 w-12 text-center" x-text="Math.round(zoom * 100) + '%'"></span>
                <button @click="zoomIn()"
                        type="button"
                        title="{{ __('tools.split_pdf.zoom_in') }}"
                        class="h-8 w-8 rounded-lg border border-slate-200 bg-white flex items-center justify-center text-slate-700 hover:bg-slate-100 font-bold text-base cursor-pointer">
                    +
                </button>
                <button @click="resetZoom()"
                        type="button"
                        class="px-2 py-1 rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-600 hover:bg-slate-100 cursor-pointer">
                    100%
                </button>
                <button @click="close()"
                        type="button"
                        title="{{ __('tools.split_pdf.close_preview') }}"
                        class="ml-2 h-8 w-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center cursor-pointer font-bold">
                    &times;
                </button>
            </div>
        </div>

        <!-- Modal Body (Preview Canvas) -->
        <div class="p-6 overflow-auto flex-1 flex items-center justify-center bg-slate-100/60 min-h-[350px]">
            <template x-if="loading">
                <div class="flex flex-col items-center gap-2 text-slate-400">
                    <svg class="h-8 w-8 animate-spin text-indigo-600" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    <span class="text-xs font-medium">{{ __('tools.split_pdf.preview_loading') }}</span>
                </div>
            </template>
            <template x-if="error">
                <div role="alert" class="max-w-md rounded-xl border border-rose-200 bg-white p-5 text-center shadow-sm">
                    <p class="font-semibold text-rose-700">{{ __('ui.preview_failed') }}</p>
                    <p class="mt-2 text-sm text-slate-600" x-text="errorMessage"></p>
                </div>
            </template>
            <img x-show="imageLoaded"
                 :src="url"
                 :style="`transform: scale(${zoom}); transform-origin: center center;`"
                 class="max-w-full max-h-[70vh] object-contain shadow-lg rounded transition-transform duration-150"
                 alt="Inspection Preview">
        </div>
    </div>
</div>
