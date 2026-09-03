// Thin fetch wrappers matching the legacy booking-calendar.js contract
// exactly (same admin-ajax action names / REST paths / nonce headers) so
// the PHP side (class-bookflow-*.php) needs zero changes for this migration.

export function ajax(config, action, data) {
    data = { ...data, action, nonce: config.nonce };
    const formData = new FormData();
    for (const key in data) {
        if (Object.prototype.hasOwnProperty.call(data, key) && data[key] !== undefined) {
            formData.append(key, data[key]);
        }
    }

    return fetch(config.ajaxUrl, { method: 'POST', body: formData })
        .then((r) => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then((res) => {
            if (res && res.success) return res.data;
            throw new Error((res && res.data && res.data.message) || 'request_failed');
        });
}

export function restFetch(config, path) {
    return fetch(config.restUrl + path, {
        headers: { 'X-WP-Nonce': config.restNonce },
    }).then((r) => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    });
}
