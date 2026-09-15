<x-app-layout :title="__('ui.privacy_policy')">
    <div class="py-12 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="border-b border-slate-200 pb-6 mb-8">
                <h1 class="text-3xl font-extrabold text-slate-900 sm:text-4xl">
                    {{ __('ui.privacy_policy') }}
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    {{ app()->getLocale() === 'ar' ? 'آخر تحديث: سبتمبر 2026' : 'Last updated: September 2026' }}
                </p>
            </div>

            <div class="prose prose-slate max-w-none space-y-6 text-slate-700 leading-relaxed">
                @if(app()->getLocale() === 'ar')
                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">1. التزامنا بحماية خصوصيتك</h2>
                        <p>
                            في <strong>Ka7loot PDF</strong>، نعتبر خصوصية مستنداتك وبياناتك أولوية قصوى. تم تصميم بنيتنا التحتية بالكامل لتعمل بنظام المعالجة المؤقتة المؤتمتة دون أي تخزين دائم أو تتبع للمحتوى.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">2. المعالجة المؤقتة والعزل الأمني</h2>
                        <p>
                            عند رفع أي ملف PDF أو صورة للمعالجة:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li>يتم تخزين الملف في مجلد معزول تماماً ومحدد بمعرف مشفر عشوائي (UUID) داخل مساحة تخزين خاصة غير قابلة للوصول العام المباشر.</li>
                            <li>تتم معالجة المستندات عبر عمليات نظام معزولة مع منع تام لأي حقن للأوامر.</li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">3. الحذف التلقائي والإلغاء الفوري</h2>
                        <p>
                            تخضع جميع الملفات الأصلية والناتجة لسياسة حذف صارمة:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li><strong>الحذف التلقائي:</strong> يتم حذف جميع الملفات نهائياً من خوادمنا بشكل آلي خلال 60 دقيقة من المعالجة كحد أقصى.</li>
                            <li><strong>الحذف الفوري:</strong> يمكن للمستخدم النقر على زر "حذف الملفات الآن فوراً" في أي وقت لإتلاف المستندات ومجلده المعزول بشكل نهائي ولحظي.</li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">4. التعامل الآمن مع كلمات المرور</h2>
                        <p>
                            عند استخدام أدوات حماية أو فك تشفير مستندات PDF:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li>لا نقوم بحفظ أو تسجيل أو تخزين كلمات المرور الخاصة بك في أي قاعدة بيانات أو سجلات برمجية نهائياً.</li>
                            <li>تُستخدم كلمات المرور حصراً في الذاكرة اللحظية لتنفيذ عملية التشفير أو فك التشفير ثم تُحذف فوراً.</li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">5. روابط المشاركة المؤقتة ورموز QR</h2>
                        <p>
                            في حال اختيارك مشاركة المستند عبر رمز الاستجابة السريعة (QR Code):
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li>يتم إنشاء رمز مشفر عشوائي ومحمي بتجزئة مشفرة في قاعدة البيانات.</li>
                            <li>تنتهي صلاحية الرابط تلقائياً بعد المدة المحددة (ساعة إلى 24 ساعة) أو فور استنفاد عدد التحميلات المحدد (بحد أقصى 5 تحميلات).</li>
                            <li>يمكن لمنشئ الرابط إبطال المشاركة وحظر التحميل فوراً من واجهة الموقع.</li>
                        </ul>
                    </section>
                @else
                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">1. Our Privacy Commitment</h2>
                        <p>
                            At <strong>Ka7loot PDF</strong>, protecting the confidentiality of your documents and files is our utmost priority. Our platform architecture is engineered around ephemeral, isolated processing with zero permanent document retention.
                        </p>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">2. Ephemeral Storage & Strict Job Isolation</h2>
                        <p>
                            When you upload a PDF or image for processing:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li>Files are placed in a strictly isolated directory mapped to a cryptographically secure UUID inside private storage, completely inaccessible to the public web.</li>
                            <li>Processing executes within sandboxed external sub-processes using parameterized arguments with zero shell interpolation.</li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">3. Automatic & Immediate Deletion</h2>
                        <p>
                            All uploaded source files and generated outputs are governed by automated expiration:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li><strong>Automatic Purging:</strong> Files and job directories are permanently wiped by our automated cleanup worker within 60 minutes of processing.</li>
                            <li><strong>Immediate Deletion:</strong> You can click the "Delete Now" button at any time on the results screen to instantly and permanently shred all files from disk.</li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">4. Password Confidentiality</h2>
                        <p>
                            When using PDF protection or unlocking utilities:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li>Passwords are never logged, persisted in database tables, or stored on disk.</li>
                            <li>Passwords reside solely in ephemeral process memory for the execution lifecycle of the security operation and are immediately cleared.</li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-xl font-bold text-slate-900">5. Temporary QR Sharing</h2>
                        <p>
                            When generating a temporary QR share link:
                        </p>
                        <ul class="list-disc list-inside space-y-1 text-slate-600">
                            <li>Access is gated by a cryptographically secure 64-character token stored as an irreversible SHA-256 hash.</li>
                            <li>Tokens automatically expire after their designated TTL (1 to 24 hours) or upon reaching their download ceiling (max 5 downloads).</li>
                            <li>The owner retains the ability to instantly revoke access at any point prior to expiration.</li>
                        </ul>
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
