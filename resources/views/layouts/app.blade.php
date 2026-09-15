<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="h-full bg-slate-50 text-slate-900 antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('ui.app_name') }} - {{ __('ui.app_tagline') }}</title>
    <meta name="description" content="{{ __('ui.app_tagline') }}">

    <!-- Canonical & Alternate Hreflang Tags -->
    <link rel="canonical" href="{{ url()->current() }}">
    <link rel="alternate" hreflang="en" href="{{ route('locale.switch', 'en') }}">
    <link rel="alternate" hreflang="ar" href="{{ route('locale.switch', 'ar') }}">
    <link rel="alternate" hreflang="x-default" href="{{ url('/') }}">

    <!-- Open Graph Metadata -->
    <meta property="og:title" content="{{ $title ?? __('ui.app_name') }} - {{ __('ui.app_tagline') }}">
    <meta property="og:description" content="{{ __('ui.app_tagline') }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ __('ui.app_name') }}">
    <meta property="og:locale" content="{{ app()->getLocale() === 'ar' ? 'ar_AR' : 'en_US' }}">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $title ?? __('ui.app_name') }} - {{ __('ui.app_tagline') }}">
    <meta name="twitter:description" content="{{ __('ui.app_tagline') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script>
        window.fileProcessorConfig = {
            limits: {
                maxPdfSizeKb: {{ (int) config('fileprocessor.limits.max_pdf_size_kb', 51200) }},
                maxPdfSizeMb: {{ (int) round(config('fileprocessor.limits.max_pdf_size_kb', 51200) / 1024) }},
                maxImageSizeKb: {{ (int) config('fileprocessor.limits.max_image_size_kb', 25600) }},
                maxImageSizeMb: {{ (int) round(config('fileprocessor.limits.max_image_size_kb', 25600) / 1024) }},
                maxBatchFiles: {{ (int) config('fileprocessor.limits.max_batch_files', 20) }},
                maxTotalBatchKb: {{ (int) config('fileprocessor.limits.max_total_batch_kb', 102400) }},
                maxTotalBatchMb: {{ (int) round(config('fileprocessor.limits.max_total_batch_kb', 102400) / 1024) }},
            },
            locale: '{{ app()->getLocale() }}',
        };
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        body {
            font-family: {{ app()->getLocale() === 'ar' ? "'Cairo', system-ui, sans-serif" : "'Plus Jakarta Sans', system-ui, sans-serif" }};
        }
    </style>
