@extends('layouts.editor')
@section('work-controls')
<div class="work-footer-status"><span id="work-label" role="status">{{ __('editor.no_run') }}</span><progress id="work-progress" max="100" value="0" aria-label="{{ __('editor.editorial_progress') }}"></progress><span id="work-error" class="hint" role="alert"></span></div>
<div class="flex gap-2 shrink-0"><a class="button-secondary" data-docx-download href="{{ route('books.export', $book) }}">{{ __('editor.export') }}</a><button type="button" id="pause-work" class="button-secondary hidden">{{ __('editor.pause') }}</button></div>
@endsection
@section('content')
<div id="book-editor" class="document-workspace">
    <div class="document-column">
        <div class="page-heading editor-heading"><h1 id="book-title">{{ $book->title }}</h1></div>
        <div id="editor-message" class="notice hidden" role="alert"></div>
        <div id="book-blocks" class="space-y-8"></div>
    </div>
    <aside id="setup-panel" class="editorial-chat" aria-labelledby="setup-title">
        <div class="chat-heading"><h2 id="setup-title">{{ __('editor.editorial_chat') }}</h2></div>
        <div id="chat-messages" role="log" aria-live="polite" aria-label="{{ __('editor.editorial_chat') }}"></div>
        <form id="chat-form" class="chat-composer">
            <label class="sr-only" for="chat-input">{{ __('editor.chat_request') }}</label>
            <textarea id="chat-input" rows="3" maxlength="4000" placeholder="{{ __('editor.chat_placeholder') }}"></textarea>
            <div class="chat-composer-actions"><span class="hint">{{ __('editor.chat_keyboard') }}</span><button class="button" id="chat-send">{{ __('editor.send') }}</button></div>
            <p id="chat-status" class="hint" role="status"></p>
        </form>
    </aside>
</div>
@endsection
@push('scripts')
<script>
window.leStrings = @js(__('editor'));
window.leBook = @js(['id' => $book->id, 'version' => $book->version, 'locale' => app()->getLocale()]);
</script>
@endpush
