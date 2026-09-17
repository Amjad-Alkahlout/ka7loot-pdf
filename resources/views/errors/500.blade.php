<x-app-layout :title="__('ui.error_500_title')" :noindex="true">
    <div class="min-h-[60vh] flex items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
        <div class="max-w-md w-full text-center">
            <p class="text-6xl font-extrabold text-rose-600">500</p>
            <h1 class="mt-4 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                {{ __('ui.error_500_title') }}
            </h1>
            <p class="mt-3 text-base text-slate-600">
                {{ __('ui.error_500_desc') }}
            </p>
            <div class="mt-8">
                <a href="{{ route('home') }}" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 transition">
                    {{ __('ui.back_to_home') }}
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
