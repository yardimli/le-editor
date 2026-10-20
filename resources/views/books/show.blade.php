@extends('layouts.editor')
@section('content')
<div id="book-editor">
    <div class="page-heading">
        <div><a href="{{ route('books.index') }}" class="eyebrow">← {{ __('editor.back') }}</a><h1 id="book-title" class="mt-3">{{ $book->title }}</h1></div>
        <button class="button-secondary" id="open-export">{{ __('editor.export') }} ↗</button>
    </div>
    <div id="editor-message" class="notice hidden" role="alert"></div>
    <div id="metadata-message" class="notice {{ $book->metadata_status === 'complete' ? 'hidden' : '' }}">{{ __('editor.metadata_'.$book->metadata_status) }}</div>
    <div class="editor-grid">
        <aside class="space-y-5">
            <section class="panel">
                <p class="eyebrow">01 / {{ __('editor.prompt') }}</p>
                <form id="book-settings" class="space-y-4 mt-5">
                    <div><label for="book-name">{{ __('editor.title') }}</label><input id="book-name" name="title" value="{{ $book->title }}" required maxlength="255"></div>
                    <div><label for="book-language">{{ __('editor.language') }}</label><select id="book-language" name="language"><option value="tr" @selected($book->language === 'tr')>Türkçe</option><option value="en" @selected($book->language === 'en')>English</option></select></div>
                    <details><summary class="cursor-pointer font-semibold text-sm">{{ __('editor.presets') }}</summary>
                        <div class="preset-list mt-3 space-y-3">
                            @foreach($presets as $preset)
                                <label class="check-label items-start"><input type="checkbox" class="preset-checkbox mt-1" value="{{ $preset->id }}" data-instruction="{{ $preset->instructions[app()->getLocale()] }}"><span>{{ $preset->names[app()->getLocale()] }}<small class="block muted">{{ __('editor.'.$preset->genre) }} · {{ __('editor.level_'.$preset->intensity) }}</small></span></label>
                            @endforeach
                        </div>
                        <p class="hint">{{ __('editor.combine_hint') }}</p><button type="button" class="button-secondary mt-3 w-full" id="combine-presets">{{ __('editor.combine') }}</button>
                    </details>
                    <div><label for="book-prompt">{{ __('editor.prompt') }}</label><textarea id="book-prompt" name="prompt" rows="8" maxlength="10000">{{ $book->prompt }}</textarea></div>
                    <div><label for="book-context">{{ __('editor.context') }}</label><textarea id="book-context" name="context" rows="3" maxlength="10000" placeholder="{{ __('editor.context_hint') }}">{{ $book->context }}</textarea></div>
                    <label class="check-label"><input name="learn" type="checkbox" @checked($book->learn)> {{ __('editor.learn') }}</label>
                    <button class="button-secondary w-full">{{ __('editor.save_settings') }}</button>
                </form>
                <p class="hint mt-3" id="learning-status" role="status"></p><button class="quiet-button hidden text-accent" id="retry-learning">{{ __('editor.retry_learning') }}</button>
            </section>
            <section class="panel">
                <p class="eyebrow">02 / {{ __('editor.run') }}</p>
                <form id="run-form" class="space-y-4 mt-5">
                    <div><label for="run-model">{{ __('editor.model') }}</label><select id="run-model" name="model" required data-model-select data-current="{{ $book->model }}"><option value="">{{ __('editor.loading_models') }}</option></select><p class="hint model-error" role="alert"></p></div>
                    <div><label for="chunk-size">{{ __('editor.chunk_size') }}</label><input id="chunk-size" name="chunk_size" type="number" min="100" max="10000" value="{{ $book->chunk_size }}" required></div>
                    <p class="hint">{{ __('editor.rerun_hint') }}</p>
                    <p class="text-sm" id="cost-estimate"></p><p class="hint">{{ __('editor.cost_hint') }}</p>
                    <button class="button w-full" id="run-button">{{ __('editor.run') }} →</button>
                </form>
            </section>
            <form action="{{ route('books.destroy', $book) }}" method="POST" data-confirm="{{ __('editor.delete_confirm') }}">@csrf @method('DELETE')<button class="quiet-button muted">{{ __('editor.delete') }}</button></form>
        </aside>
        <section class="min-w-0">
            <div class="manuscript-toolbar">
                <div class="flex gap-1 flex-wrap" role="tablist">
                    @foreach(['review', 'manual', 'preview', 'history'] as $tab)
                        <button class="tab-button {{ $tab === 'review' ? 'active' : '' }}" data-tab="{{ $tab }}" role="tab" aria-selected="{{ $tab === 'review' ? 'true' : 'false' }}">{{ __('editor.'.$tab) }}</button>
                    @endforeach
                </div>
                <div class="flex gap-2"><button class="quiet-button" id="prev-change" aria-label="{{ __('editor.previous') }}">↑</button><button class="quiet-button" id="next-change" aria-label="{{ __('editor.next') }}">↓</button></div>
            </div>
            <div class="flex flex-wrap gap-4 text-xs muted px-4 py-3" id="review-counts" aria-live="polite"></div>
            <div id="run-status" class="notice hidden"><span></span><button class="button-secondary ml-3 hidden" id="retry-run">{{ __('editor.retry') }}</button></div>
            <div class="manuscript-sheet">
                <div id="save-indicator" class="save-indicator hidden" role="status"><span>{{ __('editor.saved') }}</span><button type="button" class="underline ml-2 hidden" id="save-now">{{ __('editor.save_now') }}</button></div>
                <div data-panel="review" id="diff-view" class="manuscript-text"></div>
                <div data-panel="manual" class="hidden">
                    <p class="notice text-sm">{{ __('editor.manual_hint') }}</p>
                    <textarea id="manuscript-input" class="manuscript-text" aria-label="{{ __('editor.manual') }}" spellcheck="true">{{ $manuscript }}</textarea>
                </div>
                <div data-panel="preview" class="hidden manuscript-text whitespace-pre-wrap" id="clean-preview">{{ $manuscript }}</div>
                <div data-panel="history" class="hidden space-y-8">
                    <section><h2>{{ __('editor.runs') }}</h2>@foreach($runs as $run)<details class="history-item"><summary>#{{ $run->id }} · {{ __('editor.'.$run->status) }} · {{ $run->created_at->format('d.m.Y H:i') }}</summary><p class="hint">{{ $run->model }} · {{ $run->chunk_size }} {{ __('editor.words') }} · {{ $run->tokens }} {{ __('editor.tokens') }} · ${{ $run->cost }}</p><pre class="whitespace-pre-wrap text-sm mt-3">{{ $run->prompt }}</pre><a class="text-accent text-sm inline-block mt-3" href="{{ route('books.history', [$book, $run]) }}">{{ __('editor.review') }} →</a></details>@endforeach</section>
                    <section><h2>{{ __('editor.manuscript_versions') }}</h2>@foreach($versions as $version)<form class="history-item flex justify-between items-center gap-3" method="POST" action="{{ route('books.restore', $book) }}" data-confirm="{{ __('editor.restore_confirm') }}">@csrf<input type="hidden" name="kind" value="manuscript"><input type="hidden" name="id" value="{{ $version->id }}"><span>#{{ $version->id }} · {{ $version->created_at->format('d.m.Y H:i') }}</span><button class="quiet-button text-accent">{{ __('editor.restore') }}</button></form>@endforeach</section>
                    <section><h2>{{ __('editor.prompt_versions') }}</h2>@foreach($promptRevisions as $revision)<details class="history-item"><summary>#{{ $revision->id }} · {{ $revision->created_at->format('d.m.Y H:i') }}</summary><pre class="whitespace-pre-wrap text-sm my-3">{{ $revision->prompt }}</pre><form method="POST" action="{{ route('books.restore', $book) }}" data-confirm="{{ __('editor.restore_confirm') }}">@csrf<input type="hidden" name="kind" value="prompt"><input type="hidden" name="id" value="{{ $revision->id }}"><button class="quiet-button text-accent">{{ __('editor.restore') }}</button></form></details>@endforeach</section>
                </div>
            </div>
        </section>
    </div>
    <dialog id="export-dialog" aria-labelledby="export-title" class="editor-dialog">
        <h2 id="export-title">{{ __('editor.export_title') }}</h2><p class="muted my-5">{{ __('editor.export_hint') }}</p>
        <form action="{{ route('books.export', $book) }}" method="GET" id="export-form"><label class="check-label"><input type="checkbox" name="include_pending" value="1"> {{ __('editor.include_pending') }}</label><div class="flex gap-3 mt-7"><button class="button">{{ __('editor.download') }}</button><button type="button" id="close-export" class="button-secondary">{{ __('editor.close') }}</button></div></form>
    </dialog>
</div>
@endsection
@push('scripts')
<script>
window.leStrings = @js(__('editor'));
window.leBook = @js(['id' => $book->id, 'version' => $book->version, 'promptVersion' => $book->prompt_version, 'locale' => app()->getLocale(), 'text' => $manuscript]);
</script>
@endpush
