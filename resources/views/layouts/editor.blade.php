<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="{{ auth()->user()?->theme === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'LE Editor' }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <script>try { document.documentElement.classList.toggle('dark', (localStorage.getItem('le-theme') || @js(auth()->user()?->theme ?? 'light')) === 'dark'); } catch(e) {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="site-header">
        <a href="{{ url('/') }}" class="brand"><img src="{{ asset('images/le-editor-logo.png') }}" width="44" height="44" alt="" class="shrink-0"><span>LE EDITOR<small>{{ __('editor.tagline') }}</small></span></a>
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
    @auth
    <div class="status-band">
        <div class="page-shell status-band-inner">
            @hasSection('status-controls')
                @yield('status-controls')
            @else
                <a href="{{ route('books.index') }}" class="text-xs font-semibold shrink-0">LE EDITOR</a>
            @endif

        </div>
    </div>
    @endauth
    <main class="page-shell">
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @unless(request()->routeIs('books.index', 'books.api-history', 'prompts', 'settings', 'profile.*'))
            @include('components.browser-queue')
        @endunless
        @yield('content')
    </main>
    <footer class="page-shell py-8 muted text-xs">LE Editor · {{ __('editor.tagline') }}</footer>
    @auth
    <footer class="work-footer"><div class="page-shell work-footer-inner">
        @yield('work-controls')
            <div class="header-notification">
                <span id="header-ai-spinner" class="queue-spinner hidden" aria-hidden="true"></span>
                <span id="header-ai-status" role="status" aria-live="polite"></span>
                <span id="editor-learning-hint" class="hidden" title="{{ __('editor.learning_offer') }}">
                    <button type="button" id="learn-from-feedback" class="quiet-button underline">{{ __('editor.learning_hint_short') }}</button>
                    <button type="button" id="dismiss-learning" class="quiet-button ml-2" aria-label="{{ __('editor.dismiss') }}">×</button>
                </span>
                <button type="button" id="header-ai-cancel" class="quiet-button underline hidden">{{ __('editor.setup_cancel') }}</button>
            </div>
    </div></footer>
    @endauth
    @auth<script>window.leStrings = @js(__('editor'));</script>@endauth
    @stack('scripts')
</body>
</html>
