import { startBrowserQueue } from './browser-queue';
import './bootstrap';
import Alpine from 'alpinejs';
window.Alpine = Alpine;
Alpine.start();

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
const t = key => window.leStrings?.[key] || key;
const csrf = $('meta[name="csrf-token"]').content;
async function request(url, method = 'GET', data) {
    const response = await fetch(url, { method, credentials: 'same-origin', headers: {
        Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf,
    }, ...(data === undefined ? {} : { body: JSON.stringify(data) }) });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || t('request_failed'));
    return result;
}
$('#theme-toggle')?.addEventListener('click', () => {
    const dark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('le-theme', dark ? 'dark' : 'light');
});
$('#theme')?.addEventListener('change', event => {
    localStorage.setItem('le-theme', event.target.value);
    document.documentElement.classList.toggle('dark', event.target.value === 'dark');
});
async function confirmAction(text) {
    const dialog = document.createElement('dialog');
    dialog.className = 'editor-dialog';
    const copy = document.createElement('p'); copy.textContent = text;
    const actions = document.createElement('div'); actions.className = 'flex gap-3 mt-6';
    const yes = document.createElement('button'); yes.className = 'button'; yes.textContent = document.documentElement.lang === 'tr' ? 'Devam et' : 'Continue';
    const no = document.createElement('button'); no.className = 'button-secondary'; no.textContent = document.documentElement.lang === 'tr' ? 'Vazgeç' : 'Cancel';
    actions.append(yes, no); dialog.append(copy, actions); document.body.append(dialog);
    return new Promise(resolve => {
        const finish = value => { dialog.close(); dialog.remove(); resolve(value); };
        yes.addEventListener('click', () => finish(true)); no.addEventListener('click', () => finish(false));
        dialog.addEventListener('cancel', event => { event.preventDefault(); finish(false); });
        dialog.showModal(); no.focus();
    });
}
$$('form[data-confirm]').forEach(form => form.addEventListener('submit', async event => {
    event.preventDefault();
    if (await confirmAction(form.dataset.confirm)) HTMLFormElement.prototype.submit.call(form);
}));
$$('[data-upload-form]').forEach(form => form.addEventListener('submit', () => { $('button', form).disabled = true; }));
$$('[data-copy-preset]').forEach(button => button.addEventListener('click', () => {
    const data = JSON.parse(button.dataset.copyPreset);
    const form = $('#preset-form');
    Object.entries(data).forEach(([key, value]) => { form.elements[key].value = value; });
    form.scrollIntoView({ behavior: 'smooth', block: 'center' });
}));
$('#test-key')?.addEventListener('click', async event => {
    event.target.disabled = true;
    try { await request('/settings/test', 'POST', {}); $('#key-result').textContent = t('connected'); }
    catch { $('#key-result').textContent = t('connection_failed'); }
    finally { event.target.disabled = false; }
});
let models = [];
async function loadModels() {
    if (!$$('[data-model-select]').length) return;
    try {
        models = (await request('/models')).models;
        $$('[data-model-select]').forEach(select => {
            select.replaceChildren(new Option('—', ''));
            models.forEach(model => select.add(new Option(`${model.name} · $${model.output_price.toFixed(2)} / 1M`, model.id)));
            select.value = select.dataset.current;
            select.dispatchEvent(new Event('change'));
        });
        if (!models.length) $$('.model-error').forEach(el => { el.textContent = t('catalog_unavailable'); });
    } catch (error) { $$('.model-error').forEach(el => { el.textContent = error.message; }); }
}
loadModels();

if (window.leQueue) startBrowserQueue({ $, request, t, window, document, setInterval });

