<x-app-layout :title="__('ui.security')">
    <div class="py-12 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="border-b border-slate-200 pb-6 mb-8">
                <h1 class="text-3xl font-extrabold text-slate-900 sm:text-4xl">
                    {{ __('ui.security') }}
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    {{ app()->getLocale() === 'ar' ? 'معايير الأمان والبنية التحتية' : 'Security Standards & Architecture' }}
                </p>
            </div>

            <div class="prose prose-slate max-w-none space-y-6 text-slate-700 leading-relaxed">
                @if(app()->getLocale() === 'ar')
                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">1. التشفير أثناء النقل (TLS 1.3)</h2>
                        <p>
                            تتم جميع الاتصالات بين متصفحك وخوادمنا عبر بروتوكول HTTPS المشفر باستخدام شهادات TLS المعتمدة، مما يمنع اعتراض البيانات أو التنصت عليها أثناء الرفع والتحميل.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">2. العزل الصارم لمجلدات المهام</h2>
                        <p>
                            يتم تخصيص مجلد مؤقت مستقل لكل مهمة معالجة داخل بيئة تخزين غير معلنة للعامة، مع استخدام معرفات مشفرة غير قابلة للتخمين (UUID v4)، مما يستحيل معه وصول أي مستخدم لملفات مستخدم آخر.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">3. التحقق الأمني متعدد الطبقات من الملفات</h2>
                        <p>
                            لا نعتمد على امتداد الملف الظاهري فحسب، بل نقوم بفحص البايتات السحرية (Magic Bytes) والتحقق من بنية الملفات الداخلية لمنع الهجمات المزدوجة وتعدد الامتدادات.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">4. تنفيذ آمن للعمليات الفرعية</h2>
                        <p>
                            تُنفذ جميع أدوات التحويل والمعالجة (PDFium, Ghostscript, Poppler) من خلال مصفوفات برمجية محصنة ضد حقن الأوامر مع حدود صارمة للذاكرة ووقت المعالجة.
                        </p>
                    </section>
                @else
                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">1. Encryption in Transit (TLS 1.3)</h2>
                        <p>
                            All network communication between your client and our platform is enforced over HTTPS via industry-standard TLS 1.3 encryption, ensuring complete confidentiality during file upload and download.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">2. Strict Per-Job Directory Isolation</h2>
                        <p>
                            Every processing task is assigned an unguessable UUID v4 directory located outside public web roots. Directory traversal safeguards strictly verify boundaries to prevent unauthorized cross-tenant file access.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">3. Multi-Layer File Validation</h2>
                        <p>
                            Client-provided extensions are verified against true magic-byte MIME inspections (`FILEINFO_MIME_TYPE`). Polyglot files and mismatched binaries are actively rejected prior to processing.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">4. Parameterized Sandboxed Subprocesses</h2>
                        <p>
                            External utilities (Google PDFium, Ghostscript, Poppler) are invoked strictly via parameterized process arrays without shell string interpolation, eliminating command injection vectors.
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
