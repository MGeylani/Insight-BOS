// Upload files to a record with fetch() (used by the rich-text editor for pasted images).
function xsrf() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** @returns {Promise<{id:number,name:string,status:string,src:string,error:?string}>} */
export async function uploadAttachment(type, id, file) {
    const fd = new FormData();
    fd.append('type', type);
    fd.append('id', id);
    fd.append('files[]', file, file.name || 'image.png');
    const r = await fetch('/attachments', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
    });
    const json = await r.json().catch(() => ({}));
    if (!r.ok) {
        const first = json.errors ? Object.values(json.errors)[0] : null;
        throw new Error((Array.isArray(first) ? first[0] : first) || json.message || 'تعذّر رفع الصورة.');
    }
    return json.attachments[0];
}
