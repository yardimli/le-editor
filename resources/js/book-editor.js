import { sliceStyles } from './source-styles.js';
import { chunkStyles, annotateStyles } from './source-styles';
import { italicRanges, applyItalics, italicOps, readItalics, appendFormatted, sliceRanges } from './italics';
import { draftSegments } from './draft-review';
import Quill from 'quill';
import 'quill/dist/quill.snow.css';
import { chunkText, changeKey, boundedChunk } from './chunk-text';

const FontStyle = Quill.import('attributors/style/font'); FontStyle.whitelist = null; Quill.register(FontStyle, true);
const SizeStyle = Quill.import('attributors/style/size'); SizeStyle.whitelist = null; Quill.register(SizeStyle, true);

export function startBookEditor({ $, $$, request, t, getModels }) {
    const book = window.leBook, base = `/books/${book.id}`;
    const setup = $('#setup-dialog'), settings = $('#book-settings');
    const quill = new Quill('#chunk-editor', { theme: 'snow', modules: { toolbar: [['italic']], keyboard: { bindings: { bold: {key: 'b', shortKey: true, handler: () => false}, underline: {key: 'u', shortKey: true, handler: () => false} } }, history: { userOnly: true } }, formats: ['italic', 'bold', 'underline', 'strike', 'color', 'font', 'size', 'header', 'script'] });
    quill.root.setAttribute('aria-label', t('edit_chunk'));
    quill.root.setAttribute('role', 'textbox');
    quill.root.setAttribute('aria-multiline', 'true');
    quill.enable(false);
    let state = null, current = null, busy = false, settingsDirty = false;
    let dirty = false, saving = null, deadline = 0, polling = null, signature = '', focusedChange = -1;
    let stateTokens = {};
    let savedUntil = 0, editing = false;
    let number = 1;
    try { number = Math.max(1, Number(sessionStorage.getItem(`le-chunk-${book.id}`)) || 1); } catch {}
    const text = () => quill.getText().slice(0, -1);
    const formats = () => readItalics(quill.getContents().ops, Array.from(text()).length);
    const matches = chunk => text() === chunkText(chunk) && JSON.stringify(formats()) === JSON.stringify(italicRanges(chunk?.segments));
    const message = (copy, error = false) => {
        const el = $('#editor-message'); el.textContent = copy;
        el.classList.toggle('hidden', !copy); el.classList.toggle('error', error);
    };
    const indicator = () => {
        $('#save-indicator span').textContent = saving ? t('saving') : dirty
            ? `${t('modified')} ${Math.max(0, Math.ceil((deadline - Date.now()) / 1000))} ${t('seconds')}` : t('saved');
        $('#save-indicator').classList.toggle('hidden', !dirty && !saving && Date.now() >= savedUntil);
        $('#save-now').classList.toggle('hidden', !dirty || !!saving);
    };
    function estimate() {
        const model = getModels().find(m => m.id === $('#run-model').value);
        if (!model) return;
        const words = state?.word_count || 0, tokens = Math.ceil(words * 2);
        const chunks = Math.max(1, Math.ceil(words / Number($('#chunk-size').value || 1000)));
        const level = $('#thinking-level').value;
        const ratio = {low: .5, medium: 1, high: 2}[level];
        const effortShare = {low: .2, medium: .5, high: .8}[level];
        const thinkingTokens = model.reasoning ? Math.max(chunks * 1024, tokens * (model.reasoning.supports_max_tokens ? ratio : Math.max(ratio, effortShare / (1 - effortShare)))) : 0;
        const promptTokens = Math.ceil(($('#book-prompt').value.length + $('#book-context').value.length + 1000) / 3);
        $('#cost-estimate').textContent = `${t('estimate')}: ~$${(((tokens + chunks * promptTokens) * model.input_price + (tokens + thinkingTokens + chunks * 10000) * model.output_price) / 1e6).toFixed(3)}`;
    }
    function showChunk(chunk, force = false) {
        if (!chunk || dirty || saving) return;
        if (current?.id === chunk.id && Number(chunk.revision) < Number(current?.revision)) return;
        const key = `${state?.run?.id}-${chunk.position}-${chunk.revision}-${chunk.status}`;
        if (!force && key === signature) return;
        const switched = current?.id !== chunk.id || current?.position !== chunk.position;
        const selection = quill.getSelection();
        current = chunk; signature = key;
        if (switched || !matches(chunk)) {
            quill.setContents({ops: italicOps(chunkText(chunk), italicRanges(chunk.segments), chunkStyles(chunk.segments))}, 'silent');
            quill.history.clear();
            if (!switched && selection) quill.setSelection(Math.min(selection.index, quill.getLength() - 1), 0, 'silent');
        }
        quill.enable(chunk.status === 'completed' && !!chunk.id);
        $('#chunk-unprocessed').classList.toggle('hidden', chunk.status === 'completed');
        $('#chunk-edit-count').textContent = `${t('ai_edit_count')}: ${chunk.ai_edit_count || 0}`;
        renderDiff(chunk);
        indicator();
    }
    function renderDiff(chunk) {
        const view = $('#diff-view'); view.replaceChildren();
        const counts = { accepted: 0, rejected: 0, pending: 0 };
        const container = document.createElement('div'); container.className = 'review-paragraphs'; view.append(container);
        let body;
        const nextParagraph = () => { body = document.createElement('p'); container.append(body); };
        nextParagraph();
        if (!chunk.segments) body.textContent = chunkText(chunk);
        (chunk.segments || []).forEach((segment, index) => {
            if (segment.type === 'equal') {
                let offset = 0;
                segment.after.split('\n\n').forEach((part, i) => {
                    if (i) { nextParagraph(); offset += 2; }
                    const length = Array.from(part).length;
                    appendFormatted(body, part, sliceRanges(segment.italics || [], offset, length), sliceStyles(segment.styles || [], offset, length));
                    offset += length;
                });
                return;
            }
            counts[segment.status]++;
            const span = document.createElement('span'); span.className = 'change'; span.dataset.status = segment.status;
            if (segment.before) { const del = document.createElement('del'); appendFormatted(del, segment.before, segment.before_italics, segment.before_styles); span.append(del); }
            if (segment.after) { const ins = document.createElement('ins'); appendFormatted(ins, segment.after, segment.italics, segment.styles); span.append(ins); }
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
            ? `${t(paused && running ? 'cancelled' : run.status === 'queued' ? 'running' : run.status)} · ${completed} / ${total} · ${percent}%` : t('no_run');
        const task = window.leQueueState?.task;
        if (!paused && task?.book_id && Number(task.book_id) !== Number(book.id)) {
            $('#work-label').textContent = `${t(task.kind === 'editing' ? 'queue_other_book' : 'queue_other_task')}: ${task.title} · ${t('queue_this_book_waiting')}`;
        }
        $('#work-error').textContent = run?.error ? t(run.error) : '';
        $('#pause-work').classList.toggle('hidden', !running || paused);
        $('#resume-work').classList.toggle('hidden', !run || (!paused && !['cancelled', 'failed'].includes(run.status)));
        $('#restart-work').classList.toggle('hidden', !run || (running && !paused));
        $('#open-setup').disabled = running && !paused;
    }
    async function refresh(force = false) {
        if (polling) { if (!force) return polling; await polling; }
        const wanted = number;
        const query = new URLSearchParams({chunk: wanted, ...Object.fromEntries(Object.entries(stateTokens).map(([key, value]) => [`known_${key}`, value]))});
        polling = request(`${base}/state?${query}`).then(update => {
            if (wanted !== number || (update.version !== undefined && update.version < book.version)) return update.queue;
            stateTokens = update.tokens;
            const next = {...state, ...update};
            state = next; book.version = next.version;
            window.leQueueState = update.queue;
            number = boundedChunk(next.chunk.position + 1, next.chunk_count);
            if (!settingsDirty) {
                $$('[name=editorial_genre]').forEach(radio => { radio.checked = radio.value === next.editorial_genre; });
                $('#book-prompt').value = next.prompt || ''; book.promptVersion = next.prompt_version;
                $('#book-name').value = next.title; $('#book-language').value = next.language;
                $('#book-context').value = next.context || ''; $('#chunk-size').value = next.chunk_size;
                $$('.preset-checkbox').forEach(box => { box.checked = !!next.prompt?.includes(box.dataset.instruction) || (next.italicize && box.dataset.presetKey === 'italics' && box.closest('[data-preset-genre]').dataset.presetGenre === next.editorial_genre); });
                filterPresets();
            }
            $('#book-title').textContent = next.title;
            $('#metadata-message').classList.toggle('hidden', next.metadata_status === 'complete');
            $('#metadata-message').textContent = t(`metadata_${next.metadata_status}`);
            $('#learning-status').textContent = `${t('learning_awaiting')}: ${next.learning_awaiting} · ${t('learning_failed')}: ${next.learning_failed}`;
            $('#retry-learning').classList.toggle('hidden', !next.learning_awaiting && !next.learning_failed);
            $('#editor-learning-hint').classList.toggle('hidden', !next.learning_hint);
            renderProgress(); if (update.chunk) showChunk(update.chunk); estimate();
            return update.queue;
        }).catch(error => { message(error.message, true); throw error; }).finally(() => { polling = null; });
        return polling;
    }
    async function save() {
        if (saving) { await saving; if (dirty) return save(); return; }
        if (!dirty || !current?.id) return;
        const snapshot = text(), snapshotFormats = formats(), chunkId = current.id;
        saving = request(`${base}/chunks/${chunkId}/text`, 'PUT', { text: snapshot, italics: snapshotFormats, revision: current.revision });
        indicator();
        try {
            const result = await saving;
            book.version = result.version; current = result.chunk;
            dirty = text() !== snapshot || JSON.stringify(formats()) !== JSON.stringify(snapshotFormats);
            if (!dirty) { deadline = 0; savedUntil = Date.now() + 3000; }
            signature = '';
        } catch (error) { deadline = 0; message(`${t('save_failed')} ${error.message}`, true); throw error; }
        finally { saving = null; indicator(); }
        if (!dirty) { showChunk(current, true); message(''); }
    }
    quill.on('text-change', (_, __, source) => {
        if (source !== 'user') return;
        dirty = !matches(current); deadline = Date.now() + 10000; indicator();
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
            await refresh(true);
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
    function filterPresets() {
        const genre = $('[name=editorial_genre]:checked')?.value || 'fiction';
        $$('[data-preset-genre]').forEach(label => {
            const visible = label.dataset.presetGenre === 'general' || label.dataset.presetGenre === genre || (genre === 'fiction' && label.dataset.presetGenre === 'poetry');
            label.classList.toggle('hidden', !visible);
            if (!visible) $('input', label).checked = false;
        });
    }
    function combinePresets() {
        $('#book-prompt').value = $$('.preset-checkbox:checked').map(item => item.dataset.instruction).join('\n\n');
        settingsDirty = true; $('#prompt-live-status').textContent = t('setup_prompt_updated'); estimate();
    }
    $$('[name=editorial_genre]').forEach(radio => radio.addEventListener('change', () => {
        const keys = new Set($$('.preset-checkbox:checked').map(box => box.dataset.presetKey));
        $$('.preset-checkbox').forEach(box => { box.checked = keys.has(box.dataset.presetKey); });
        filterPresets(); combinePresets();
    }));
    $$('.preset-checkbox').forEach(box => box.addEventListener('change', () => {
        // Opposing register goals cannot both be selected.
        if (box.checked && ['formal', 'casual'].includes(box.dataset.presetKey)) {
            $$('.preset-checkbox').filter(other => other !== box && ['formal', 'casual'].includes(other.dataset.presetKey)).forEach(other => { other.checked = false; });
        }
        combinePresets();
    }));
    filterPresets();
    $('#run-model').addEventListener('change', () => { $('#thinking-control').hidden = !getModels().find(m => m.id === $('#run-model').value)?.reasoning; estimate(); });
    $('#thinking-level').addEventListener('change', estimate);
    $('#chunk-size').addEventListener('input', estimate);
    function confirmModelChoice() {
        const select = $('#run-model');
        const recommended = getModels().find(model => model.id === select.dataset.defaultModel);
        if (!recommended || select.value === recommended.id) return Promise.resolve(true);
        const selected = getModels().find(model => model.id === select.value);
        const dialog = $('#model-warning-dialog'), keep = $('#keep-selected-model');
        keep.checked = false;
        $('#warning-selected-model').textContent = selected?.name || select.value;
        $('#warning-default-model').textContent = recommended.name;
        return new Promise(resolve => {
            const start = $('#confirm-model-start'), cancel = $('#cancel-model-warning');
            const finish = confirmed => {
                if (confirmed && !keep.checked) {
                    select.value = recommended.id;
                    select.dispatchEvent(new Event('change'));
                }
                dialog.close();
                start.removeEventListener('click', accept); cancel.removeEventListener('click', decline);
                dialog.removeEventListener('cancel', escape);
                resolve(confirmed);
            };
            const accept = () => finish(true), decline = () => finish(false);
            const escape = event => { event.preventDefault(); finish(false); };
            start.addEventListener('click', accept); cancel.addEventListener('click', decline);
            dialog.addEventListener('cancel', escape);
            dialog.showModal(); start.focus();
        });
    }
    function chooseRunScope() {
        if (!state?.run?.completed_chunks) return Promise.resolve('unfinished');
        const dialog = $('#run-scope-dialog');
        const unfinished = $('[data-run-scope="unfinished"]', dialog);
        unfinished.disabled = state.run.completed_chunks >= state.run.total_chunks;
        return new Promise(resolve => {
            const finish = scope => {
                dialog.close(); dialog.removeEventListener('click', click); dialog.removeEventListener('cancel', cancel);
                resolve(scope);
            };
            const click = event => { const button = event.target.closest('[data-run-scope]'); if (button && !button.disabled) finish(button.dataset.runScope); };
            const cancel = event => { event.preventDefault(); finish('cancel'); };
            dialog.addEventListener('click', click); dialog.addEventListener('cancel', cancel);
            dialog.showModal();
            (unfinished.disabled ? $('[data-run-scope="all"]', dialog) : unfinished).focus();
        });
    }
    $('#run-form').addEventListener('submit', async event => {
        event.preventDefault(); if (busy || !settings.reportValidity()) return;
        busy = true; $('#run-button').disabled = true; $('#cancel-setup').disabled = true;
        $('.setup-grid', setup).inert = true; $('#setup-error').classList.add('hidden');
        try {
            const scope = await chooseRunScope();
            if (scope === 'cancel') return;
            if (!await confirmModelChoice()) return;
            await save();
            const data = Object.fromEntries(new FormData(settings));
            data.italicize = $$('.preset-checkbox:checked').some(box => box.dataset.presetKey === 'italics');
            data.learn = true; data.prompt_version = book.promptVersion;
            const saved = await request(base, 'PATCH', data);
            book.promptVersion = saved.prompt_version; settingsDirty = false;
            await request(`${base}/run`, 'POST', { scope, model: $('#run-model').value,
                reasoning_effort: $('#thinking-level').value, chunk_size: Number($('#chunk-size').value), version: book.version });
            setup.close(); signature = ''; await refresh(true);
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
            await refresh(true);
        } catch (error) { message(error.message, true); }
        finally { event.target.disabled = false; }
    }
    $('#learn-from-feedback').addEventListener('click', learn);
    $('#retry-learning').addEventListener('click', learn);
    $('#dismiss-learning').addEventListener('click', async () => {
        try { await request(`${base}/learning/dismiss`, 'POST', {}); await refresh(true); }
        catch (error) { message(error.message, true); }
    });
    $('#toggle-edit').addEventListener('click', () => {
        const nextEditing = !editing;
        if (!nextEditing && current) {
            try {
                renderDiff(dirty ? { ...current, segments: applyItalics(annotateStyles(draftSegments(current.segments, text()), current.segments), formats()) } : current);
            } catch (error) { message(t(error.message), true); return; }
        }
        editing = nextEditing;
        $('[data-panel="edit"]').classList.toggle('hidden', !editing);
        $('[data-panel="review"]').classList.toggle('hidden', editing);
        $('#toggle-edit').textContent = t(editing ? 'review' : 'edit_chunk');
        $('#toggle-edit').setAttribute('aria-pressed', String(editing));
        if (editing) quill.focus();
    });
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
    window.addEventListener('queue-updated', renderProgress);
    if (book.needsSetup) setup.showModal();
    return { poll: () => refresh() };
}
