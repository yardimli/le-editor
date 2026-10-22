<dialog id="analysis-dialog" class="editor-dialog analysis-dialog" aria-labelledby="analysis-title" data-user="{{ auth()->id() }}">
    <h2 id="analysis-title">{{ __('editor.analyses') }}</h2>
    <form id="analysis-form" class="space-y-3 mt-3">
        <label class="block">{{ __('editor.model') }}<select name="model" required data-model-select><option value="">{{ __('editor.loading_models') }}</option></select><span class="model-error hint"></span></label>
        <label class="block">{{ __('editor.analysis_words') }}<input name="word_count" type="number" min="50" max="10000" value="300" required></label>
        <label class="block">{{ __('editor.prompt') }}<textarea name="prompt" rows="6" maxlength="10000" required></textarea></label>
        <button type="button" id="analysis-reset" class="quiet-button underline">{{ __('editor.analysis_reset') }}</button>
        <p class="hint">{{ __('editor.analysis_hint') }}</p>

    </form>
    <div id="analysis-status-panel" class="dialog-status" hidden><span id="analysis-spinner" class="queue-spinner" hidden></span><p id="analysis-status" role="status" aria-live="polite"></p></div>
    <pre id="analysis-result" class="analysis-result" hidden></pre>
    <div class="dialog-actions">
        <a id="analysis-history-link" class="button-secondary dialog-history">{{ __('editor.analyses') }}</a>
        <button id="analysis-cancel" type="button" class="button-secondary">{{ __('editor.setup_cancel') }}</button>
        <button id="analysis-retry" type="button" class="button" hidden>{{ __('editor.resume') }}</button>
        <button id="analysis-again" type="button" class="button" hidden>{{ __('editor.analysis_again') }}</button>
        <button id="analysis-submit" type="submit" form="analysis-form" class="button">{{ __('editor.analysis_create') }}</button>
    </div>
</dialog>
