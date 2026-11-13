@php
    $appUrl = rtrim((string) config('app.url', 'https://ka7lootpdf.me'), '/');
    if (!str_contains($appUrl, 'ka7lootpdf.me')) {
        $appUrl = 'https://ka7lootpdf.me';
    } elseif (str_starts_with($appUrl, 'http://')) {
        $appUrl = 'https://' . substr($appUrl, 7);
    }
    $path = request()->getPathInfo();
    $canonicalUrl = $path === '/' ? $appUrl : $appUrl . $path;

    $appName = __('ui.app_name');
    $appTagline = __('ui.app_tagline');

    $pageTitle = isset($title) && !empty($title)
        ? (str_contains($title, $appName) ? $title : $title . ' - ' . $appName)
        : $appName . ' - ' . $appTagline;

    $pageDescription = $description ?? $metaDescription ?? $appTagline;
    $isRtl = app()->getLocale() === 'ar';
    $ogLocale = $isRtl ? 'ar_AR' : 'en_US';

    $gaMeasurementId = config('services.google.analytics.measurement_id');
    $adsensePublisherId = config('services.google.adsense.publisher_id');

    $organizationSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $appName,
        'url' => 'https://ka7lootpdf.me',
        'logo' => 'https://ka7lootpdf.me/favicon.ico',
        'founder' => [
            '@type' => 'Person',
            'name' => 'Amjad Alkahloot',
            'jobTitle' => 'Software Developer',
        ],
    ];

    $specificSchema = null;
    if (request()->routeIs('home')) {
        $specificSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $appName,
            'url' => 'https://ka7lootpdf.me',
            'description' => $appTagline,
        ];
    } elseif (request()->routeIs('tools.*')) {
        $specificSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => $title ?? $appName,
            'url' => $canonicalUrl,
            'description' => $pageDescription,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'All',
            'browserRequirements' => 'Requires JavaScript. Requires HTML5.',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'USD',
            ],
        ];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full bg-slate-50 text-slate-900 antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">

    @if($noindex ?? false)
    <meta name="robots" content="noindex, nofollow">
    @else
    <meta name="robots" content="index, follow">
    @endif

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ $canonicalUrl }}">

    <!-- Open Graph Metadata -->
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:site_name" content="{{ $appName }}">
    <meta property="og:locale" content="{{ $ogLocale }}">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $pageTitle }}">
    <meta name="twitter:description" content="{{ $pageDescription }}">

    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    {!! json_encode($organizationSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
    </script>
    @if($specificSchema)
    <script type="application/ld+json">
    {!! json_encode($specificSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
    </script>
    @endif

    @if(!empty($gaMeasurementId))
    <!-- Google Analytics (GA4) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaMeasurementId }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $gaMeasurementId }}', {
            anonymize_ip: true,
            send_page_view: true
        });
    </script>
    @endif

    @if(!empty($adsensePublisherId))
    <!-- Google AdSense -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $adsensePublisherId }}" crossorigin="anonymous"></script>
    @endif

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

        // Production-safe anonymous event tracking helper
        window.trackEvent = function(eventName, eventParams) {
            if (typeof window.gtag === 'function') {
                window.gtag('event', eventName, eventParams || {});
            }
        };
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        window.clerkConfig = {
            publishableKey: @json(config('clerk.publishable_key')),
            csrfToken: @json(csrf_token()),
            syncUrl: @json(route('auth.sync')),
            logoutUrl: @json(route('logout')),
            syncErrorMessage: @json(__('ui.clerk_sync_failed')),
        };
    </script>
    @livewireStyles
    <style>
        body {
            font-family: {{ $isRtl ? "'Cairo', system-ui, sans-serif" : "'Plus Jakarta Sans', system-ui, sans-serif" }};
        }
    </style>
