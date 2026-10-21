export function startBrowserQueue({ $, request, t, window, document, setInterval, pollState }) {
    const dialog = $('#queue-dialog');
    let polling = false, stepping = false, leaving = false, offline = false, generation = 0, lastKind = null;
    function render(queue) {
        const task = queue.task;
        const learning = !queue.paused && task?.kind === 'learning';
        $('#header-ai-spinner').classList.toggle('hidden', queue.paused || (!task && !queue.working));
        $('#header-ai-cancel').classList.toggle('hidden', !learning);
        const otherBook = !queue.paused && window.leBook && task?.book_id && Number(task.book_id) !== Number(window.leBook.id);
        // The editor's main footer label already names the other book. Keep the spinner, not duplicate text.
        if (otherBook) $('#header-ai-status').textContent = '';
        else if (learning) $('#header-ai-status').textContent = t('queue_learning');
        else if (!queue.paused && task) $('#header-ai-status').textContent = t(`queue_${task.kind}`);
        else if (queue.paused) $('#header-ai-status').textContent = t('queue_paused_short');
        else if (!task && !queue.working && lastKind === 'learning') {
            $('#header-ai-status').textContent = t(queue.learning_failed ? 'learning_failed' : 'prompt_updated');
            lastKind = null;
        } else if (!task && !queue.working && lastKind) {
            $('#header-ai-status').textContent = t('completed');
            lastKind = null;
        }
        $('#queue-notice').classList.toggle('hidden', (!queue.paused || !!window.leBook) && !offline);
        $('#queue-notice-text').textContent = t(queue.paused ? 'queue_paused' : 'queue_connection');
        if (!queue.paused && task?.kind === 'metadata') {
            if (!dialog.open) dialog.showModal();
            $('#queue-task').textContent = task ? `${t(`queue_${task.kind}`)} · ${task.title}` : t('queue_waiting');
            const progress = $('#queue-progress');
            if (task?.total) {
                progress.value = Math.round(task.completed / task.total * 100);
                $('#queue-count').textContent = `${task.completed} / ${task.total} · ${progress.value}%`;
            } else {
                progress.removeAttribute('value');
                $('#queue-count').textContent = t('queue_working');
            }
        } else if (dialog.open) dialog.close();
        if (task) lastKind = task.kind;
        window.leQueueState = queue;
        window.dispatchEvent(new Event('queue-updated'));
    }
    async function tick() {
        if (polling || leaving || document.hidden || offline) return;
        polling = true;
        const epoch = generation;
        try {
            const queue = await (pollState ? pollState() : request('/queue/state'));
            if (!queue) return;
            if (epoch !== generation || leaving) return;
            render(queue);
            // Polling remains available while the long completion request is running.
            if (!stepping && !queue.paused && !queue.working && queue.task) {
                stepping = true;
                request('/queue/step', 'POST', {}).then(result => {
                    if (epoch === generation && !leaving) render(result);
                }).catch(error => {
                    if (epoch === generation && !leaving) {
                        offline = true;
                        $('#queue-error').textContent = error.message;
                        $('#queue-notice').classList.remove('hidden');
                        $('#queue-notice-text').textContent = t('queue_connection');
                        $('#header-ai-status').textContent = t('queue_connection');
                    }
                }).finally(() => { stepping = false; });
            }
        } catch (error) {
            $('#queue-error').textContent = error.message;
            $('#queue-notice').classList.remove('hidden');
            $('#queue-notice-text').textContent = t('queue_connection');
        } finally { polling = false; }
    }
    dialog.addEventListener('cancel', event => event.preventDefault());
    const cancel = async event => {
        event.target.disabled = true;
        generation++;
        try {
            render(await request('/queue/cancel', 'POST', {}));
            offline = false;
            $('#queue-error').textContent = '';
        } catch (error) { $('#queue-error').textContent = error.message; }
        finally { event.target.disabled = false; }
    };
    $('#queue-cancel').addEventListener('click', cancel);
    $('#header-ai-cancel').addEventListener('click', cancel);
    $('#queue-resume').addEventListener('click', async event => {
        event.target.disabled = true;
        generation++;
        try {
            offline = false;
            $('#queue-error').textContent = '';
            render(await request('/queue/resume', 'POST', {}));
            tick();
        } catch (error) { $('#queue-notice-text').textContent = error.message; }
        finally { event.target.disabled = false; }
    });
    window.addEventListener('pagehide', () => { leaving = true; });
    window.addEventListener('pageshow', () => { const returning = leaving; leaving = false; if (returning) tick(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    window.addEventListener('online', () => { offline = false; tick(); });
    window.addEventListener('queue-poll', () => { offline = false; tick(); });
    setInterval(tick, 3000);
    tick();
}
