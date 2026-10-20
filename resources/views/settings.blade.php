@extends('layouts.editor')
@section('content')
<div class="page-heading"><div><p class="eyebrow">LE EDITOR / {{ __('editor.settings') }}</p><h1>{{ __('editor.settings') }}</h1></div></div>
<div class="panel max-w-3xl">
    @if(config('editor.dev_mode'))<p class="notice">{{ __('editor.dev_notice') }}</p>@endif
    <form action="{{ route('settings.update') }}" method="POST" class="space-y-6">@csrf @method('PATCH')
        <div><label for="api-key">{{ __('editor.api_key') }}</label><input id="api-key" type="password" name="openrouter_api_key" autocomplete="new-password" placeholder="{{ auth()->user()->openrouter_api_key ? '••••••••••••••••' : 'sk-or-…' }}"><p class="hint">{{ __('editor.key_hint') }}</p>
            @if(auth()->user()->openrouter_api_key)<label class="check-label mt-3"><input type="checkbox" name="remove_key" value="1"> {{ __('editor.remove_key') }}</label>@endif
        </div>
        <div><label for="default-model">{{ __('editor.default_model') }}</label><select id="default-model" name="default_model" data-model-select data-current="{{ auth()->user()->default_model ?: config('editor.model') }}"><option value="">{{ __('editor.loading_models') }}</option></select><p class="hint">{{ __('editor.model_hint') }}</p><p class="hint model-error" role="alert"></p></div>
        <div class="grid sm:grid-cols-2 gap-5">
            <div><label for="locale">{{ __('editor.interface_language') }}</label><select id="locale" name="locale"><option value="tr" @selected(auth()->user()->locale === 'tr')>Türkçe</option><option value="en" @selected(auth()->user()->locale === 'en')>English</option></select></div>
            <div><label for="theme">{{ __('editor.appearance') }}</label><select id="theme" name="theme"><option value="light" @selected(auth()->user()->theme === 'light')>{{ __('editor.light') }}</option><option value="dark" @selected(auth()->user()->theme === 'dark')>{{ __('editor.dark') }}</option></select></div>
        </div>
        <button class="button">{{ __('editor.save') }}</button>
        <button type="button" class="button-secondary" id="test-key">{{ __('editor.test_key') }}</button><p id="key-result" role="status"></p>
    </form>
</div>
@endsection
@push('scripts')<script>window.leStrings = @js(__('editor'));</script>@endpush