</head>
<body class="flex min-h-full flex-col bg-slate-50 text-slate-800">
    <!-- Navbar -->
    <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur-md">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-xl font-extrabold tracking-tight text-slate-900 transition hover:opacity-90">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 text-white shadow-md shadow-indigo-500/20">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </span>
                    <span class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-800 bg-clip-text text-transparent">{{ __('ui.app_name') }}</span>
                </a>
            </div>

            <!-- Desktop Nav Links -->
            <nav class="hidden items-center gap-1 md:flex">
                <a href="{{ route('home') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('ui.home') }}</a>
                <a href="{{ route('tools.merge-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.merge_pdf.title') }}</a>
                <a href="{{ route('tools.compress-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.compress_pdf.title') }}</a>
                <a href="{{ route('tools.split-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.split_pdf.title') }}</a>
                <a href="{{ route('tools.jpg-to-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.jpg_to_pdf.title') }}</a>
                <a href="{{ route('tools.compress-image') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.compress_image.title') }}</a>
            </nav>

            <!-- Language Switcher & Security Pill -->
            <div class="flex items-center gap-3">
                <a href="{{ route('locale.switch', app()->getLocale() === 'en' ? 'ar' : 'en') }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs hover:border-slate-300 hover:bg-slate-50 transition">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                    </svg>
                    <span>{{ __('ui.switch_language') }}</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Monetization / Future AdSense Unit: Top Responsive Placement (Non-obstructive) -->
    <div id="ad-slot-header" class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center empty:hidden" data-ad-slot="header" aria-hidden="true"></div>

    <!-- Main Content -->
    <main class="flex-1">
        {{ $slot }}
    </main>

    <!-- Monetization / Future AdSense Unit: Bottom Responsive Placement (Non-obstructive) -->
    <div id="ad-slot-footer" class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center empty:hidden" data-ad-slot="footer" aria-hidden="true"></div>

    <!-- Footer -->
    <footer class="mt-auto border-t border-slate-200/80 bg-white text-slate-600">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12 lg:py-16">
            <div class="grid grid-cols-1 gap-10 sm:grid-cols-2 lg:grid-cols-12 lg:gap-12">
                <!-- GROUP 1 — BRAND (Span 5 on lg) -->
                <div class="space-y-4 sm:col-span-2 lg:col-span-5">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2.5 text-xl font-extrabold tracking-tight text-slate-900 transition hover:opacity-90">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 text-white shadow-md shadow-indigo-500/20">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                        </span>
                        <span class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-800 bg-clip-text text-transparent">{{ __('ui.app_name') }}</span>
                    </a>
                    <p class="text-sm font-medium text-slate-500 max-w-sm leading-relaxed">
                        {{ __('ui.app_tagline') }}
                    </p>
                    <div class="pt-1">
                        <div class="inline-flex items-center gap-2 rounded-full border border-slate-200/90 bg-slate-50/80 px-3 py-1 text-xs text-slate-600">
                            <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                            <span>{{ __('ui.security_badge') }}</span>
                        </div>
                    </div>
                </div>

                <!-- GROUP 2 — NAVIGATION (Span 4 on lg) -->
                <div class="space-y-3.5 sm:col-span-1 lg:col-span-4">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">
                        {{ __('ui.navigation') }}
                    </h3>
                    <ul class="space-y-2.5 text-sm font-medium text-slate-600">
                        <li>
                            <a href="{{ route('about') }}" class="hover:text-indigo-600 transition">{{ __('ui.about') }}</a>
                        </li>
                        <li>
                            <a href="{{ route('contact') }}" class="hover:text-indigo-600 transition">{{ __('ui.contact') }}</a>
                        </li>
                        <li>
                            <a href="{{ route('privacy-policy') }}" class="hover:text-indigo-600 transition">{{ __('ui.privacy_policy') }}</a>
                        </li>
                        <li>
                            <a href="{{ route('terms-of-service') }}" class="hover:text-indigo-600 transition">{{ __('ui.terms_of_service') }}</a>
                        </li>
                        <li>
                            <a href="{{ route('security') }}" class="hover:text-indigo-600 transition">{{ __('ui.security') }}</a>
                        </li>
                    </ul>
                </div>

                <!-- GROUP 3 — CREATOR (Span 3 on lg) -->
                <div class="space-y-3.5 sm:col-span-1 lg:col-span-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">
                        {{ __('ui.creator') }}
                    </h3>
                    <div class="space-y-1.5 text-sm">
                        <p class="text-base font-bold text-slate-900 tracking-tight">
                            {{ __('ui.creator_name') }}
                        </p>
                        <p class="text-xs font-medium text-slate-500">
                            {{ __('ui.creator_title') }}
                        </p>
                        <div class="pt-2">
                            <a href="https://github.com/Amjad-Alkahlout" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-indigo-600 transition-colors">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" />
                                </svg>
                                <span>{{ __('ui.contact_github') }}</span>
                                <svg class="h-3 w-3 rtl:rotate-180 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                </svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Copyright & Privacy Summary -->
            <div class="mt-12 sm:mt-16 border-t border-slate-200/70 pt-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
                <p>
                    {{ __('ui.footer_simple_copyright', ['year' => date('Y')]) }}
                </p>
                <div class="flex items-center gap-2 text-slate-400">
                    <span class="inline-flex h-1.5 w-1.5 rounded-full bg-slate-300"></span>
                    <span>{{ __('ui.privacy_pledge') }}</span>
                </div>
            </div>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