</head>
<body class="flex min-h-full flex-col bg-slate-50 text-slate-800">
    @php
        $dailyQuota = app(\App\Services\File\DailyFileQuotaService::class)->summary();
        $quotaUsed = $dailyQuota['used'] + $dailyQuota['reserved'];
        $quotaPercent = $dailyQuota['limit'] > 0 ? min(100, (int) round(($quotaUsed / $dailyQuota['limit']) * 100)) : 0;
        $quotaRemaining = $dailyQuota['remaining'];
        $quotaLimit = $dailyQuota['limit'];
        $quotaStatusColor = $quotaRemaining === 0 ? 'bg-rose-500' : ($quotaPercent >= 80 ? 'bg-amber-500' : 'bg-emerald-500');
        $quotaBarColor = $quotaRemaining === 0 ? 'bg-rose-500' : ($quotaPercent >= 80 ? 'bg-amber-500' : 'bg-emerald-600');
    @endphp

    <!-- Navbar -->
    <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur-md">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
            <!-- Brand -->
            <div class="flex items-center gap-3">
                <a href="{{ route('home', [], false) }}" class="flex items-center gap-2.5 text-xl font-extrabold tracking-tight text-slate-900 transition hover:opacity-90">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 text-white shadow-md shadow-indigo-500/20">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </span>
                    <span class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-800 bg-clip-text text-transparent">{{ __('ui.app_name') }}</span>
                </a>
            </div>

            <!-- Desktop Nav Links -->
            <nav class="hidden items-center gap-1 xl:flex">
                <a href="{{ route('home', [], false) }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('ui.home') }}</a>
                <a href="{{ route('tools.merge-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.merge_pdf.title') }}</a>
                <a href="{{ route('tools.compress-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.compress_pdf.title') }}</a>
                <a href="{{ route('tools.split-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.split_pdf.title') }}</a>
                <a href="{{ route('tools.jpg-to-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.jpg_to_pdf.title') }}</a>
                <a href="{{ route('tools.heic-to-pdf') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.heic_to_pdf.title') }}</a>
                <a href="{{ route('tools.compress-image') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition">{{ __('tools.compress_image.title') }}</a>
            </nav>

            <!-- Right Controls: Quota Popover + Clerk Auth + Language + Mobile Toggle -->
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- Interactive Daily Quota Popover Trigger -->
                <div class="relative" id="quota-menu-container">
                    <button type="button"
                            id="quota-popover-button"
                            aria-expanded="false"
                            aria-haspopup="true"
                            aria-controls="quota-popover"
                            class="inline-flex items-center gap-1.5 sm:gap-2 rounded-full border border-slate-200/90 bg-white px-2.5 sm:px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs hover:border-slate-300 hover:bg-slate-50 transition cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                        <span class="inline-flex h-2 w-2 rounded-full {{ $quotaStatusColor }} ring-2 ring-white" aria-hidden="true"></span>
                        <svg class="h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <span class="hidden sm:inline font-bold">{{ $quotaRemaining }}/{{ $quotaLimit }}</span>
                        <span class="sm:hidden font-bold">{{ $quotaRemaining }}</span>
                        <svg id="quota-chevron" class="h-3 w-3 text-slate-400 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <!-- Floating Quota Popover Panel -->
                    <div id="quota-popover"
                         role="region"
                         aria-label="{{ __('ui.daily_quota') }}"
                         class="hidden absolute end-0 top-full mt-2 w-72 sm:w-80 rounded-2xl border border-slate-200/90 bg-white p-4 sm:p-5 shadow-xl shadow-slate-900/10 z-50 transition-all duration-200">
                        <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                </span>
                                <h3 class="text-sm font-bold text-slate-900">{{ __('ui.daily_quota') }}</h3>
                            </div>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold {{ $quotaRemaining === 0 ? 'bg-rose-50 text-rose-700' : ($quotaPercent >= 80 ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700') }}">
                                {{ $quotaRemaining }}/{{ $quotaLimit }} {{ __('ui.remaining') }}
                            </span>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mt-3.5">
                            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $quotaUsed }}" aria-valuemin="0" aria-valuemax="{{ $quotaLimit }}" aria-label="{{ __('ui.daily_quota') }}">
                                <div class="h-full rounded-full {{ $quotaBarColor }} transition-all duration-300" style="width: {{ $quotaPercent }}%"></div>
                            </div>
                            <div class="mt-2.5 flex items-center justify-between text-xs text-slate-500">
                                <span>{{ __('ui.used') }}: <strong class="text-slate-800">{{ $dailyQuota['used'] }}</strong></span>
                                @if($dailyQuota['reserved'] > 0)
                                    <span>{{ __('ui.processing_reserved') }}: <strong class="text-indigo-600">{{ $dailyQuota['reserved'] }}</strong></span>
                                @endif
                                <span>{{ __('ui.remaining') }}: <strong class="text-slate-800">{{ $quotaRemaining }}</strong></span>
                            </div>
                        </div>

                        <!-- Reset Time & Actions -->
                        <div class="mt-4 pt-3 border-t border-slate-100 space-y-2.5 text-xs">
                            <div class="flex items-center gap-1.5 text-slate-500">
                                <svg class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>{{ __('ui.quota_resets_midnight') }}</span>
                            </div>

                            @auth
                                <a href="{{ route('account') }}" class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-100 hover:text-indigo-600 transition">
                                    <span>{{ __('ui.view_account') }}</span>
                                    <span aria-hidden="true" class="rtl:rotate-180">&rarr;</span>
                                </a>
                            @else
                                <button type="button" data-clerk-sign-in class="w-full flex items-center justify-center gap-1.5 rounded-xl bg-indigo-50 px-3 py-2 font-bold text-indigo-700 hover:bg-indigo-100 transition cursor-pointer">
                                    <span>{{ __('ui.guest_quota_prompt') }}</span>
                                    <span aria-hidden="true" class="rtl:rotate-180">&rarr;</span>
                                </button>
                            @endauth
                        </div>
                    </div>
                </div>

                <!-- Desktop Clerk Auth Controls -->
                <div id="clerk-auth-controls" class="hidden sm:flex items-center gap-2" data-authenticated="{{ auth()->check() ? 'true' : 'false' }}">
                    @auth
                        <span class="hidden lg:inline text-xs font-semibold text-slate-600">{{ auth()->user()->name }}</span>
                        <a href="{{ route('account') }}" class="text-xs font-semibold text-slate-600 hover:text-indigo-600">{{ __('ui.account') }}</a>
                        <button type="button" data-clerk-sign-out class="text-xs font-semibold text-slate-600 hover:text-indigo-600 cursor-pointer">{{ __('ui.sign_out') }}</button>
                    @else
                        <button type="button" data-clerk-sign-in class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-700 cursor-pointer">{{ __('ui.sign_in') }}</button>
                        <button type="button" data-clerk-sign-up class="hidden lg:inline text-xs font-semibold text-indigo-700 hover:text-indigo-900 cursor-pointer">{{ __('ui.sign_up') }}</button>
                    @endauth
                </div>

                <!-- Language Switcher -->
                <a href="{{ route('locale.switch', app()->getLocale() === 'en' ? 'ar' : 'en') }}"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 sm:px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs hover:border-slate-300 hover:bg-slate-50 transition">
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                    </svg>
                    <span>{{ __('ui.switch_language') }}</span>
                </a>

                <!-- Mobile Hamburger Toggle Button -->
                <button type="button"
                        id="mobile-menu-button"
                        aria-expanded="false"
                        aria-controls="mobile-nav-drawer"
                        aria-label="{{ __('ui.toggle_navigation') }}"
                        class="xl:hidden inline-flex items-center justify-center h-9 w-9 rounded-lg border border-slate-200 bg-white text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 cursor-pointer">
                    <svg id="mobile-hamburger-icon" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                    <svg id="mobile-close-icon" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <!-- Mobile Navigation Drawer Overlay & Panel -->
    <div id="mobile-nav-drawer"
         class="hidden fixed inset-0 z-50"
         role="dialog"
         aria-modal="true"
         aria-labelledby="mobile-menu-title">
        <!-- Backdrop with Fade -->
        <div id="mobile-nav-backdrop"
             class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs transition-opacity"
             aria-hidden="true"></div>

        <!-- Slide-over Drawer Panel -->
        <div class="fixed inset-y-0 {{ $isRtl ? 'left-0' : 'right-0' }} max-w-full flex">
            <div class="w-screen max-w-sm bg-white shadow-2xl flex flex-col h-full transform transition-transform ease-in-out duration-300">
                <!-- Drawer Header -->
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <a href="{{ route('home', [], false) }}" class="flex items-center gap-2">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 text-white shadow-xs">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                        </span>
                        <span id="mobile-menu-title" class="font-bold text-slate-900">{{ __('ui.app_name') }}</span>
                    </a>
                    <button type="button"
                            id="mobile-drawer-close"
                            aria-label="{{ __('ui.close_menu') }}"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition cursor-pointer">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Drawer Scrollable Content -->
                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-5">
                    <!-- Mobile Daily Quota Card -->
                    <div class="rounded-2xl border border-slate-200/90 bg-slate-50/70 p-4">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-2 w-2 rounded-full {{ $quotaStatusColor }}"></span>
                                <span class="text-xs font-bold text-slate-900">{{ __('ui.daily_quota') }}</span>
                            </div>
                            <span class="text-xs font-bold text-indigo-700">{{ $quotaRemaining }}/{{ $quotaLimit }}</span>
                        </div>
                        <div class="mt-2.5 h-2 w-full overflow-hidden rounded-full bg-slate-200/70">
                            <div class="h-full rounded-full {{ $quotaBarColor }} transition-all" style="width: {{ $quotaPercent }}%"></div>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                            <span>{{ __('ui.used') }}: {{ $dailyQuota['used'] }}</span>
                            <span>{{ __('ui.remaining') }}: {{ $quotaRemaining }}</span>
                        </div>
                    </div>

                    <!-- Auth Section in Mobile Menu -->
                    <div class="rounded-2xl border border-slate-200/80 bg-white p-3 shadow-xs">
                        @auth
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-bold text-slate-900">{{ auth()->user()->name }}</p>
                                    <p class="truncate text-[11px] text-slate-500">{{ auth()->user()->email }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('account') }}" class="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 hover:bg-indigo-100 transition">{{ __('ui.account') }}</a>
                                    <button type="button" data-clerk-sign-out class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200 transition cursor-pointer">{{ __('ui.sign_out') }}</button>
                                </div>
                            </div>
                        @else
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" data-clerk-sign-in class="w-full rounded-xl bg-indigo-600 px-3 py-2 text-center text-xs font-bold text-white hover:bg-indigo-700 transition cursor-pointer">{{ __('ui.sign_in') }}</button>
                                <button type="button" data-clerk-sign-up class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-center text-xs font-bold text-slate-700 hover:bg-slate-50 transition cursor-pointer">{{ __('ui.sign_up') }}</button>
                            </div>
                        @endauth
                    </div>

                    <!-- Categorized Tools Links -->
                    <nav class="space-y-4">
                        <!-- PDF Essentials -->
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">{{ __('ui.pdf_essentials') }}</p>
                            <div class="grid grid-cols-2 gap-1.5">
                                <a href="{{ route('tools.merge-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                                    <span>{{ __('tools.merge_pdf.title') }}</span>
                                </a>
                                <a href="{{ route('tools.split-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                    <span>{{ __('tools.split_pdf.title') }}</span>
                                </a>
                                <a href="{{ route('tools.compress-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    <span>{{ __('tools.compress_pdf.title') }}</span>
                                </a>
                                <a href="{{ route('tools.organize-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                                    <span>{{ __('tools.organize_pdf.title') }}</span>
                                </a>
                            </div>
                        </div>

                        <!-- Convert & Export -->
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">{{ __('ui.convert_export') }}</p>
                            <div class="grid grid-cols-2 gap-1.5">
                                <a href="{{ route('tools.jpg-to-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span>
                                    <span>{{ __('tools.jpg_to_pdf.title') }}</span>
                                </a>
                                <a href="{{ route('tools.heic-to-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-pink-500"></span>
                                    <span>{{ __('tools.heic_to_pdf.title') }}</span>
                                </a>
                                <a href="{{ route('tools.pdf-to-jpg') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                    <span>{{ __('tools.pdf_to_jpg.title') }}</span>
                                </a>
                                <a href="{{ route('tools.pdf-to-png') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
                                    <span>{{ __('tools.pdf_to_png.title') }}</span>
                                </a>
                            </div>
                        </div>

                        <!-- Security & Signing -->
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">{{ __('ui.security_signing') }}</p>
                            <div class="grid grid-cols-2 gap-1.5">
                                <a href="{{ route('tools.sign-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-violet-500"></span>
                                    <span>{{ __('tools.sign_pdf.title') }}</span>
                                </a>
                                <a href="{{ route('tools.edit-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-teal-500"></span>
                                    <span>{{ __('tools.edit_pdf.title') }}</span>
                                </a>
                                <a href="{{ route('tools.protect-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                    <span>{{ __('tools.protect_pdf.title') }}</span>
                                </a>
                                <a href="{{ route('tools.unlock-pdf') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-cyan-500"></span>
                                    <span>{{ __('tools.unlock_pdf.title') }}</span>
                                </a>
                            </div>
                        </div>

                        <!-- Image Tools -->
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">{{ __('ui.image_tools_menu') }}</p>
                            <div class="grid grid-cols-2 gap-1.5">
                                <a href="{{ route('tools.compress-image') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    <span>{{ __('tools.compress_image.title') }}</span>
                                </a>
                                <a href="{{ route('tools.resize-image') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                    <span>{{ __('tools.resize_image.title') }}</span>
                                </a>
                                <a href="{{ route('tools.convert-image') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-fuchsia-500"></span>
                                    <span>{{ __('tools.convert_image.title') }}</span>
                                </a>
                                <a href="{{ route('tools.remove-background') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-purple-500"></span>
                                    <span>{{ __('tools.remove_background.title') }}</span>
                                </a>
                                <a href="{{ route('tools.change-background') }}" class="flex items-center gap-2 rounded-xl px-2.5 py-2 text-xs font-semibold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 transition">
                                    <span class="h-1.5 w-1.5 rounded-full bg-indigo-500"></span>
                                    <span>{{ __('tools.change_background.title') }}</span>
                                </a>
                            </div>
                        </div>
                    </nav>
                </div>

                <!-- Drawer Footer -->
                <div class="border-t border-slate-100 p-4 bg-slate-50/50 flex items-center justify-between text-xs text-slate-500">
                    <a href="{{ route('privacy-policy') }}" class="hover:text-indigo-600 transition">{{ __('ui.privacy_policy') }}</a>
                    <a href="{{ route('terms-of-service') }}" class="hover:text-indigo-600 transition">{{ __('ui.terms_of_service') }}</a>
                    <a href="{{ route('contact') }}" class="hover:text-indigo-600 transition">{{ __('ui.contact') }}</a>
                </div>
            </div>
        </div>
    </div>

    <div id="clerk-auth-error" class="hidden mx-auto mt-4 w-full max-w-7xl rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800" role="alert" aria-live="assertive"></div>

    <!-- Monetization / Future AdSense Unit: Top Responsive Placement (Non-obstructive) -->
    <div id="ad-slot-header" class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center empty:hidden" data-ad-slot="header" aria-hidden="true"></div>

    <!-- Main Content -->
    <main class="flex-1">
        @if (isset($errors) && $errors->any())
            <div class="mx-auto mt-6 w-full max-w-7xl px-4 sm:px-6 lg:px-8" role="alert">
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 shadow-sm">
                    <ul class="list-disc space-y-1 ps-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
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
                    <a href="{{ route('home', [], false) }}" class="inline-flex items-center gap-2.5 text-xl font-extrabold tracking-tight text-slate-900 transition hover:opacity-90">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 text-white shadow-md shadow-indigo-500/20">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
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
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" />
                                </svg>
                                <span>{{ __('ui.contact_github') }}</span>
                                <svg class="h-3 w-3 rtl:rotate-180 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
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
