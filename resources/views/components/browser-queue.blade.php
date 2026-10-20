@auth
    <div id="queue-notice" class="notice hidden" role="status">
        <span id="queue-notice-text"></span>
        <button type="button" class="button-secondary ml-3" id="queue-resume">{{ __('editor.queue_resume') }}</button>
    </div>
    <dialog id="queue-dialog" class="editor-dialog" aria-labelledby="queue-title">
        <div class="flex items-center gap-3 mb-5"><span class="queue-spinner" aria-hidden="true"></span><h2 id="queue-title">{{ __('editor.queue_working') }}</h2></div>
        <p id="queue-task" class="muted my-4"></p>
        <progress id="queue-progress" max="100" class="w-full"></progress>
        <p id="queue-count" class="text-sm mt-3" aria-live="polite"></p>
        <p class="muted text-sm mt-4">{{ __('editor.queue_hint') }}</p>
        <p id="queue-error" class="error mt-3" role="alert"></p>
        <button type="button" class="button-secondary w-full mt-7" id="queue-cancel">{{ __('editor.queue_cancel') }}</button>
    </dialog>
    <script>window.leQueue = true; window.leStrings = @js(__('editor'));</script>
@endauth
