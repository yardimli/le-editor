export function startDocxDownloads({ $, $$, t }) {
    const dialog = $('#docx-progress-dialog');
    let busy = false, objectUrl = null;
    const close = $('#close-docx-progress');
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    close.addEventListener('click', () => dialog.close());
    async function download(url) {
        if (busy) return;
        busy = true;
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        const status = $('#docx-progress-status'), link = $('#docx-ready-link');
        link.hidden = true; close.disabled = true;
        $('#docx-progress-spinner').hidden = false;
        status.textContent = t('export_preparing');
        dialog.showModal();
        try {
            const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'}});
            if (!response.ok || !response.headers.get('content-type')?.includes('application/vnd.openxmlformats-officedocument.wordprocessingml.document')) throw new Error(t('export_failed'));
            status.textContent = t('export_receiving');
            const blob = await response.blob();
            const disposition = response.headers.get('content-disposition') || '';
            const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
            const plain = disposition.match(/filename="([^"]+)"|filename=([^;]+)/i);
            let name = plain?.[1] || plain?.[2] || 'manuscript.docx';
            if (encoded) { try { name = decodeURIComponent(encoded[1]); } catch {} }
            objectUrl = URL.createObjectURL(blob);
            link.href = objectUrl; link.download = name; link.hidden = false;
            status.textContent = t('export_ready');
            link.click();
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
