<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="h-full bg-slate-50 text-slate-900 antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? __('ui.app_name') }} - {{ __('ui.app_tagline') }}</title>
    <meta name="description" content="{{ __('ui.app_tagline') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        [x-cloak] { display: none !important; }
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

    <!-- Main Content -->
    <main class="flex-1">
        {{ $slot }}
    </main>

    <!-- Footer -->
    <footer class="mt-auto border-t border-slate-200 bg-white py-8 text-slate-600">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="flex items-center gap-2 text-sm text-slate-600">
                    <span class="inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                    <span class="font-medium text-slate-700">{{ __('ui.security_badge') }}</span>
                    <span class="text-slate-400">&bull;</span>
                    <span>{{ __('ui.privacy_pledge') }}</span>
                </div>
                <div class="text-xs text-slate-400">
                    &copy; {{ date('Y') }} {{ __('ui.app_name') }}. All rights reserved.
                </div>
            </div>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
