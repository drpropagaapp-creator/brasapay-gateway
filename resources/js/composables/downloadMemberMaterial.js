export function isProtectedPdf(lesson, file) {
    if (!lesson?.watermark_enabled) {
        return false;
    }
    const name = String(file?.name || '').toLowerCase();
    const path = String(file?.url || '').split('?')[0].toLowerCase();
    return name.endsWith('.pdf') || path.endsWith('.pdf');
}

function filenameFromDisposition(header) {
    if (!header) {
        return '';
    }
    const star = /filename\*=UTF-8''([^;]+)/i.exec(header);
    if (star) {
        try {
            return decodeURIComponent(star[1]);
        } catch {
            return '';
        }
    }
    const plain = /filename="([^"]+)"/i.exec(header);
    return plain ? plain[1] : '';
}

function pdfName(name) {
    const base = String(name || 'material').trim() || 'material';
    return base.toLowerCase().endsWith('.pdf') ? base : `${base}.pdf`;
}

export async function downloadMemberMaterial(url, fallbackName) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/pdf, application/json' },
    });
    const type = response.headers.get('content-type') || '';

    if (!response.ok || !type.includes('application/pdf')) {
        let message = 'Não foi possível proteger este PDF.';
        if (type.includes('application/json')) {
            const data = await response.json().catch(() => ({}));
            if (data?.message) {
                message = String(data.message);
            }
        }
        throw new Error(message);
    }

    const blob = await response.blob();
    const headerName = filenameFromDisposition(response.headers.get('content-disposition'));
    const filename = pdfName(headerName || fallbackName);
    const objectUrl = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }));
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
}
