import { startBookEditor } from './book-editor';
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
        const catalog = await request('/models');
        models = catalog.models;
        $$('[data-model-select]').forEach(select => {
            select.replaceChildren(new Option(t('select_model'), ''));
            models.forEach(model => select.add(new Option(`${model.name} · $${model.output_price.toFixed(2)} / 1M`, model.id)));
            select.value = models.some(model => model.id === catalog.default_model) ? catalog.default_model : ''; 
            select.dispatchEvent(new Event('change'));
        });
        if (!models.length) $$('.model-error').forEach(el => { el.textContent = t('catalog_unavailable'); });
    } catch (error) { $$('.model-error').forEach(el => { el.textContent = error.message; }); }
}
loadModels();

const bookEditor = window.leBook ? startBookEditor({ $, $$, request, t, confirmAction, getModels: () => models }) : null;
if (window.leQueue) startBrowserQueue({ $, request, t, window, document, setInterval, pollState: bookEditor?.poll });

$$('[data-open-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog).showModal()));
$$('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
