@extends('layouts.editor')
@section('content')
<section class="max-w-5xl mx-auto py-16 md:py-28 grid md:grid-cols-2 gap-16 items-center">
    <div><p class="eyebrow">LE EDITOR</p><h1 class="mt-5 text-5xl md:text-6xl leading-tight">{{ __('editor.tagline') }}</h1><p class="muted text-lg leading-relaxed mt-6">{{ __('editor.intro') }}</p><a class="button inline-flex mt-9" href="{{ route('register') }}">{{ __('editor.register') }} →</a></div>
    <div class="hero-page"><span class="eyebrow">01 / {{ __('editor.review') }}</span><p class="font-serif text-3xl leading-loose mt-12">{{ app()->getLocale() === 'tr' ? 'Her hikâye' : 'Every story deserves' }} <del>{{ app()->getLocale() === 'tr' ? 'biraz daha iyi' : 'a better' }}</del> <ins>{{ app()->getLocale() === 'tr' ? 'kendi sesiyle' : 'its own' }}</ins> {{ app()->getLocale() === 'tr' ? 'anlatılmayı hak eder.' : 'voice.' }}</p><div class="mt-12 border-t border-current/10 pt-5 text-xs muted">{{ __('editor.accept') }} ✓ &nbsp; / &nbsp; {{ __('editor.reject') }} ×</div></div>
</section>
@endsection
