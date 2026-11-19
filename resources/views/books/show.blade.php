@extends('layouts.editor')
@section('work-controls')
            <div class="work-footer-status"><span id="work-label" role="status">{{ __('editor.no_run') }}</span><progress id="work-progress" max="100" value="0" aria-label="{{ __('editor.editorial_progress') }}"></progress><span id="work-error" class="hint" role="alert"></span></div>
            <div class="flex gap-2 shrink-0"><button type="button" class="button-secondary" id="open-setup">{{ __('editor.editorial_chat') }}</button><button type="button" class="button-secondary" id="open-export">{{ __('editor.export') }}</button><button type="button" id="pause-work" class="button-secondary hidden">{{ __('editor.pause') }}</button><button type="button" id="resume-work" class="button hidden">{{ __('editor.resume') }}</button><button type="button" id="restart-work" class="button-secondary hidden">{{ __('editor.restart') }}</button></div>

@endsection
@section('content')
<div id="book-editor" class="document-workspace"><div class="document-column">
    <div class="page-heading editor-heading"><h1 id="book-title">{{ $book->title }}</h1></div>
    <div id="editor-message" class="notice hidden" role="alert"></div>
    <div id="metadata-message" class="notice {{ $book->metadata_status === 'complete' ? 'hidden' : '' }}">{{ __('editor.metadata_'.$book->metadata_status) }}</div>
    <div id="book-blocks" class="space-y-8"></div>
    <div id="editor-parking" class="hidden"><div id="block-edit-panel" class="hidden">
        <div id="save-indicator" class="save-indicator hidden" role="status"><span>{{ __('editor.saved') }}</span><button type="button" class="underline ml-2 hidden" id="save-now">{{ __('editor.save_now') }}</button></div>
        <p class="hint mb-3">{{ __('editor.chunk_edit_hint') }}</p><div id="chunk-editor"></div>
    </div></div>
    </div>
    <aside id="setup-panel" class="editorial-chat panel" aria-labelledby="setup-title">
        <h2 id="setup-title">{{ __('editor.editorial_chat') }}</h2>
        <p id="setup-hint" class="hint">{{ __('editor.chat_hint') }}</p>
        <div id="setup-error" class="notice error hidden" role="alert"></div>
        <div id="chat-messages" role="log" aria-live="polite" aria-label="{{ __('editor.editorial_chat') }}"></div>
        <button type="button" id="retry-metadata" class="button-secondary hidden">{{ __('editor.retry_analysis') }}</button>
        <form id="chat-form" class="space-y-2">
            <label for="chat-input">{{ __('editor.chat_request') }}</label>
            <textarea id="chat-input" rows="3" maxlength="4000" required></textarea>
            <button class="button-secondary" id="chat-send">{{ __('editor.send') }}</button>
            <p id="chat-status" class="hint" role="status"></p>
        </form>
        <div class="setup-grid">
            <form id="book-settings" class="space-y-3">
                <label for="book-prompt">{{ __('editor.chosen_plan') }}</label>
                <textarea id="book-prompt" name="prompt" rows="5" maxlength="10000" required>{{ $book->prompt }}</textarea>
                <p id="prompt-live-status" class="hint" role="status"></p>
                <input type="hidden" id="book-name" name="title" value="{{ $book->title }}">
                <input type="hidden" id="book-language" name="language" value="{{ $book->language }}">
                <input type="hidden" id="book-context" name="context" value="{{ $book->context }}">
            </form>
            <form id="run-form" class="space-y-3">
                <input type="hidden" id="run-model" value="{{ auth()->user()->default_model ?: config('editor.model') }}">
                <input type="hidden" id="thinking-level" value="{{ auth()->user()->reasoning_effort }}">
                <input type="hidden" id="chunk-size" value="{{ $book->chunk_size }}">
                <a class="text-accent text-sm" href="{{ route('settings') }}">{{ __('editor.model') }} / {{ __('editor.thinking') }}</a>
                <label for="run-scope">{{ __('editor.run_scope_title') }}</label>
                <select id="run-scope"><option value="unfinished">{{ __('editor.continue_unfinished') }}</option><option value="all">{{ __('editor.redo_all') }}</option></select>
                <p id="run-scope-hint" class="hint"></p>
                <p id="cost-estimate" class="text-sm"></p>
                <button class="button w-full" id="run-button">{{ __('editor.start_editing') }}</button>
            </form>
        </div>
        <p class="hint" id="learning-status" role="status"></p><button class="quiet-button hidden text-accent" id="retry-learning">{{ __('editor.learn_now') }}</button>
    </aside>
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
