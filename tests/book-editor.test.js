import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

class Element {
    constructor(tag = 'div') {
        this.tag = tag; this.children = []; this.nodes = new Map(); this.listeners = {}; this.dataset = {}; this.value = '';
        this.scrollTop = 0; this.scrollHeight = 0; this.clientHeight = 0;
        const classes = new Set();
        this.classList = {add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c), toggle(c, on) { if (on) classes.add(c); else classes.delete(c); }};
    }
    querySelector(key) { if (!this.nodes.has(key)) this.nodes.set(key, new Element()); return this.nodes.get(key); }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    addEventListener(event, fn) { this.listeners[event] = fn; }
    remove() {}
    requestSubmit() { this.submitted = (this.submitted || 0) + 1; }
}
function descendants(node) { return node.children.flatMap(child => [child, ...descendants(child)]); }
function harness() {
    const root = new Element(), calls = [];
    const $ = selector => root.querySelector(selector);
    const boxes = () => descendants($('#chat-messages')).filter(el => el.tag === 'input');
    const state = {tokens: {}, title: 'Book', editorial_stage: 'awaiting_consent', chat: [], chunks: [{id: 1, revision: 1, position: 0, status: 'completed', segments: [{type:'replace', before:'Old', after:'New', status:'pending'}]}]};
    const context = vm.createContext({URLSearchParams, Event, document:{createElement: tag => new Element(tag)},
        window:{leBook:{id:1}, addEventListener(){}, dispatchEvent(){}}, appendFormatted:(el,text)=>el.textContent=text, sliceRanges:()=>[], sliceStyles:()=>[]});
    const source = readFileSync(new URL('../resources/js/book-editor.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '').replace('export function startBookEditor', 'function startBookEditor');
    vm.runInContext(source, context);
    const editor = context.startBookEditor({$, $$: () => boxes(), t: key => key, request: async (url, method, data) => {
        calls.push({url,method,data});
        if (method === 'PATCH') {
            const item = state.chat[0]; assert.equal(data.version, item.selection_version);
            item.selected = [...data.selected]; item.selection_version++;
            return {version:item.selection_version};
        }
        if (method === 'POST' && url.endsWith('/chat')) {
            if (data.suggestion_id) state.chat[0].submitted_at = 'now';
            state.chat.push({id:2,role:'user',content:data.content,status:'pending'});
            return {ok:true};
        }
        return structuredClone(state);
    }});
    return {$, boxes, editor, state, calls};
}

test('manuscript displays tracked changes without editing or decision controls', async () => {
    const h = harness(); await h.editor.poll();
    const nodes = descendants(h.$('#book-blocks'));
    assert.ok(nodes.some(n => n.tag === 'del')); assert.ok(nodes.some(n => n.tag === 'ins'));
    assert.equal(nodes.some(n => ['button','textarea','input'].includes(n.tag)), false);
});

test('Enter sends, Shift+Enter and IME composition do not submit', async () => {
    const h = harness(); await h.editor.poll();
    let prevented = 0;
    const event = {key:'Enter', shiftKey:false, isComposing:false, preventDefault(){prevented++;}};
    h.$('#chat-input').listeners.keydown(event);
    assert.equal(h.$('#chat-form').submitted, 1); assert.equal(prevented, 1);
    h.$('#chat-input').listeners.keydown({...event,shiftKey:true});
    h.$('#chat-input').listeners.keydown({...event,isComposing:true});
    assert.equal(h.$('#chat-form').submitted, 1);
});

test('selection writes finish before Send and submitted suggestions stay readonly after refresh', async () => {
    const h = harness();
    h.state.editorial_stage = 'ready';
    h.state.chat = [{id:1,role:'assistant',content:'Plan',status:'complete',selection_version:1,selected:[],suggestions:[{label:'Grammar',instruction:'Fix grammar',reason:'Several grammar errors'}]}];
    await h.editor.poll();
    const box = h.boxes()[0]; box.checked = true; box.listeners.change();
    await h.$('#chat-form').listeners.submit({preventDefault(){}});
    assert.equal(h.calls.find(c => c.method === 'PATCH').data.selected[0], 0);
    const sent = h.calls.find(c => c.method === 'POST').data;
    assert.equal(sent.selection_version, 2); assert.equal(sent.suggestion_id, 1);
    await h.editor.poll();
    assert.equal(h.boxes()[0].checked, true); assert.equal(h.boxes()[0].disabled, true);
});

test('empty messages are allowed for opening consent but not ordinary conversation', async () => {
    const h = harness(); await h.editor.poll(); assert.equal(h.$('#chat-send').disabled, false);
    h.state.editorial_stage = 'ready'; await h.editor.poll(); assert.equal(h.$('#chat-send').disabled, true);
    h.$('#chat-input').value = 'Start editing'; h.$('#chat-input').listeners.input(); assert.equal(h.$('#chat-send').disabled, false);
});
