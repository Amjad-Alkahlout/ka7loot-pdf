<x-app-layout :title="__('ui.contact')">
    <div class="py-12 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="border-b border-slate-200 pb-6 mb-8">
                <h1 class="text-3xl font-extrabold text-slate-900 sm:text-4xl">
                    {{ __('ui.contact') }}
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    {{ __('ui.contact_desc') }}
                </p>
            </div>

            <!-- Professional Contact Card -->
            <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-8 sm:p-10 shadow-xs">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-8">
                    <div class="space-y-3">
                        <div class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            <span>{{ __('ui.creator_title') }}</span>
                        </div>
                        <h2 class="text-2xl sm:text-3xl font-bold text-slate-900">
                            Amjad Alkahloot
                        </h2>
                        <p class="text-slate-600 font-medium">
                            Software Developer
                        </p>
                        <p class="text-sm text-slate-500 max-w-md pt-1">
                            {{ app()->getLocale() === 'ar' ? 'للتواصل والاستفسارات التقنية أو التعاون البرمجي، يسعدني استقبال رسائلكم عبر البريد الإلكتروني أو متابعة الأعمال على جيت هَب.' : 'For inquiries, technical feedback, or software collaboration, feel free to reach out via email or visit my GitHub profile.' }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-4 sm:min-w-[280px]">
                        <!-- Email Link -->
                        <a href="mailto:akahloot2005@gmail.com"
                           class="flex items-center gap-3 rounded-xl bg-indigo-600 px-5 py-3.5 text-white shadow-sm hover:bg-indigo-700 transition">
                            <svg class="h-5 w-5 text-indigo-200 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <div class="text-start">
                                <div class="text-xs text-indigo-200 font-medium">{{ __('ui.contact_email') }}</div>
                                <div class="text-sm font-semibold">akahloot2005@gmail.com</div>
                            </div>
                        </a>

                        <!-- GitHub Link -->
                        <a href="https://github.com/Amjad-Alkahlout" target="_blank" rel="noopener noreferrer"
                           class="flex items-center gap-3 rounded-xl border border-slate-300 bg-white px-5 py-3.5 text-slate-800 shadow-xs hover:bg-slate-100 hover:border-slate-400 transition">
                            <svg class="h-5 w-5 fill-slate-700 shrink-0" viewBox="0 0 24 24">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
                            </svg>
                            <div class="text-start">
                                <div class="text-xs text-slate-500 font-medium">{{ __('ui.contact_github') }}</div>
                                <div class="text-sm font-semibold">Amjad-Alkahlout</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <div class="mt-10 pt-6 border-t border-slate-200">
                <a href="{{ route('home') }}" class="inline-flex items-center text-sm font-semibold text-indigo-600 hover:text-indigo-800">
                    &larr; {{ __('ui.back_to_home') }}
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
