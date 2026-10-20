import Quill from 'quill';
import 'quill/dist/quill.core.css';
import { chunkText, changeKey, boundedChunk } from './chunk-text';

export function startBookEditor({ $, $$, request, t, getModels }) {
    const book = window.leBook, base = `/books/${book.id}`;
    const setup = $('#setup-dialog'), settings = $('#book-settings');
    const quill = new Quill('#chunk-editor', { modules: { toolbar: false, history: { userOnly: true } }, formats: [] });
    quill.root.setAttribute('aria-label', t('edit_chunk'));
    quill.root.setAttribute('role', 'textbox');
    quill.root.setAttribute('aria-multiline', 'true');
    quill.enable(false);
    let state = null, current = null, busy = false, settingsDirty = false;
    let dirty = false, saving = null, deadline = 0, polling = null, signature = '', focusedChange = -1;
    let number = 1;
    try { number = Math.max(1, Number(sessionStorage.getItem(`le-chunk-${book.id}`)) || 1); } catch {}
    const text = () => quill.getText().slice(0, -1);
    const message = (copy, error = false) => {
        const el = $('#editor-message'); el.textContent = copy;
        el.classList.toggle('hidden', !copy); el.classList.toggle('error', error);
    };
    const indicator = () => {
        $('#save-indicator span').textContent = saving ? t('saving') : dirty
            ? `${t('modified')} ${Math.max(0, Math.ceil((deadline - Date.now()) / 1000))} ${t('seconds')}` : t('saved');
        $('#save-now').classList.toggle('hidden', !dirty || !!saving);
    };
    function estimate() {
        const model = getModels().find(m => m.id === $('#run-model').value);
        if (!model) return;
        const words = state?.word_count || 0, tokens = Math.ceil(words * 2);
        const chunks = Math.max(1, Math.ceil(words / Number($('#chunk-size').value || 2500)));
        const promptTokens = Math.ceil(($('#book-prompt').value.length + $('#book-context').value.length + 1000) / 3);
        $('#cost-estimate').textContent = `${t('estimate')}: ~$${(((tokens + chunks * promptTokens) * model.input_price + tokens * model.output_price) / 1e6).toFixed(3)}`;
    }
    function showChunk(chunk, force = false) {
        if (!chunk || dirty || saving) return;
        if (current?.id === chunk.id && Number(chunk.revision) < Number(current?.revision)) return;
        const key = `${state?.run?.id}-${chunk.position}-${chunk.revision}-${chunk.status}`;
        if (!force && key === signature) return;
        const switched = current?.id !== chunk.id || current?.position !== chunk.position;
        const selection = quill.getSelection();
        current = chunk; signature = key;
        if (switched || text() !== chunkText(chunk)) {
            quill.setText(chunkText(chunk) + '\n', 'silent');
            quill.history.clear();
            if (!switched && selection) quill.setSelection(Math.min(selection.index, quill.getLength() - 1), 0, 'silent');
        }
        quill.enable(chunk.status === 'completed' && !!chunk.id);
        $('#chunk-unprocessed').classList.toggle('hidden', chunk.status === 'completed');
        $('#chunk-edit-count').textContent = `${t('ai_edit_count')}: ${chunk.ai_edit_count || 0}`;
        $('#clean-preview').textContent = chunkText(chunk, false);
        renderDiff(chunk);
        indicator();
    }
    function renderDiff(chunk) {
        const view = $('#diff-view'); view.replaceChildren();
        const counts = { accepted: 0, rejected: 0, pending: 0 };
        const body = document.createElement('div'); body.className = 'whitespace-pre-wrap'; view.append(body);
        if (!chunk.segments) body.textContent = chunkText(chunk);
        (chunk.segments || []).forEach((segment, index) => {
            if (segment.type === 'equal') { body.append(document.createTextNode(segment.after)); return; }
            counts[segment.status]++;
            const span = document.createElement('span'); span.className = 'change'; span.dataset.status = segment.status;
            if (segment.before) { const del = document.createElement('del'); del.textContent = segment.before; span.append(del); }
            if (segment.after) { const ins = document.createElement('ins'); ins.textContent = segment.after; span.append(ins); }
            const tools = document.createElement('span'); tools.className = 'change-tools';
            if (segment.origin === 'manual') {
                const label = document.createElement('span'); label.textContent = t('manual_revision'); tools.append(label);
            }
            const actions = segment.status === 'pending' ? [['accepted', 'accept'], ['rejected', 'reject']] : [['pending', 'undo']];
            if (chunk.status === 'completed') actions.forEach(([status, label]) => {
                const button = document.createElement('button'); button.type = 'button'; button.textContent = t(label);
                const stableKey = changeKey(chunk.segments, index);
                button.addEventListener('click', async () => {
                    if (busy) return;
                    busy = true; button.disabled = true;
                    try {
                        await save();
                        // Manual edits can split suggestions. Never apply a stale numeric index.
                        const freshIndex = current.segments.findIndex((_, i) => changeKey(current.segments, i) === stableKey);
                        if (freshIndex < 0) { message(t('suggestion_changed')); return; }
                        const result = await request(`${base}/chunks/${current.id}`, 'PATCH', {
                            index: freshIndex, status, revision: current.revision,
                        });
                        book.version = result.version;
                        showChunk(result.chunk, true);
                        $('#header-ai-status').textContent = t('decision_saved');
                        await refresh(true);
                    } catch (error) { message(error.message, true); }
                    finally { busy = false; button.disabled = false; }
                });
                tools.append(button);
            });
            span.append(tools); body.append(span);
        });
        $('#review-counts').textContent = Object.entries(counts).map(([key, value]) => `${t(key)}: ${value}`).join(' · ');
    }
    function renderProgress() {
        const run = state?.run, total = state?.chunk_count || 1;
        const completed = run?.completed_chunks || 0, percent = Math.round(completed / total * 100);
        $('#chunk-position').textContent = `${number} / ${total}`;
        $('#previous-chunk').disabled = number <= 1;
        $('#next-chunk').disabled = number >= total;
        $('#book-progress').value = percent;
        $('#book-progress-label').textContent = `${t('processed')}: ${completed} / ${total} · ${percent}%`;
        $('#work-progress').value = percent;
        const running = ['queued', 'running'].includes(run?.status);
        const paused = window.leQueueState?.paused;
        $('#work-label').textContent = run
            ? `${t(paused && running ? 'cancelled' : run.status)} · ${completed} / ${total} · ${percent}%` : t('no_run');
        $('#work-error').textContent = run?.error ? t(run.error) : '';
        $('#pause-work').classList.toggle('hidden', !running || paused);
        $('#resume-work').classList.toggle('hidden', !run || (!paused && !['cancelled', 'failed'].includes(run.status)));
        $('#restart-work').classList.toggle('hidden', !run || (running && !paused));
        $('#open-setup').disabled = running && !paused;
    }
    async function refresh(force = false) {
        if (polling) { await polling; if (!force) return; }
        const wanted = number;
        polling = request(`${base}/state?chunk=${wanted}`).then(next => {
            if (wanted !== number || next.version < book.version) return;
            state = next; book.version = next.version;
            number = boundedChunk(next.chunk.position + 1, next.chunk_count);
            if (!settingsDirty) {
                $('#book-prompt').value = next.prompt || ''; book.promptVersion = next.prompt_version;
                $('#book-name').value = next.title; $('#book-language').value = next.language;
                $('#book-context').value = next.context || ''; settings.elements.learn.checked = next.learn;
                $$('.preset-checkbox').forEach(box => { box.checked = !!next.prompt?.includes(box.dataset.instruction); });
            }
            $('#book-title').textContent = next.title;
            $('#metadata-message').classList.toggle('hidden', next.metadata_status === 'complete');
            $('#metadata-message').textContent = t(`metadata_${next.metadata_status}`);
            $('#learning-status').textContent = `${t('learning_awaiting')}: ${next.learning_awaiting} · ${t('learning_failed')}: ${next.learning_failed}`;
            $('#retry-learning').classList.toggle('hidden', !next.learning_awaiting && !next.learning_failed);
            $('#editor-learning-hint').classList.toggle('hidden', !next.learning_hint);
            renderProgress(); showChunk(next.chunk); estimate();
        }).catch(error => message(error.message, true)).finally(() => { polling = null; });
        return polling;
    }
    async function save() {
        if (saving) { await saving; if (dirty) return save(); return; }
        if (!dirty || !current?.id) return;
        const snapshot = text(), chunkId = current.id;
        saving = request(`${base}/chunks/${chunkId}/text`, 'PUT', { text: snapshot, revision: current.revision });
        indicator();
        try {
            const result = await saving;
            book.version = result.version; current = result.chunk;
            dirty = text() !== snapshot;
            if (!dirty) deadline = 0;
            signature = '';
        } catch (error) { deadline = 0; message(`${t('save_failed')} ${error.message}`, true); throw error; }
        finally { saving = null; indicator(); }
        if (!dirty) { showChunk(current, true); message(''); }
    }
    quill.on('text-change', (_, __, source) => {
        if (source !== 'user') return;
        dirty = text() !== chunkText(current); deadline = Date.now() + 10000; indicator();
    });
    setInterval(() => {
        indicator();
        if (dirty && deadline && Date.now() >= deadline && !saving && !busy) save().catch(() => {});
    }, 250);
    $('#save-now').addEventListener('click', () => save().catch(() => {}));
    async function go(value) {
        if (busy) return;
        busy = true;
        try {
            await save();
            number = boundedChunk(value, state?.chunk_count || 1);
            try { sessionStorage.setItem(`le-chunk-${book.id}`, number); } catch {}
            $('#jump-dialog').close();
            await refresh(true);
            $('#chunk-position').focus();
        } catch (error) { message(error.message, true); }
        finally { busy = false; }
    }
    $('#previous-chunk').addEventListener('click', () => go(number - 1));
    $('#next-chunk').addEventListener('click', () => go(number + 1));
    $('#chunk-position').addEventListener('click', () => {
        $('#jump-number').max = state?.chunk_count || 1; $('#jump-number').value = number;
        $('#jump-dialog').showModal(); $('#jump-number').select();
    });
    $('#jump-form').addEventListener('submit', event => { event.preventDefault(); go($('#jump-number').value); });
    $('#close-jump').addEventListener('click', () => $('#jump-dialog').close());
    $('#pause-work').addEventListener('click', () => $('#queue-cancel').click());
    $('#resume-work').addEventListener('click', async event => {
        event.target.disabled = true;
        try {
            if (['failed', 'cancelled'].includes(state?.run?.status)) await request(`${base}/retry`, 'POST', {});
            else await request('/queue/resume', 'POST', {});
            window.dispatchEvent(new Event('queue-poll')); await refresh(true);
        } catch (error) { message(error.message, true); }
        finally { event.target.disabled = false; }
    });
    function openSetup() { if (!busy) setup.showModal(); }
    $('#restart-work').addEventListener('click', openSetup);
    $('#open-setup').addEventListener('click', openSetup);
    $('#cancel-setup').addEventListener('click', () => { if (!busy) setup.close(); });
    setup.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    settings.addEventListener('input', () => { settingsDirty = true; estimate(); });
    settings.addEventListener('submit', event => { event.preventDefault(); $('#run-form').requestSubmit(); });
    $$('.preset-checkbox').forEach(box => box.addEventListener('change', () => {
        $('#book-prompt').value = $$('.preset-checkbox:checked').map(item => item.dataset.instruction).join('\n\n');
        settingsDirty = true; $('#prompt-live-status').textContent = t('setup_prompt_updated'); estimate();
    }));
    $('#run-model').addEventListener('change', estimate);
    $('#chunk-size').addEventListener('input', estimate);
    $('#run-form').addEventListener('submit', async event => {
        event.preventDefault(); if (busy || !settings.reportValidity()) return;
        busy = true; $('#run-button').disabled = true; $('#cancel-setup').disabled = true;
        $('.setup-grid', setup).inert = true; $('#setup-error').classList.add('hidden');
        try {
            await save();
            const data = Object.fromEntries(new FormData(settings));
            data.learn = settings.elements.learn.checked; data.prompt_version = book.promptVersion;
            const saved = await request(base, 'PATCH', data);
            book.promptVersion = saved.prompt_version; settingsDirty = false;
            await request(`${base}/run`, 'POST', { model: $('#run-model').value,
                chunk_size: Number($('#chunk-size').value), version: book.version });
            setup.close(); signature = ''; await refresh(true);
            window.dispatchEvent(new Event('queue-poll'));
        } catch (error) {
            $('#setup-error').textContent = error.message; $('#setup-error').classList.remove('hidden');
            $('#setup-error').scrollIntoView({ block: 'nearest' });
        } finally {
            busy = false; $('#run-button').disabled = false; $('#cancel-setup').disabled = false; $('.setup-grid', setup).inert = false;
        }
    });
    async function learn(event) {
        event.target.disabled = true;
        try {
            await request(`${base}/learning/retry`, 'POST', {});
            window.dispatchEvent(new Event('queue-poll')); await refresh(true);
        } catch (error) { message(error.message, true); }
        finally { event.target.disabled = false; }
    }
    $('#learn-from-feedback').addEventListener('click', learn);
    $('#retry-learning').addEventListener('click', learn);
    $('#dismiss-learning').addEventListener('click', async () => {
        try { await request(`${base}/learning/dismiss`, 'POST', {}); await refresh(true); }
        catch (error) { message(error.message, true); }
    });
    $$('[data-tab]').forEach(button => button.addEventListener('click', async () => {
        try { await save(); } catch { return; }
        $$('[data-tab]').forEach(item => { item.classList.toggle('active', item === button); item.setAttribute('aria-selected', item === button); });
        $$('[data-panel]').forEach(panel => panel.classList.toggle('hidden', panel.dataset.panel !== button.dataset.tab));
    }));
    document.addEventListener('keydown', event => {
        if (!event.altKey || !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
        event.preventDefault();
        const changes = $$('.change[data-status="pending"]');
        if (!changes.length) return;
        $$('.change.focused').forEach(el => el.classList.remove('focused'));
        focusedChange = (focusedChange + (event.key === 'ArrowDown' ? 1 : -1) + changes.length) % changes.length;
        changes[focusedChange].classList.add('focused'); changes[focusedChange].scrollIntoView({ block: 'center' });
    });
    $('#open-export').addEventListener('click', async () => {
        try { await save(); $('#export-dialog').showModal(); } catch {}
    });
    $('#close-export').addEventListener('click', () => $('#export-dialog').close());
    $('#export-form').addEventListener('submit', () => $('#export-dialog').close());
    window.addEventListener('beforeunload', event => {
        if (dirty || settingsDirty) { event.preventDefault(); event.returnValue = t('unsaved'); }
    });
    window.addEventListener('queue-updated', () => { if (!busy) refresh(); });
    if (book.needsSetup) setup.showModal();
    refresh();
    setInterval(() => { if (!document.hidden && !busy) refresh(); }, 3000);
}
