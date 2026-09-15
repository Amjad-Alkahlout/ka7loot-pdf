<x-app-layout>
    <!-- Hero Section -->
    <div class="relative overflow-hidden bg-white border-b border-slate-200/80">
        <div class="absolute inset-0 bg-[radial-gradient(#e0e7ff_1px,transparent_1px)] [background-size:16px_16px] opacity-40"></div>
        <div class="relative mx-auto max-w-5xl px-4 py-9 sm:px-6 sm:py-12 lg:py-14 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50/70 px-3.5 py-1.5 text-xs font-semibold text-indigo-800 shadow-xs mb-3.5 sm:mb-4">
                <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <span>{{ __('ui.security_badge') }}</span>
            </div>
            <h1 class="text-3xl sm:text-4xl lg:text-[2.75rem] font-extrabold tracking-tight text-slate-900 leading-tight sm:leading-snug max-w-3xl mx-auto">
                {{ __('ui.app_tagline') }}
            </h1>
            <p class="mx-auto mt-3 sm:mt-3.5 max-w-xl sm:max-w-2xl text-sm sm:text-base text-slate-600 leading-relaxed">
                {{ __('ui.privacy_pledge') }}
            </p>
        </div>
    </div>

    <!-- Tools Grid Section -->
    <div class="mx-auto max-w-7xl px-4 pt-10 pb-16 sm:px-6 sm:pt-12 sm:pb-20 lg:px-8 space-y-14 sm:space-y-16">
        <!-- PDF Tools Category -->
        <div>
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">{{ __('ui.pdf_tools') }}</h2>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">{{ __('ui.pdf_tools_desc') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-12">
                <!-- Merge PDF -->
                <a href="{{ route('tools.merge-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-red-50 text-red-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.merge_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.merge_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Split PDF -->
                <a href="{{ route('tools.split-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.split_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.split_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Compress PDF -->
                <a href="{{ route('tools.compress-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.compress_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.compress_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- PDF to JPG -->
                <a href="{{ route('tools.pdf-to-jpg') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-orange-50 text-orange-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.pdf_to_jpg.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.pdf_to_jpg.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- PDF to PNG -->
                <a href="{{ route('tools.pdf-to-png') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-sky-50 text-sky-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.pdf_to_png.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.pdf_to_png.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- JPG to PDF -->
                <a href="{{ route('tools.jpg-to-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-50 text-blue-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.jpg_to_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.jpg_to_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Organize PDF -->
                <a href="{{ route('tools.organize-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-violet-50 text-violet-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.organize_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.organize_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Edit PDF -->
                <a href="{{ route('tools.edit-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-blue-50 text-blue-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.edit_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.edit_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Sign PDF -->
                <a href="{{ route('tools.sign-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-4">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-purple-50 text-purple-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.sign_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.sign_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Protect PDF -->
                <a href="{{ route('tools.protect-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-4">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.protect_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.protect_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Unlock PDF -->
                <a href="{{ route('tools.unlock-pdf') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-2 lg:col-span-4 sm:max-w-md sm:mx-auto sm:w-full lg:max-w-none">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.unlock_pdf.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.unlock_pdf.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>
            </div>
        </div>

        <!-- Image Tools Category -->
        <div>
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">{{ __('ui.image_tools') }}</h2>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1">{{ __('ui.image_tools_desc') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-6">
                <!-- Remove Background -->
                <a href="{{ route('tools.remove-background') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-2">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-pink-50 text-pink-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.remove_background.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.remove_background.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Change Background -->
                <a href="{{ route('tools.change-background') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-2">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-fuchsia-50 text-fuchsia-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.change_background.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.change_background.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Compress Image -->
                <a href="{{ route('tools.compress-image') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-2">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-purple-50 text-purple-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.compress_image.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.compress_image.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Resize Image -->
                <a href="{{ route('tools.resize-image') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-1 lg:col-span-3">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-teal-50 text-teal-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.resize_image.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.resize_image.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>

                <!-- Convert Image -->
                <a href="{{ route('tools.convert-image') }}" class="group relative flex h-full flex-col justify-between rounded-2xl border border-slate-200/90 bg-white p-6 shadow-xs transition duration-200 hover:-translate-y-1 hover:border-indigo-300 hover:shadow-md hover:shadow-indigo-500/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 col-span-1 sm:col-span-2 lg:col-span-3 sm:max-w-md sm:mx-auto sm:w-full lg:max-w-none">
                    <div class="flex flex-1 flex-col">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 group-hover:scale-105 transition-transform duration-200">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                            </svg>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 group-hover:text-indigo-600 transition-colors">{{ __('tools.convert_image.title') }}</h3>
                        <p class="mt-2 text-sm text-slate-500 leading-relaxed min-h-[44px] sm:min-h-[48px] flex-1">{{ __('tools.convert_image.desc') }}</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-slate-100 flex items-center">
                        <span class="text-xs font-semibold text-indigo-600 group-hover:translate-x-1 rtl:group-hover:-translate-x-1 transition-transform inline-flex items-center gap-1">
                            {{ __('ui.get_started') }} <span aria-hidden="true" class="inline-block transition-transform duration-200 rtl:rotate-180">&rarr;</span>
                        </span>
                    </div>
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
