<dialog id="analysis-dialog" class="editor-dialog setup-dialog" aria-labelledby="analysis-title" data-user="{{ auth()->id() }}">
    <h2 id="analysis-title">{{ __('editor.analyses') }}</h2>
    <form id="analysis-form" class="space-y-3 mt-3">
        <label class="block">{{ __('editor.model') }}<select name="model" required data-model-select><option value="">{{ __('editor.loading_models') }}</option></select><span class="model-error hint"></span></label>
        <label class="block">{{ __('editor.analysis_words') }}<input name="word_count" type="number" min="50" max="10000" value="300" required></label>
        <label class="block">{{ __('editor.prompt') }}<textarea name="prompt" rows="6" maxlength="10000" required></textarea></label>
        <button type="button" id="analysis-reset" class="quiet-button underline">{{ __('editor.analysis_reset') }}</button>
        <p class="hint">{{ __('editor.analysis_hint') }}</p>
        <button class="button">{{ __('editor.analysis_create') }}</button>
    </form>
    <div class="flex gap-2 items-center mt-4"><span id="analysis-spinner" class="queue-spinner" hidden></span><p id="analysis-status" role="status" aria-live="polite"></p></div>
    <pre id="analysis-result" class="whitespace-pre-wrap text-sm max-h-96 overflow-auto mt-4"></pre>
    <div class="flex gap-2 mt-4"><button id="analysis-cancel" type="button" class="button-secondary">{{ __('editor.setup_cancel') }}</button><a id="analysis-history-link" class="text-accent">{{ __('editor.analyses') }}</a></div>
</dialog>
