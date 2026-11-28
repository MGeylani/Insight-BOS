// Small JSON requests with the CSRF cookie (for dialogs that talk to the server without a page visit).
function xsrf() {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** @returns {Promise<any>} the JSON body; throws an Error with the first validation message. */
export async function json(method, url, body) {
    const r = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) {
        const first = data.errors ? Object.values(data.errors)[0] : null;
        throw new Error((Array.isArray(first) ? first[0] : first) || data.message || 'تعذّر تنفيذ الطلب.');
    }
    return data;
}
