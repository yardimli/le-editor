import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { chunkText, changeKey } from '../resources/js/chunk-text.js';
import { italicOps, italicRanges, readItalics } from '../resources/js/italics.js';
import { chunkStyles, sliceStyles } from '../resources/js/source-styles.js';

class Element {
    constructor() {
        this.children = []; this.nodes = new Map(); this.listeners = {}; this.dataset = {}; this.value = '';
        const classes = new Set();
        this.classList = { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c),
            toggle(c, on) { if (on) classes.add(c); else classes.delete(c); } };
    }
    querySelector(key) { if (!this.nodes.has(key)) this.nodes.set(key, new Element()); return this.nodes.get(key); }
    append(...children) { for (const child of children) { child.parent = this; this.children.push(child); } }
    replaceChildren(...children) { this.children = []; this.append(...children); }
    setAttribute(key, value) { this[key] = value; }
    addEventListener(event, fn) { this.listeners[event] = fn; }
    showModal() { this.open = true; }
    close() { this.open = false; }
    focus() {}
    remove() {}
}

function harness() {
    const root = new Element();
    let quill;
    class Quill {
        static import() { return {}; } static register() {}
        constructor() { quill = this; this.root = new Element(); this.history = { clear() {} }; this.ops = []; }
        getText() { return this.ops.map(op => op.insert).join(''); }
        getContents() { return {ops:this.ops}; }
        setContents(value) { this.ops = value.ops; }
        getSelection() { return null; }
        enable() {} focus() {}
        on(_, handler) { this.handler = handler; }
        type(text) { this.ops = [{insert:text+'\n'}]; this.handler(null, null, 'user'); }
    }
    const chunks = [1,2].map(id => ({id, position:id-1, revision:1, status:id === 1 ? 'completed' : 'pending',
        segments:[{type:'equal', before:`Block ${id}`, after:`Block ${id}`, status:'accepted'}]}));
    const calls = []; let failSave = false;
    const $ = (selector, parent = root) => parent.querySelector(selector);
    const context = vm.createContext({ Quill, chunkText, changeKey, italicOps, italicRanges, readItalics, chunkStyles, sliceStyles,
        appendFormatted() {}, sliceRanges: () => [], setInterval() {}, URLSearchParams,
        document: {createElement: () => new Element(), addEventListener() {}},
        window: {leBook:{id:1, version:1}, addEventListener() {}},
    });
    const source = readFileSync(new URL('../resources/js/book-editor.js', import.meta.url), 'utf8')
        .replace(/^import .*;\r?\n/gm, '').replace('export function startBookEditor', 'function startBookEditor');
    vm.runInContext(source, context);
    const editor = context.startBookEditor({ $, $$: () => [], t:key=>key, getModels:()=>[], request:async (url, method, data) => {
        calls.push({url, method, data});
        if (method === 'PUT') {
            if (failSave) throw Error('Save failed');
            const chunk = chunks.find(c => url.includes(`/chunks/${c.id}/`));
            chunk.revision++; chunk.segments = [{type:'equal', before:data.text, after:data.text, status:'accepted'}];
            return {version:2, chunk:structuredClone(chunk)};
        }
        return {version:2, tokens:{}, chunks:structuredClone(chunks), chunk_count:2,
            run:{id:1,status:'cancelled',completed_chunks:1,total_chunks:2}, queue:{}, metadata_status:'complete'};
    }});
    return {editor, $, calls, quill:()=>quill, fail:()=>{failSave=true;}, cards:()=>$('#book-blocks').children};
}

test('switching blocks saves the previous editor and returns it to review', async () => {
    const h = harness(); await h.editor.poll();
    const [first, second] = h.cards();
    assert.equal(second.querySelector('[data-status]').textContent, 'chunk_not_processed');
    await first.querySelector('[data-edit]').listeners.click();
    h.quill().type('Saved first block');
    await second.querySelector('[data-edit]').listeners.click();
    assert.equal(h.calls.filter(c => c.method === 'PUT').length, 1);
    assert.equal(h.calls.find(c => c.method === 'PUT').data.text, 'Saved first block');
    assert.equal(first.querySelector('[data-review]').classList.contains('hidden'), false);
    assert.equal(second.querySelector('[data-review]').classList.contains('hidden'), true);
    assert.equal(h.$('#block-edit-panel').parent, second.querySelector('[data-edit-slot]'));
    h.quill().type('Saved second block');
    await second.querySelector('[data-edit]').listeners.click();
    assert.equal(h.calls.filter(c => c.method === 'PUT').length, 2);
    assert.equal(second.querySelector('[data-review]').classList.contains('hidden'), false);
    assert.equal(h.$('#block-edit-panel').parent, h.$('#editor-parking'));
    await first.querySelector('[data-redo]').listeners.click();
    assert.equal(h.$('#setup-dialog').open, true);
    assert.equal(h.$('#setup-hint').textContent, 'block_review_hint');
});

test('a failed save keeps the original block open and retains the unsaved text', async () => {
    const h = harness(); await h.editor.poll();
    const [first, second] = h.cards();
    await first.querySelector('[data-edit]').listeners.click(); h.quill().type('Do not lose this'); h.fail();
    await second.querySelector('[data-edit]').listeners.click();
    assert.equal(h.quill().getText(), 'Do not lose this\n');
    assert.equal(h.$('#block-edit-panel').parent, first.querySelector('[data-edit-slot]'));
    assert.equal(first.querySelector('[data-review]').classList.contains('hidden'), true);
    assert.equal(second.querySelector('[data-review]').classList.contains('hidden'), false);
});
