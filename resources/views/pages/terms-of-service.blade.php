<x-app-layout :title="__('ui.terms_of_service')" :description="__('ui.privacy_pledge')">
    <div class="py-12 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="border-b border-slate-200 pb-6 mb-8">
                <h1 class="text-3xl font-extrabold text-slate-900 sm:text-4xl">
                    {{ __('ui.terms_of_service') }}
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    {{ app()->getLocale() === 'ar' ? 'آخر تحديث: سبتمبر 2026' : 'Last updated: September 2026' }}
                </p>
            </div>

            <div class="prose prose-slate max-w-none space-y-6 text-slate-700 leading-relaxed">
                @if(app()->getLocale() === 'ar')
                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">1. قبول الشروط</h2>
                        <p>
                            باستخدامك لمنصة <strong>Ka7loot PDF</strong>، فإنك توافق على الالتزام بشروط الخدمة هذه وسياسة الخصوصية الخاصة بنا. إذا كنت لا توافق على هذه الشروط، يرجى عدم استخدام المنصة.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">2. الاستخدام المقبول</h2>
                        <p>
                            أنت توافق على عدم استخدام خدماتنا في أي غرض غير قانوني أو ضار، بما في ذلك:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li>رفع ملفات تحتوي على برمجيات خبيثة أو فيروسات أو شفرات استغلالية.</li>
                            <li>محاولة التحايل على قيود الأمان، أو هندسة المنظومة عكسياً، أو استنزاف موارد الخوادم.</li>
                            <li>معالجة مستندات تنتهك حقوق الملكية الفكرية أو الخصوصية لأطراف ثالثة دون تصريح قانوني.</li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">3. حدود الخدمة والمسؤولية</h2>
                        <p>
                            تُقدم المنصة "كما هي" دون أي ضمانات صريحة أو ضمنية. نحن لا نتحمل المسؤولية عن أي فقدان للملفات الناتجة عن عدم تحميلها قبل انقضاء فترة الصلاحية المؤقتة (60 دقيقة) أو بسبب أخطاء في تهيئة الملفات المصدرية.
                        </p>
                    </section>
                @else
                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">1. Acceptance of Terms</h2>
                        <p>
                            By accessing and using <strong>Ka7loot PDF</strong>, you agree to be bound by these Terms of Service and our Privacy Policy. If you do not agree to these terms, you should discontinue use of the service immediately.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">2. Acceptable Use Policy</h2>
                        <p>
                            You agree not to use our processing tools for any unlawful or abusive purpose, including but not limited to:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li>Uploading files containing malware, automated exploits, or malicious payloads.</li>
                            <li>Attempting to bypass security controls, rate limiters, or isolated storage boundaries.</li>
                            <li>Processing documents that infringe upon intellectual property rights or trade secrets without authorization.</li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">3. Service Limitations & Disclaimer</h2>
                        <p>
                            Ka7loot PDF provides its utilities on an "AS IS" and "AS AVAILABLE" basis. We are not responsible for files not retrieved before their automated expiration (60-minute window) or damages arising from corrupted input files.
                        </p>
                    </section>
                @endif
            </div>

            <div class="mt-10 pt-6 border-t border-slate-200">
                <a href="{{ route('home') }}" class="inline-flex items-center text-sm font-semibold text-indigo-600 hover:text-indigo-800">
                    &larr; {{ __('ui.back_to_home') }}
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
