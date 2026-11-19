import { sliceStyles } from './source-styles.js';
import { appendFormatted, sliceRanges } from './italics';

export function startBookEditor({ $, $$, request, t }) {
    const book = window.leBook, base = `/books/${book.id}`;
    let state = {}, tokens = {}, polling = null, sending = false, chatSignature = '';
    let selectionWrites = Promise.resolve(), selectionError = null;
    const cards = new Map(), drafts = new Map();
    const status = copy => { $('#chat-status').textContent = copy; };
    function renderBlocks(chunks) {
        const ids = new Set(chunks.map(chunk => chunk.id));
        for (const [id, card] of cards) if (!ids.has(id)) { card.remove(); cards.delete(id); }
        for (const chunk of chunks) {
            let card = cards.get(chunk.id);
            if (!card) {
                card = document.createElement('article'); card.className = 'manuscript-sheet chunk-sheet';
                cards.set(chunk.id, card); $('#book-blocks').append(card);
            }
            const signature = `${chunk.revision}-${chunk.status}`;
            if (card.dataset.signature === signature) continue;
            card.dataset.signature = signature; card.replaceChildren();
            const toolbar = document.createElement('div'); toolbar.className = 'manuscript-toolbar block-toolbar';
            const count = document.createElement('strong');
            const edits = (chunk.segments || []).filter(segment => segment.type !== 'equal' && segment.status !== 'rejected').length;
            count.textContent = `#${chunk.position + 1} · ${t('block_edits')}: ${edits}`;
            toolbar.append(count); card.append(toolbar);
            const view = document.createElement('div'); view.className = 'manuscript-text review-paragraphs'; view.dataset.review = ''; card.append(view);
            let paragraph;
            const nextParagraph = () => { paragraph = document.createElement('p'); view.append(paragraph); };
            nextParagraph();
            for (const segment of chunk.segments || []) {
                if (segment.type === 'equal' || segment.status === 'rejected') {
                    const text = segment.status === 'rejected' ? segment.before : segment.after;
                    const styles = segment.status === 'rejected' ? segment.before_styles : segment.styles;
                    const italics = segment.status === 'rejected' ? segment.before_italics : segment.italics;
                    let offset = 0;
                    text.split('\n\n').forEach((part, index) => {
                        if (index) { nextParagraph(); offset += 2; }
                        const length = Array.from(part).length;
                        appendFormatted(paragraph, part, sliceRanges(italics || [], offset, length), sliceStyles(styles || [], offset, length));
                        offset += length;
                    });
                } else {
                    const change = document.createElement('span'); change.className = 'change'; change.dataset.status = 'pending';
                    if (segment.before) { const del = document.createElement('del'); appendFormatted(del, segment.before, segment.before_italics, segment.before_styles); change.append(del); }
                    if (segment.after) { const ins = document.createElement('ins'); appendFormatted(ins, segment.after, segment.italics, segment.styles); change.append(ins); }
                    paragraph.append(change);
                }
            }
        }
    }
    function renderProgress() {
        const run = state.run, total = run?.total_chunks || state.chunk_count || 1;
        const completed = run?.completed_chunks || 0;
        $('#work-progress').value = Math.round(completed / total * 100);
        $('#work-label').textContent = `${t(state.editorial_stage === 'awaiting_followup' ? 'chat_waiting_answer' : run?.status || 'no_run')} · ${completed} / ${total}`;
        $('#work-error').textContent = run?.error ? t(run.error) : '';
        $('#pause-work').classList.toggle('hidden', !['queued', 'running'].includes(run?.status) && !state.chat?.some(item => item.status === 'pending'));
    }
    function activeSuggestions() {
        return [...(state.chat || [])].reverse().find(item => item.role === 'assistant' && item.suggestions?.length && !item.submitted_at);
    }
    function updateSend() {
        const awaiting = state.chat?.some(item => item.status === 'pending');
        $('#chat-send').disabled = sending || awaiting || (!$('#chat-input').value.trim() && state.editorial_stage !== 'awaiting_consent' && !activeSuggestions());
    }
    function saveSelection(item, selected) {
        const draft = drafts.get(item.id) || {selected: item.selected || [], version: item.selection_version};
        draft.selected = selected; drafts.set(item.id, draft); selectionError = null;
        selectionWrites = selectionWrites.then(async () => {
            const result = await request(`${base}/chat/${item.id}/selections`, 'PATCH', {selected, version: draft.version});
            draft.version = result.version;
        }).catch(error => {
            selectionError = error; status(error.message); drafts.delete(item.id); chatSignature = '';
        });
        return selectionWrites;
    }
    function renderChat(messages) {
        const key = JSON.stringify(messages);
        if (key === chatSignature) { updateSend(); return; }
        chatSignature = key;
        const log = $('#chat-messages'), atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
        const scrollTop = log.scrollTop;
        log.replaceChildren();
        for (const item of messages) {
            const entry = document.createElement('article'); entry.className = `chat-message chat-${item.role}`;
            const role = document.createElement('strong'); role.textContent = t(item.role === 'user' ? 'you' : 'editorial_assistant');
            const body = document.createElement('p'); body.textContent = item.content || t(item.suggestions?.length ? 'chat_selected_sent' : 'chat_agree');
            entry.append(role, body);
            if (item.role === 'assistant') {
                const draft = drafts.get(item.id);
                if (draft && item.selection_version >= draft.version && JSON.stringify(item.selected || []) === JSON.stringify(draft.selected)) drafts.delete(item.id);
                const selected = drafts.get(item.id)?.selected || item.selected || [];
                (item.suggestions || []).forEach((suggestion, index) => {
                    const label = document.createElement('label'); label.className = 'chat-option';
                    const box = document.createElement('input'); box.type = 'checkbox'; box.checked = selected.includes(index);
                    box.disabled = !!item.submitted_at || sending; box.dataset.messageId = item.id;
                    const copy = document.createElement('span'), title = document.createElement('strong'), reason = document.createElement('small');
                    title.textContent = suggestion.label; reason.textContent = suggestion.reason || suggestion.instruction;
                    copy.append(title, reason); label.append(box, copy); entry.append(label);
                    box.addEventListener('change', () => {
                        const chosen = new Set(drafts.get(item.id)?.selected || item.selected || []);
                        if (box.checked) chosen.add(index); else chosen.delete(index);
                        saveSelection(item, [...chosen].sort((a, b) => a - b));
                    });
                });
                if (item.suggestions?.length) {
                    const hint = document.createElement('p'); hint.className = 'hint'; hint.textContent = t(item.submitted_at ? 'chat_selection_locked' : 'chat_check_send'); entry.append(hint);
                }
            } else if (item.suggestions?.length) {
                const summary = document.createElement('p'); summary.className = 'hint'; summary.textContent = item.suggestions.map(s => s.label).join(' · '); entry.append(summary);
            }
            if (item.status !== 'complete') {
                const hint = document.createElement('p'); hint.className = 'hint'; hint.textContent = t(item.status === 'failed' ? 'chat_failed' : 'chat_pending'); entry.append(hint);
            }
            log.append(entry);
        }
        log.scrollTop = atBottom ? log.scrollHeight : scrollTop; updateSend();
    }
    async function refresh(force = false) {
        if (polling) { if (!force) return polling; await polling; }
        const query = new URLSearchParams({all: 1, ...Object.fromEntries(Object.entries(tokens).map(([key, value]) => [`known_${key}`, value]))});
        polling = request(`${base}/state?${query}`).then(update => {
            tokens = update.tokens; state = {...state, ...update};
            window.leQueueState = update.queue;
            $('#book-title').textContent = state.title;
            renderChat(state.chat || []); renderProgress();
            if (update.chunks) renderBlocks(update.chunks);
            return update.queue;
        }).finally(() => { polling = null; });
        return polling;
    }
    $('#chat-input').addEventListener('input', updateSend);
    $('#chat-input').addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) {
            event.preventDefault(); if (!$('#chat-send').disabled) $('#chat-form').requestSubmit();
        }
    });
    $('#chat-form').addEventListener('submit', async event => {
        event.preventDefault(); if (sending || $('#chat-send').disabled) return;
        sending = true; updateSend();
        $$('#chat-messages input[type=checkbox]').forEach(box => { box.disabled = true; });
        const content = $('#chat-input').value;
        try {
            await selectionWrites;
            if (selectionError) throw selectionError;
            const suggestion = activeSuggestions();
            await request(`${base}/chat`, 'POST', {content, ...(suggestion ? {suggestion_id: suggestion.id, selection_version: drafts.get(suggestion.id)?.version || suggestion.selection_version} : {})});
            if ($('#chat-input').value === content) $('#chat-input').value = '';
            status(''); drafts.clear(); await refresh(true);
            window.dispatchEvent(new Event('queue-poll'));
        } catch (error) { status(error.message); }
        finally { sending = false; chatSignature = ''; renderChat(state.chat || []); }
    });
    $('#pause-work').addEventListener('click', async event => {
        event.target.disabled = true;
        try { await request(`${base}/cancel`, 'POST', {}); await refresh(true); }
        catch (error) { status(error.message); }
        finally { event.target.disabled = false; }
    });
    if (typeof ResizeObserver !== 'undefined') {
        const header = $('.site-header');
        new ResizeObserver(() => document.documentElement.style.setProperty('--editor-header-height', `${header.getBoundingClientRect().height}px`)).observe(header);
    }
    window.addEventListener('queue-updated', renderProgress);
    updateSend();
    return {poll: () => refresh()};
}