if (window.leBook) {
    const book = window.leBook;
    const base = `/books/${book.id}`;
    const input = $('#manuscript-input');
    const exportDialog = $('#export-dialog');
    const settings = $('#book-settings');
    const setupDialog = $('#setup-dialog');
    let state = null, dirty = false, settingsDirty = false, busy = false, active = false;
    let deadline = 0, saving = null, polling = false, lastSignature = '', focusedChange = -1;
    let acknowledgedText = input.value;
    const message = (text, error = false) => {
        const el = $('#editor-message'); el.textContent = text; el.classList.toggle('hidden', !text); el.classList.toggle('error', error);
    };
    const updateIndicator = () => {
        const indicator = $('#save-indicator');
        const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        $('span', indicator).textContent = saving ? t('saving') : dirty ? `${t('modified')} ${seconds} ${t('seconds')}` : t('saved');
        $('#save-now').classList.toggle('hidden', !dirty || !!saving);
    };
    const updateEstimate = () => {
        const model = models.find(item => item.id === $('#run-model').value);
        if (!model) { $('#cost-estimate').textContent = ''; return; }
        const tokens = Math.ceil(input.value.length / 3);
        const chunks = Math.max(1, Math.ceil(input.value.trim().split(/\s+/u).length / Number($('#chunk-size').value || 2500)));
        const promptTokens = Math.ceil(($('#book-prompt').value.length + $('#book-context').value.length + 1000) / 3);
        const estimate = ((tokens + chunks * promptTokens) * model.input_price + tokens * model.output_price) / 1e6;
        $('#cost-estimate').textContent = `${t('estimate')}: ~$${estimate.toFixed(3)}`;
    };
    $('#run-model').addEventListener('change', updateEstimate);
    $('#chunk-size').addEventListener('input', updateEstimate);
    function renderDiff(run) {
        const view = $('#diff-view'); view.replaceChildren();
        const counts = { accepted: 0, rejected: 0, pending: 0 };
        if (!run) {
            const note = document.createElement('p'); note.className = 'notice font-sans text-sm'; note.textContent = t('no_changes'); view.append(note);
            const text = document.createElement('div'); text.className = 'whitespace-pre-wrap'; text.textContent = state?.text || book.text; view.append(text);
        }
        for (const chunk of run?.chunks || []) {
            const section = document.createElement('section');
            const label = document.createElement('span'); label.className = 'chunk-label'; label.textContent = `${t('chunk')} ${chunk.position + 1}`; section.append(label);
            const content = document.createElement('div'); content.className = 'whitespace-pre-wrap'; section.append(content);
            if (!chunk.segments) content.textContent = chunk.source;
            for (const [index, segment] of (chunk.segments || []).entries()) {
                if (segment.type === 'equal') { content.append(document.createTextNode(segment.after)); continue; }
                counts[segment.status]++;
                const change = document.createElement('span'); change.className = 'change'; change.dataset.status = segment.status;
                if (segment.before) { const del = document.createElement('del'); del.textContent = segment.before; change.append(del); }
                if (segment.after) { const ins = document.createElement('ins'); ins.textContent = segment.after; change.append(ins); }
                const tools = document.createElement('span'); tools.className = 'change-tools';
                const actions = segment.status === 'pending' ? [['accepted','accept'],['rejected','reject']] : [['pending','undo']];
                if (segment.status !== 'pending') { const status = document.createElement('span'); status.textContent = t(segment.status); tools.append(status); }
                actions.forEach(([status, name]) => {
                    const button = document.createElement('button'); button.type = 'button'; button.textContent = t(name);
                    button.addEventListener('click', async () => {
                        if (busy || active) return;
                        busy = true; tools.querySelectorAll('button').forEach(b => b.disabled = true);
                        try { const result = await request(`${base}/chunks/${chunk.id}`, 'PATCH', { index, status, version: book.version }); book.version = result.version; await refresh(); }
                        catch (error) { message(error.message, true); tools.querySelectorAll('button').forEach(b => b.disabled = false); }
                        finally { busy = false; }
                    });
                    tools.append(button);
                });
                change.append(tools); content.append(change);
            }
            view.append(section);
        }
        $('#review-counts').textContent = Object.entries(counts).map(([key, value]) => `${t(key)}: ${value}`).join('  ·  ');
        if (run) $('#review-counts').textContent += `  ·  ${t('cost')}: $${Number(run.cost).toFixed(4)}`;
    }
    async function refresh() {
        if (polling) return;
        polling = true;
        try {
            const next = await request(`${base}/state`);
            if (next.version < book.version) return;
            state = next;
            active = ['queued','running'].includes(next.run?.status);
            if (!dirty && !saving) {
                book.version = next.version;
                input.value = next.text; acknowledgedText = next.text;
                $('#clean-preview').textContent = next.text;
            }
            if (!settingsDirty) {
                $('#book-prompt').value = next.prompt || ''; book.promptVersion = next.prompt_version;
                $$('.preset-checkbox').forEach(box => { box.checked = !!next.prompt?.includes(box.dataset.instruction); });
                $('#book-name').value = next.title; $('#book-language').value = next.language;
                $('#book-title').textContent = next.title;
            }
            $('#metadata-message').classList.toggle('hidden', next.metadata_status === 'complete');
            $('#metadata-message').textContent = t(`metadata_${next.metadata_status}`);
            $('#learning-status').textContent = `${t('learning_pending')}: ${next.learning_pending} · ${t('learning_failed')}: ${next.learning_failed}`;
            $('#retry-learning').classList.toggle('hidden', !next.learning_failed);
            const signature = `${next.run?.id}-${next.version}-${next.run?.completed_chunks}-${next.run?.status}`;
            if (signature !== lastSignature && !dirty) { renderDiff(next.run); lastSignature = signature; }
            const status = $('#run-status');
            status.classList.toggle('hidden', !next.run || active);
            if (next.run) $('span', status).textContent = t(next.run.status) + (next.run.error ? ` · ${t(next.run.error)}` : '');
            $('#retry-run').classList.toggle('hidden', !['cancelled','failed'].includes(next.run?.status));
            updateEstimate();
        } catch (error) {
            message(error.message, true);
        } finally { polling = false; }
    }
    async function saveText() {
        if (saving) { await saving; if (dirty) return saveText(); return; }
        if (!dirty) return;
        const snapshot = input.value;
        saving = request(`${base}/text`, 'PUT', { text: snapshot, version: book.version }); updateIndicator();
        try {
            const result = await saving;
            book.version = result.version; acknowledgedText = snapshot;
            dirty = input.value !== snapshot;
            if (!dirty) { deadline = 0; message(''); lastSignature = ''; }
        } catch (error) { deadline = 0; message(`${t('save_failed')} ${error.message}`, true); throw error; }
        finally { saving = null; updateIndicator(); }
        if (!dirty) await refresh();
    }
    input.addEventListener('input', () => { dirty = input.value !== acknowledgedText; deadline = Date.now() + 10000; updateIndicator(); });
    setInterval(() => { updateIndicator(); if (dirty && deadline && Date.now() >= deadline && !saving) saveText().catch(() => {}); }, 250);
    $('#save-now').addEventListener('click', () => saveText().catch(() => {}));
    settings.addEventListener('input', () => { settingsDirty = true; updateEstimate(); });
    $('#open-setup').addEventListener('click', () => {
        if (!busy && !active) setupDialog.showModal();
    });
    $('#cancel-setup').addEventListener('click', () => { if (!busy) setupDialog.close(); });
    setupDialog.addEventListener('cancel', event => {
        // Native dialogs ignore backdrop clicks. Escape closes unless submission is underway.
        if (busy) event.preventDefault();
    });
    async function saveSettings() {
        const data = Object.fromEntries(new FormData(settings));
        data.learn = settings.elements.learn.checked;
        data.prompt_version = book.promptVersion;
        const result = await request(base, 'PATCH', data);
        book.promptVersion = result.prompt_version; settingsDirty = false;
        $('#book-title').textContent = data.title;
    }
    settings.addEventListener('submit', event => {
        event.preventDefault();
        $('#run-form').requestSubmit();
    });
    $$('.preset-checkbox').forEach(box => box.addEventListener('change', () => {
        const selected = $$('.preset-checkbox:checked').map(box => box.dataset.instruction);
        $('#book-prompt').value = selected.join('\n\n'); settingsDirty = true;
        $('#prompt-live-status').textContent = t('setup_prompt_updated');
        updateEstimate();
    }));
    $('#run-form').addEventListener('submit', async event => {
        event.preventDefault(); if (busy || active) return;
        if (!settings.reportValidity()) return;
        busy = true; $('#run-button').disabled = true;
        $('#cancel-setup').disabled = true;
        $('.setup-grid', setupDialog).inert = true;
        $('#setup-error').classList.add('hidden');
        try {
            await saveText();
            await saveSettings();
            await request(`${base}/run`, 'POST', { model: $('#run-model').value, chunk_size: Number($('#chunk-size').value), version: book.version });
            setupDialog.close();
            message(''); await refresh();
        } catch (error) {
            $('#setup-error').textContent = error.message;
            $('#setup-error').classList.remove('hidden');
            $('#setup-error').scrollIntoView({ block: 'nearest' });
        }
        finally { busy = false; $('#run-button').disabled = false; $('#cancel-setup').disabled = false; $('.setup-grid', setupDialog).inert = false; }
    });
    $('#retry-run').addEventListener('click', async () => {
        try { await request(`${base}/retry`, 'POST', {}); await refresh(); }
        catch (error) { message(error.message, true); }
    });
    $('#retry-learning').addEventListener('click', async () => {
        try { await request(`${base}/learning/retry`, 'POST', {}); await refresh(); }
        catch (error) { message(error.message, true); }
    });
    $$('[data-tab]').forEach(button => button.addEventListener('click', async () => {
        if (active) return;
        const tab = button.dataset.tab;
        if (tab === 'manual' && state?.run && !(await confirmAction(t('manual_confirm')))) return;
        try { if (dirty) await saveText(); } catch { return; }
        $$('[data-tab]').forEach(item => { item.classList.toggle('active', item === button); item.setAttribute('aria-selected', item === button); });
        $$('[data-panel]').forEach(panel => panel.classList.toggle('hidden', panel.dataset.panel !== tab));
        $('#save-indicator').classList.toggle('hidden', tab !== 'manual');
    }));
    function navigate(direction) {
        const changes = $$('.change[data-status="pending"]');
        if (!changes.length) return;
        $$('.change.focused').forEach(el => el.classList.remove('focused'));
        focusedChange = (focusedChange + direction + changes.length) % changes.length;
        changes[focusedChange].classList.add('focused'); changes[focusedChange].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    $('#next-change').addEventListener('click', () => navigate(1));
    $('#prev-change').addEventListener('click', () => navigate(-1));
    document.addEventListener('keydown', event => {
        if (event.altKey && ['ArrowDown','ArrowUp'].includes(event.key)) { event.preventDefault(); navigate(event.key === 'ArrowDown' ? 1 : -1); }
    });
    $('#open-export').addEventListener('click', async () => {
        if (active) return;
        try { await saveText(); exportDialog.showModal(); } catch {}
    });
    $('#close-export').addEventListener('click', () => exportDialog.close());
    $('#export-form').addEventListener('submit', () => exportDialog.close());
    window.addEventListener('beforeunload', event => {
        if (dirty || settingsDirty) { event.preventDefault(); event.returnValue = t('unsaved'); }
    });
    window.addEventListener('queue-updated', () => { if (!busy) refresh(); });
    if (book.needsSetup) setupDialog.showModal();
    refresh();
    setInterval(() => { if (!document.hidden && !busy) refresh(); }, 3000);
}
