export function startBrowserQueue({ $, request, t, window, document, setInterval }) {
    const dialog = $('#queue-dialog');
    let polling = false, stepping = false, leaving = false, offline = false, generation = 0;
    function render(queue) {
        const task = queue.task;
        $('#queue-notice').classList.toggle('hidden', !queue.paused && !offline);
        $('#queue-notice-text').textContent = t(queue.paused ? 'queue_paused' : 'queue_connection');
        if (!queue.paused && (task || queue.working)) {
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
        window.dispatchEvent(new Event('queue-updated'));
    }
    async function tick() {
        if (polling || leaving || document.hidden || offline) return;
        polling = true;
        const epoch = generation;
        try {
            const queue = await request('/queue/state');
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
    $('#queue-cancel').addEventListener('click', async event => {
        event.target.disabled = true;
        generation++;
        try {
            render(await request('/queue/cancel', 'POST', {}));
            offline = false;
            $('#queue-error').textContent = '';
        } catch (error) { $('#queue-error').textContent = error.message; }
        finally { event.target.disabled = false; }
    });
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
    window.addEventListener('pageshow', () => { leaving = false; tick(); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    window.addEventListener('online', () => { offline = false; tick(); });
    setInterval(tick, 2000);
    tick();
}
