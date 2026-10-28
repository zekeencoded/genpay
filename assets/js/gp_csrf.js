// Adds the session CSRF token (window.GJC_CSRF, set by includes/partials/csrf_bootstrap.php)
// to every same-origin POST that does not already carry it. Pages keep calling fetch()
// as before; the server rejects any state-changing request without this header.
(function () {
    var nativeFetch = window.fetch;
    window.fetch = function (input, init) {
        init = init || {};
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var method = (init.method || 'GET').toUpperCase();
        var sameOrigin = new URL(url, location.href).origin === location.origin;
        if (method === 'POST' && sameOrigin && window.GJC_CSRF) {
            init.headers = new Headers(init.headers || {});
            if (!init.headers.has('X-CSRF-Token')) {
                init.headers.set('X-CSRF-Token', window.GJC_CSRF);
            }
        }
        return nativeFetch.call(this, input, init);
    };

    // Classic form posts carry the token as a hidden field. Added in the capture
    // phase so page submit handlers that build FormData from the form include it.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!window.GJC_CSRF || (form.method || '').toUpperCase() !== 'POST') return;
        if (form.querySelector('input[name="csrf_token"]')) return;
        var field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'csrf_token';
        field.value = window.GJC_CSRF;
        form.appendChild(field);
    }, true);
}());
