import { test } from 'node:test';
import assert from 'node:assert/strict';
import { startBrowserQueue } from '../resources/js/browser-queue.js';

const flush = () => new Promise(resolve => setImmediate(resolve));
function surface() {
    const handlers = {}, classes = new Set();
    return {
        open: false, hidden: false, textContent: '', value: 0,
        classList: { toggle(name, force) { if (force) classes.add(name); else classes.delete(name); }, add(name) { classes.add(name); }, remove(name) { classes.delete(name); }, contains(name) { return classes.has(name); } },
        addEventListener(name, fn) { handlers[name] = fn; },
        dispatchEvent() {},
        emit(name, event = {}) { return handlers[name]?.(event); },
        showModal() { this.open = true; }, close() { this.open = false; },
        removeAttribute(name) { delete this[name]; },
    };
}
function setup(initial) {
    let state = initial, finishStep, timer;
    const elements = new Map(), calls = [];
    const window = surface(), document = surface();
    const $ = id => {
        if (!elements.has(id)) elements.set(id, surface());
        return elements.get(id);
    };
    startBrowserQueue({
        $, window, document, t: key => key, setInterval: fn => { timer = fn; },
        request: async url => {
            calls.push(url);
            if (url === '/queue/step') return new Promise(resolve => { finishStep = resolve; });
            if (url === '/queue/cancel') state = { ...state, paused: true, working: false };
            if (url === '/queue/resume') state = { ...state, paused: false, working: false };
            return state;
        },
    });
    return { $, calls, window, document, tick: () => timer(),
        finish: result => finishStep(result), setState: result => { state = result; } };
}
const active = { paused: false, working: false,
    task: { kind: 'editing', title: 'Book', completed: 1, total: 3 } };

test('prompt learning uses the header without interrupting review with a modal', async () => {
    const ui = setup({ ...active, task: { kind: 'learning', title: 'Book' } });
    await flush();
    assert.equal(ui.$('#queue-dialog').open, false);
    assert.equal(ui.$('#header-ai-status').textContent, 'queue_learning');
    ui.finish({ ...active, task: null, learning_failed: 0 });
    await flush();
    assert.equal(ui.$('#header-ai-status').textContent, 'prompt_updated');
    assert.equal(ui.$('#queue-dialog').open, false);
});

test('header cancellation pauses learning without opening the editing dialog', async () => {
    const ui = setup({ ...active, task: { kind: 'learning', title: 'Book' } });
    await flush();
    await ui.$('#header-ai-cancel').emit('click', { target: {} });
    assert.equal(ui.$('#header-ai-status').textContent, 'queue_paused_short');
    assert.equal(ui.$('#queue-dialog').open, false);
    assert.ok(ui.calls.includes('/queue/cancel'));
});

test('publishes progress without an editing modal and sends one step at a time', async () => {
    const ui = setup(active);
    await flush();
    assert.equal(ui.$('#queue-dialog').open, false);
    assert.equal(ui.window.leQueueState.task.completed, 1);
    await ui.tick();
    assert.equal(ui.calls.filter(url => url === '/queue/step').length, 1);
    ui.finish({ ...active, task: null });
    await flush();
    assert.equal(ui.$('#queue-dialog').open, false);
});

test('closing or hiding the page prevents further steps; returning resumes', async () => {
    const ui = setup(active);
    await flush();
    ui.window.emit('pagehide');
    ui.finish(active);
    await flush();
    const count = ui.calls.length;
    await ui.tick();
    assert.equal(ui.calls.length, count);
    ui.window.emit('pageshow');
    await flush();
    assert.equal(ui.calls.filter(url => url === '/queue/step').length, 2);
    ui.document.hidden = true;
    ui.finish(active);
    await flush();
    await ui.tick();
    assert.equal(ui.calls.filter(url => url === '/queue/step').length, 2);
});

test('cancel stays paused and ignores a late response until explicit resume', async () => {
    const ui = setup(active);
    await flush();
    await ui.$('#queue-cancel').emit('click', { target: {} });
    ui.finish(active);
    await flush();
    await ui.tick();
    assert.equal(ui.$('#queue-dialog').open, false);
    assert.equal(ui.calls.filter(url => url === '/queue/step').length, 1);
    await ui.$('#queue-resume').emit('click', { target: {} });
    await flush();
    assert.equal(ui.$('#queue-dialog').open, false);
    assert.equal(ui.calls.filter(url => url === '/queue/step').length, 2);
});

test('a refreshed paused page does not run jobs, and Escape cannot dismiss busy work', async () => {
    const ui = setup({ ...active, paused: true });
    await flush();
    assert.equal(ui.calls.includes('/queue/step'), false);
    let prevented = false;
    ui.$('#queue-dialog').emit('cancel', { preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
});

test('footer spinner and active wording follow editing, pause and completion', async () => {
    const ui = setup(active);
    await flush();
    assert.equal(ui.$('#header-ai-spinner').classList.contains('hidden'), false);
    assert.equal(ui.$('#header-ai-status').textContent, 'queue_editing');
    await ui.$('#queue-cancel').emit('click', { target: {} });
    assert.equal(ui.$('#header-ai-spinner').classList.contains('hidden'), true);
    await ui.$('#queue-resume').emit('click', { target: {} });
    ui.setState({ ...active, task: null });
    await ui.tick();
    assert.equal(ui.$('#header-ai-spinner').classList.contains('hidden'), true);
    assert.equal(ui.$('#header-ai-status').textContent, 'completed');
});

test('editor uses one combined poll at three-second intervals without queue state GETs', async () => {
    const elements = new Map(), calls = [], window = surface(), document = surface();
    const $ = id => { if (!elements.has(id)) elements.set(id, surface()); return elements.get(id); };
    let timer, delay, polls = 0;
    startBrowserQueue({ $, window, document, t: key => key,
        setInterval: (fn, ms) => { timer = fn; delay = ms; },
        pollState: async () => { polls++; return {paused: true, working: false, task: null}; },
        request: async url => { calls.push(url); return {}; },
    });
    await flush();
    assert.equal(delay, 3000); assert.equal(polls, 1); assert.deepEqual(calls, []);
    window.emit('pageshow'); await flush(); assert.equal(polls, 1);
    await timer(); assert.equal(polls, 2); assert.deepEqual(calls, []);
});
