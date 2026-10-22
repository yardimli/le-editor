export function startBookAnalysis({ $, $$, request, t }) {
    const dialog = $('#analysis-dialog'); if (!dialog) return;
    const form = $('#analysis-form'), status = $('#analysis-status');
    const storageKey = `le-analysis-${dialog.dataset.user}`;
    let bookId, kind, active = null, timer = null, saving = Promise.resolve(), promptTimer, starting = false, stepping = false;
    const prompt = form.elements.prompt;
    function remember() { if (active) sessionStorage.setItem(storageKey, String(active.id)); else sessionStorage.removeItem(storageKey); }
    function message(text) { status.textContent = text; $('#analysis-status-panel').hidden = !text; }
    function failed(error) { message(error.message || t('analysis_failed')); $('#analysis-spinner').hidden = true; $('#analysis-retry').hidden = !active; }
    function render(item) {
        if (!item?.id || !['queued','running','completed','failed','cancelled'].includes(item.status)) throw new Error(t('analysis_failed'));
        active = item; bookId = item.book_id; kind = item.kind; remember();
        form.hidden = true; $('#analysis-submit').hidden = true;
        $('#analysis-title').textContent = t(`create_${kind}`);
        $('#analysis-history-link').href = `/books/${bookId}/analyses`;
        $('#analysis-result').textContent = item.result || '';
        $('#analysis-result').hidden = !item.result;
        $('#analysis-retry').hidden = !['failed','cancelled'].includes(item.status);
        $('#analysis-again').hidden = item.status !== 'completed';
        const running = ['queued', 'running'].includes(item.status);
        $('#analysis-spinner').hidden = true;
        message(t(`analysis_${item.status}`));
        $('#analysis-cancel').textContent = t(running ? 'setup_cancel' : 'close');
        if (!running) { clearTimeout(timer); sessionStorage.removeItem(storageKey); }
    }
    async function drive() {
        if (stepping || !active || !dialog.open || document.hidden || !['queued', 'running'].includes(active.status)) return;
        const id = active.id; stepping = true;
        $('#analysis-spinner').hidden = false; message(t('analysis_running'));
        $('#analysis-retry').hidden = true;
        try {
            const item = await request(`/analyses/${id}/step`, 'POST', {});
            if (active?.id !== id || !dialog.open || active.status === 'cancelled') return;
            render(item);
            if (['queued','running'].includes(item.status)) timer = setTimeout(drive, 3000);
        } catch (error) { failed(error); }
        finally { stepping = false; if (active?.id !== id && dialog.open) drive(); }
    }
    function savePrompt(reset = false) {
        clearTimeout(promptTimer);
        const data = {kind, prompt: prompt.value, reset};
        saving = saving.catch(() => {}).then(() => request('/analysis-prompts', 'PATCH', data));
        return saving;
    }
    prompt.addEventListener('input', () => { promptTimer = (clearTimeout(promptTimer), setTimeout(() => savePrompt().catch(e => {failed(e);}), 800)); });
    $('#analysis-reset').addEventListener('click', async () => {
        try { const prefs = await savePrompt(true); prompt.value = prefs.prompts[kind]; message(''); }
        catch (e) { failed(e); }
    });
    async function setup() {
        const prefs = await request('/analysis-prompts');
        clearTimeout(timer); active = null;
        prompt.value = prefs.prompts[kind]; form.hidden = false;
        $('#analysis-submit').hidden = false; $('#analysis-again').hidden = true;
        $('#analysis-retry').hidden = true; $('#analysis-result').hidden = true;
        form.elements.word_count.value = 300; $('#analysis-result').textContent = ''; message('');
        $('#analysis-spinner').hidden = true; $('#analysis-title').textContent = t(`create_${kind}`);
        $('#analysis-history-link').href = `/books/${bookId}/analyses`; $('#analysis-cancel').textContent = t('setup_cancel');
        if (!dialog.open) dialog.showModal();
    }
    $$('[data-analysis-book]').forEach(button => button.addEventListener('click', async () => {
        if (button.disabled) return;
        button.disabled = true;
        clearTimeout(timer); active = null; bookId = button.dataset.analysisBook; kind = button.dataset.analysisKind;
        try {
            const result = await request(`/books/${bookId}/analyses/latest?kind=${kind}`);
            if (result.analysis) { render(result.analysis); if (!dialog.open) dialog.showModal(); }
            else await setup();
        } catch (e) { failed(e); if (!dialog.open) dialog.showModal(); }
        finally { button.disabled = false; }
    }));
    $('#analysis-again').addEventListener('click', async () => {
        try { await setup(); } catch(e) { failed(e); }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (starting) return;
        starting = true; message(t('analysis_running')); $('#analysis-spinner').hidden = false; const button = $('#analysis-submit'); button.disabled = true;
        try {
            await savePrompt();
            const item = await request(`/books/${bookId}/analyses`, 'POST', {kind, model:form.elements.model.value, word_count:Number(form.elements.word_count.value), prompt:prompt.value});
            render(item); drive();
        } catch(e) {failed(e);}
        finally {starting=false; button.disabled=false; if (!stepping) $('#analysis-spinner').hidden = true;}
    });
    async function close() {
        if (starting) return;
        try {
            if (active && ['queued','running'].includes(active.status)) render(await request(`/analyses/${active.id}/cancel`, 'POST', {}));
            else if (!form.hidden) await savePrompt();
            clearTimeout(timer); dialog.close();
        } catch(e) {failed(e);}
    }
    $('#analysis-cancel').addEventListener('click', close);
    dialog.addEventListener('cancel', event => {event.preventDefault(); close();});
    async function reopen(id, resume) {
        try {
            render(await request(`/analyses/${id}${resume ? '/resume' : ''}`, resume ? 'POST' : 'GET', resume ? {} : undefined));
            if (!dialog.open) dialog.showModal(); drive();
        } catch(e) {sessionStorage.removeItem(storageKey); failed(e);}
    }
    $$('[data-analysis-resume]').forEach(button => button.addEventListener('click', () => reopen(button.dataset.analysisResume, true)));
    $('#analysis-retry').addEventListener('click', () => reopen(active.id, true));
    const remembered = sessionStorage.getItem(storageKey); if (remembered) reopen(remembered, false);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) {clearTimeout(timer); drive();} });
}
