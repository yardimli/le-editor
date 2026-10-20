<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="{{ auth()->user()?->theme === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'LE Editor' }}</title>
    <script>try { document.documentElement.classList.toggle('dark', (localStorage.getItem('le-theme') || @js(auth()->user()?->theme ?? 'light')) === 'dark'); } catch(e) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="site-header">
        <a href="{{ url('/') }}" class="brand"><span class="brand-mark">le.</span><span>LE EDITOR<small>{{ __('editor.tagline') }}</small></span></a>
        <nav aria-label="Main" class="flex flex-wrap items-center gap-3 md:gap-6">
            @auth
                <a href="{{ route('books.index') }}" @class(['nav-active' => request()->is('books*')])>{{ __('editor.library') }}</a>
                <a href="{{ route('prompts') }}">{{ __('editor.presets') }}</a>
                <a href="{{ route('settings') }}">{{ __('editor.settings') }}</a>
                <a href="{{ route('profile.edit') }}">{{ __('editor.account') }}</a>
            @endauth
            <form method="POST" action="{{ route('locale') }}">@csrf
                <input type="hidden" name="locale" value="{{ app()->getLocale() === 'tr' ? 'en' : 'tr' }}">
                <button class="quiet-button" type="submit">{{ app()->getLocale() === 'tr' ? 'EN' : 'TR' }}</button>
            </form>
            <button class="quiet-button" id="theme-toggle" aria-label="{{ __('editor.theme') }}">◐</button>
            @auth
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="quiet-button">{{ __('editor.logout') }}</button></form>
            @else
                <a href="{{ route('login') }}">{{ __('editor.login') }}</a>
            @endauth
        </nav>
    </header>
    <main class="page-shell">
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @include('components.browser-queue')
        @yield('content')
    </main>
    <footer class="page-shell py-8 muted text-xs">LE Editor · {{ __('editor.tagline') }}</footer>
    @stack('scripts')
</body>
</html>
