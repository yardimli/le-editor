@extends('layouts.editor')
@section('work-controls')
            <div class="work-footer-status"><span id="work-label" role="status">{{ __('editor.no_run') }}</span><progress id="work-progress" max="100" value="0" aria-label="{{ __('editor.editorial_progress') }}"></progress><span id="work-error" class="hint" role="alert"></span></div>
            <div class="flex gap-2 shrink-0"><button type="button" class="button-secondary" id="open-setup">{{ __('editor.setup_title') }}</button><button type="button" class="button-secondary" id="open-export">{{ __('editor.export') }}</button><button type="button" id="pause-work" class="button-secondary hidden">{{ __('editor.pause') }}</button><button type="button" id="resume-work" class="button hidden">{{ __('editor.resume') }}</button><button type="button" id="restart-work" class="button-secondary hidden">{{ __('editor.restart') }}</button></div>

@endsection
@section('content')
<div id="book-editor">
    <div class="page-heading editor-heading"><h1 id="book-title">{{ $book->title }}</h1></div>
    <div id="editor-message" class="notice hidden" role="alert"></div>
    <div id="metadata-message" class="notice {{ $book->metadata_status === 'complete' ? 'hidden' : '' }}">{{ __('editor.metadata_'.$book->metadata_status) }}</div>
    <div id="book-blocks" class="space-y-8"></div>
    <div id="editor-parking" class="hidden"><div id="block-edit-panel" class="hidden">
        <div id="save-indicator" class="save-indicator hidden" role="status"><span>{{ __('editor.saved') }}</span><button type="button" class="underline ml-2 hidden" id="save-now">{{ __('editor.save_now') }}</button></div>
        <p class="hint mb-3">{{ __('editor.chunk_edit_hint') }}</p><div id="chunk-editor"></div>
    </div></div>
    <dialog id="setup-dialog" aria-labelledby="setup-title" aria-describedby="setup-hint" class="editor-dialog setup-dialog">
        <h2 id="setup-title">{{ __('editor.setup_title') }}</h2>
        <p id="setup-hint" class="muted mt-3 mb-6">{{ __('editor.setup_hint') }}</p>
        <div id="setup-error" class="notice error hidden" role="alert"></div>
        <div class="setup-grid">
            <section class="panel">
                    <div><h3 class="font-semibold text-sm">{{ __('editor.presets') }}</h3>
                        <fieldset class="flex gap-3 mt-3"><legend class="sr-only">{{ __('editor.genre') }}</legend>
                            @foreach(['fiction', 'nonfiction'] as $genre)
                                <label class="check-label preset-option flex-1"><input type="radio" name="editorial_genre" form="book-settings" value="{{ $genre }}" @checked($book->editorial_genre === $genre)> {{ __('editor.'.$genre) }}</label>
                            @endforeach
                        </fieldset>
                        <div class="setup-presets mt-3 grid gap-3">
                            @foreach($presets as $preset)
                                <label class="check-label preset-option items-start" data-preset-genre="{{ $preset->genre }}"><input type="checkbox" class="preset-checkbox mt-1" value="{{ $preset->id }}" data-preset-key="{{ str_starts_with($preset->slug ?? '', 'editorial-') ? substr($preset->slug, strlen('editorial-'.$preset->genre.'-')) : 'preset-'.$preset->id }}" data-instruction="{{ $preset->instructions[app()->getLocale()] }}"><span>{{ $preset->names[app()->getLocale()] }}</span></label>
                            @endforeach
                        </div>
                        <p class="hint">{{ __('editor.setup_presets_hint') }}</p>
                    </div>
            </section>
            <div class="space-y-5">
            <section class="panel setup-main">
                <p class="eyebrow">01 / {{ __('editor.prompt') }}</p>
                <form id="book-settings" class="space-y-4 mt-5">
                    <div><label for="book-prompt">{{ __('editor.prompt') }}</label><textarea id="book-prompt" name="prompt" rows="6" maxlength="10000" required aria-describedby="prompt-live-status">{{ $book->prompt }}</textarea></div>
                    <p id="prompt-live-status" class="hint" role="status" aria-live="polite"></p>
                    <input type="hidden" id="book-name" name="title" value="{{ $book->title }}">
                    <input type="hidden" id="book-language" name="language" value="{{ $book->language }}">
                    <input type="hidden" id="book-context" name="context" value="{{ $book->context }}">
                </form>
                <p class="hint mt-3" id="learning-status" role="status"></p><button class="quiet-button hidden text-accent" id="retry-learning">{{ __('editor.learn_now') }}</button>
            </section>
            <section class="panel">
                <p class="eyebrow">02 / {{ __('editor.run') }}</p>
                <form id="run-form" class="space-y-4 mt-5">
                    <div><label for="run-model">{{ __('editor.model') }}</label><select id="run-model" name="model" required data-model-select data-current="{{ $book->model }}"><option value="">{{ __('editor.loading_models') }}</option></select><p class="hint model-error" role="alert"></p></div>
                    <input type="hidden" id="chunk-size" value="{{ $book->chunk_size }}">
                    <div id="thinking-control" hidden><label for="thinking-level">{{ __('editor.thinking') }}</label><select id="thinking-level"><option value="low" @selected(($book->activeRun?->reasoning_effort ?? 'low') === 'low')>{{ __('editor.thinking_low') }}</option><option value="medium" @selected(($book->activeRun?->reasoning_effort ?? 'low') === 'medium')>{{ __('editor.thinking_medium') }}</option><option value="high" @selected(($book->activeRun?->reasoning_effort ?? 'low') === 'high')>{{ __('editor.thinking_high') }}</option></select><p class="hint">{{ __('editor.thinking_hint') }}</p></div>
                    <p class="hint" id="run-scope-hint">{{ __('editor.rerun_hint') }}</p>
                    <p class="text-sm" id="cost-estimate"></p><p class="hint">{{ __('editor.cost_hint') }}</p>

                </form>
            </section>
        </div>
        </div>
        <div class="setup-actions">
            <form action="{{ route('books.destroy', $book) }}" method="POST" data-confirm="{{ __('editor.delete_confirm') }}">@csrf @method('DELETE')<button class="quiet-button muted">{{ __('editor.delete') }}</button></form>
            <div class="flex gap-3"><button type="button" class="button-secondary" id="cancel-setup">{{ __('editor.setup_cancel') }}</button><button type="submit" form="run-form" class="button" id="run-button">{{ __('editor.run') }} →</button></div>
        </div>
    </dialog>
    <dialog id="model-warning-dialog" class="editor-dialog" aria-labelledby="model-warning-title" aria-describedby="model-warning-copy">
        <h2 id="model-warning-title">{{ __('editor.model_warning_title') }}</h2>
        <p id="model-warning-copy" class="muted my-4">{{ __('editor.model_warning_copy') }}</p>
        <p class="text-sm mb-2">{{ __('editor.model_selected') }}: <strong id="warning-selected-model"></strong></p>
        <p class="text-sm mb-4">{{ __('editor.model_recommended') }}: <strong id="warning-default-model"></strong></p>
        <label class="check-label"><input type="checkbox" id="keep-selected-model"> {{ __('editor.keep_selected_model') }}</label>
        <div class="dialog-actions">
            <button type="button" class="button" id="confirm-model-start">{{ __('editor.start_editing') }}</button>
            <button type="button" class="button-secondary" id="cancel-model-warning">{{ __('editor.setup_cancel') }}</button>
        </div>
    </dialog>
    <dialog id="run-scope-dialog" class="editor-dialog" aria-labelledby="run-scope-title">
        <h2 id="run-scope-title">{{ __('editor.run_scope_title') }}</h2>
        <p class="muted my-4">{{ __('editor.run_scope_hint') }}</p>
        <div class="dialog-actions">
            <button type="button" class="button" data-run-scope="unfinished">{{ __('editor.continue_unfinished') }}</button>
            <button type="button" class="button-secondary" data-run-scope="all">{{ __('editor.redo_all') }}</button>
            <button type="button" class="button-secondary" data-run-scope="cancel">{{ __('editor.setup_cancel') }}</button>
        </div>
    </dialog>
    <dialog id="export-dialog" aria-labelledby="export-title" class="editor-dialog">
        <h2 id="export-title">{{ __('editor.export_title') }}</h2><p class="muted my-5">{{ __('editor.export_hint') }}</p>
        <form action="{{ route('books.export', $book) }}" method="GET" id="export-form"><div class="dialog-actions"><button class="button">{{ __('editor.download') }}</button><button type="button" id="close-export" class="button-secondary">{{ __('editor.close') }}</button></div></form>
    </dialog>
</div>
@endsection
@push('scripts')
<script>
window.leStrings = @js(__('editor'));
window.leBook = @js(['id' => $book->id, 'version' => $book->version, 'promptVersion' => $book->prompt_version, 'locale' => app()->getLocale(), 'needsSetup' => $runs->isEmpty()]);
</script>
@endpush
