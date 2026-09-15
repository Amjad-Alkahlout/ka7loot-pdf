<x-app-layout :title="__('ui.about')">
    <div class="py-12 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="border-b border-slate-200 pb-6 mb-8">
                <h1 class="text-3xl font-extrabold text-slate-900 sm:text-4xl">
                    {{ __('ui.about') }} {{ __('ui.app_name') }}
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    {{ __('ui.app_tagline') }}
                </p>
            </div>

            <div class="prose prose-slate max-w-none space-y-6 text-slate-700 leading-relaxed">
                @if(app()->getLocale() === 'ar')
                    <p class="text-lg font-medium text-slate-800">
                        منصة <strong>Ka7loot PDF</strong> هي منصة متكاملة عبر الويب للتعامل مع ملفات PDF والصور من خلال أدوات بسيطة، سريعة، وآمنة للغاية.
                    </p>
                    <p>
                        تعتمد المنصة على محركات معالجة أصلية رائدة عالمياً مثل Google PDFium و Ghostscript و Poppler، لتوفير تحرير حقيقي للنصوص، وتوقيع رقمي معتمد، وضغط ذكي للملفات، وعزل فائق الدقة للخلفيات—مباشرة عبر المتصفح مع ضمان الخصوصية التامة والحذف التلقائي المؤتمت للملفات.
                    </p>
                @else
                    <p class="text-lg font-medium text-slate-800">
                        <strong>Ka7loot PDF</strong> is an online platform for working with PDF files and images through simple, fast, and secure tools.
                    </p>
                    <p>
                        Powered by world-class native engines including Google PDFium, Ghostscript, and Poppler, Ka7loot PDF provides true native text editing, digital signing, intelligent target-size compression, and neural background removal—completely in the browser with automatic file shredding and end-to-end privacy.
                    </p>
                @endif
            </div>

            <!-- Creator & Maintainer Profile Section -->
            <div class="mt-12 rounded-2xl border border-slate-200 bg-slate-50/70 p-6 sm:p-8">
                <h2 class="text-xl font-bold text-slate-900 mb-4">
                    {{ app()->getLocale() === 'ar' ? 'فريق العمل والتطوير' : 'Creator & Maintainer' }}
                </h2>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
                    <div class="space-y-1">
                        <div class="text-lg font-semibold text-slate-900">Amjad Alkahloot</div>
                        <div class="text-sm font-medium text-indigo-600">Software Developer</div>
                        <p class="text-sm text-slate-500 pt-1">
                            {{ app()->getLocale() === 'ar' ? 'تم إنشاء المنصة وإدارتها بواسطة أمجد الكحلوت' : 'Created & maintained by Amjad Alkahloot' }}
                        </p>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3 sm:items-center text-sm font-medium">
                        <a href="https://github.com/Amjad-Alkahlout" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-slate-700 shadow-xs hover:bg-slate-50 hover:text-slate-900 transition">
                            <svg class="h-4 w-4 fill-current" viewBox="0 0 24 24">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
                            </svg>
                            <span>GitHub</span>
                        </a>

                        <a href="mailto:akahloot2005@gmail.com"
                           class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-white shadow-xs hover:bg-indigo-700 transition">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <span>akahloot2005@gmail.com</span>
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
