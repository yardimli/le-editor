export function startDocxDownloads({ $, $$, t }) {
    const dialog = $('#docx-progress-dialog');
    let busy = false;
    const close = $('#close-docx-progress');
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    close.addEventListener('click', () => dialog.close());
    async function download(url) {
        if (busy) return;
        busy = true;
        const status = $('#docx-progress-status'), link = $('#docx-ready-link');
        link.hidden = true; close.disabled = true;
        $('#docx-progress-spinner').hidden = false;
        status.textContent = t('export_preparing');
        dialog.showModal();
        try {
            const prepareUrl = new URL(url, window.location.href);
            prepareUrl.pathname += '/prepare';
            prepareUrl.search = '';
            const response = await fetch(prepareUrl, {method: 'POST', credentials: 'same-origin', headers: {
                Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            }});
            if (!response.ok) throw new Error(t('export_failed'));
            const result = await response.json();
            if (!result.url) throw new Error(t('export_failed'));
            link.href = result.url; link.hidden = false;
            status.textContent = t('export_ready');
        } catch {
            status.textContent = t('export_failed');
        } finally {
            busy = false; close.disabled = false; $('#docx-progress-spinner').hidden = true;
        }
    }
    $$('[data-docx-download]').forEach(link => link.addEventListener('click', event => {
        event.preventDefault(); download(link.href);
    }));
    $('#export-form')?.addEventListener('submit', event => {
        event.preventDefault(); $('#export-dialog')?.close(); download(event.currentTarget.action);
    });
}
