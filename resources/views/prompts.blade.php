@extends('layouts.editor')
@section('content')
<div class="page-heading"><div><p class="eyebrow">LE EDITOR / {{ __('editor.presets') }}</p><h1>{{ __('editor.presets') }}</h1></div></div>
<div class="grid lg:grid-cols-[1fr_350px] gap-8 items-start">
    <section class="grid md:grid-cols-2 gap-4">
        @foreach($presets as $preset)
            <article class="panel"><div class="flex gap-2"><span class="pill">{{ __('editor.'.$preset->genre) }}</span><span class="pill">{{ __('editor.level_'.$preset->intensity) }}</span></div>
                <h2 class="mt-4 text-xl">{{ $preset->names[app()->getLocale()] }}</h2><p class="muted text-sm mt-3 leading-relaxed">{{ $preset->instructions[app()->getLocale()] }}</p>
                <div class="mt-5 flex gap-3"><button class="quiet-button text-accent" data-copy-preset="{{ json_encode(['id' => $preset->user_id ? $preset->id : '', 'name' => $preset->names[app()->getLocale()], 'instruction' => $preset->instructions[app()->getLocale()], 'genre' => $preset->genre, 'intensity' => $preset->intensity]) }}">{{ __('editor.duplicate') }}</button>
                @if($preset->user_id)<form action="{{ route('prompts.delete', $preset) }}" method="POST" data-confirm="{{ __('editor.delete_confirm') }}">@csrf @method('DELETE')<button class="quiet-button">{{ __('editor.delete') }}</button></form>@endif</div>
            </article>
        @endforeach
    </section>
    <aside class="panel lg:sticky lg:top-6"><h2>{{ __('editor.new_prompt') }}</h2>
        <form id="preset-form" action="{{ route('prompts.save') }}" method="POST" class="space-y-4 mt-5">@csrf
            <input type="hidden" name="id">
            <div><label for="preset-name">{{ __('editor.name') }}</label><input id="preset-name" name="name" required maxlength="100"></div>
            <div><label for="genre">{{ __('editor.genre') }}</label><select id="genre" name="genre">@foreach(['general','fiction','nonfiction','poetry'] as $value)<option value="{{ $value }}">{{ __('editor.'.$value) }}</option>@endforeach</select></div>
            <div><label for="intensity">{{ __('editor.intensity') }}</label><select id="intensity" name="intensity">@foreach(['light','medium','deep'] as $value)<option value="{{ $value }}">{{ __('editor.level_'.$value) }}</option>@endforeach</select></div>
            <div><label for="instruction">{{ __('editor.instruction') }}</label><textarea id="instruction" name="instruction" rows="9" required maxlength="10000"></textarea></div>
            <button class="button">{{ __('editor.save') }}</button><button class="quiet-button" type="reset">{{ __('editor.new_prompt') }}</button>
        </form>
    </aside>
</div>
@endsection
