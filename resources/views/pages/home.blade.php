<x-app-layout>
    <!-- Hero Section -->
    <div class="relative overflow-hidden bg-white border-b border-slate-200/80">
        <div class="absolute inset-0 bg-[radial-gradient(#e0e7ff_1px,transparent_1px)] [background-size:16px_16px] opacity-40"></div>
        <div class="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50/70 px-3.5 py-1.5 text-xs font-semibold text-indigo-800 shadow-xs mb-6">
                <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>{{ __('ui.security_badge') }}</span>
            </div>
            <h1 class="text-4xl sm:text-6xl font-extrabold tracking-tight text-slate-900 leading-tight">
                {{ __('ui.app_tagline') }}
            </h1>
            <p class="mx-auto mt-4 max-w-2xl text-base sm:text-lg text-slate-600 leading-relaxed">
                {{ __('ui.privacy_pledge') }}
            </p>
        </div>
    </div>

    <!-- Tools Grid Section -->
    <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <!-- PDF Tools Category -->
        <div class="mb-14">
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-slate-900">{{ __('ui.pdf_tools') }}</h2>
                    <p class="text-sm text-slate-500">Fast, reliable document manipulation</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <!-- Merge PDF -->
                <a href="{{ route('tools.merge-pdf') }}" class="group relative flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition hover:-translate-y-1 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-500/10">
                    <div>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 text-red-600 group-hover:scale-110 transition">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition">{{ __('tools.merge_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed">{{ __('tools.merge_pdf.desc') }}</p>
                    </div>
                    <span class="mt-4 text-xs font-semibold text-indigo-600 group-hover:translate-x-1 transition flex items-center gap-1">
                        Get Started &rarr;
                    </span>
                </a>

                <!-- Split PDF -->
                <a href="{{ route('tools.split-pdf') }}" class="group relative flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition hover:-translate-y-1 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-500/10">
                    <div>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-600 group-hover:scale-110 transition">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition">{{ __('tools.split_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed">{{ __('tools.split_pdf.desc') }}</p>
                    </div>
                    <span class="mt-4 text-xs font-semibold text-indigo-600 group-hover:translate-x-1 transition flex items-center gap-1">
                        Get Started &rarr;
                    </span>
                </a>

                <!-- Compress PDF -->
                <a href="{{ route('tools.compress-pdf') }}" class="group relative flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition hover:-translate-y-1 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-500/10">
                    <div>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 group-hover:scale-110 transition">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition">{{ __('tools.compress_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed">{{ __('tools.compress_pdf.desc') }}</p>
                    </div>
                    <span class="mt-4 text-xs font-semibold text-indigo-600 group-hover:translate-x-1 transition flex items-center gap-1">
                        Get Started &rarr;
                    </span>
                </a>

                <!-- JPG to PDF -->
                <a href="{{ route('tools.jpg-to-pdf') }}" class="group relative flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition hover:-translate-y-1 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-500/10">
                    <div>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-50 text-blue-600 group-hover:scale-110 transition">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition">{{ __('tools.jpg_to_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed">{{ __('tools.jpg_to_pdf.desc') }}</p>
                    </div>
                    <span class="mt-4 text-xs font-semibold text-indigo-600 group-hover:translate-x-1 transition flex items-center gap-1">
                        Get Started &rarr;
                    </span>
                </a>
            </div>
        </div>

        <!-- Image Tools Category -->
        <div>
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-slate-900">{{ __('ui.image_tools') }}</h2>
                    <p class="text-sm text-slate-500">Pure-PHP high-speed image optimization</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <!-- Compress Image -->
                <a href="{{ route('tools.compress-image') }}" class="group relative flex flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition hover:-translate-y-1 hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-500/10">
                    <div>
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-purple-50 text-purple-600 group-hover:scale-110 transition">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition">{{ __('tools.compress_image.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed">{{ __('tools.compress_image.desc') }}</p>
                    </div>
                    <span class="mt-4 text-xs font-semibold text-indigo-600 group-hover:translate-x-1 transition flex items-center gap-1">
                        Get Started &rarr;
                    </span>
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
