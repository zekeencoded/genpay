/* GenPay — one way for the finance side to talk back to the user.
 *
 * Before this file the admin pages had six different ways to report the same
 * thing: window.alert(), a hand-rolled Bootstrap toast that lived inside
 * stall_applications.php, a server-rendered banner in settings.php, an
 * .alert-danger injected into a modal body, inline rp-modal-alert divs, and —
 * on four money-moving actions — nothing at all. A tester moving between pages
 * had to relearn the interface on each one.
 *
 * Nothing here is new. gpToast is the toast() from stall_applications.php,
 * gpFetchJson is the non-JSON guard from the same file (the only place that
 * handled a PHP fatal gracefully), and gpConfirm generalises the two best
 * confirmations in the app: the fee breakdown in cash_management.php's confirmation dialogs
 * and the type-the-word gate on users.php's ban modal.
 *
 * Depends only on bootstrap.bundle.min.js, which every admin page already
 * loads. No new libraries.
 *
 * Usage
 *   gpToast('Top-up approved.');                 // success
 *   gpToast('Could not reach the server.', false);
 *
 *   if (!await gpConfirm({
 *         title: 'Release this encashment?',
 *         detail: [['Merchant', name], ['Amount', gpMoney(amt)]],
 *         note: 'The funds leave the vault immediately. This cannot be undone.',
 *         confirmLabel: 'Release funds',
 *   })) return;
 *
 *   const res = await gpFetchJson('release_encashment.php', form);
 *   gpToast(res.message, res.success);
 */
(function () {
    'use strict';

    /* ── escaping ────────────────────────────────────────────────────────────
       Defined identically in admin_leases.js, admin_lease_details.js and
       admin_merchant_details.js, plus an esc() inline in two pages. Those stay
       for now; new code uses this one. */
    var ESCAPES = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return ESCAPES[c];
        });
    }
    window.gpEsc = esc;

    /* ── money ───────────────────────────────────────────────────────────────
       Mirrors PHP's gjc_money(). The admin side had three conventions, two of
       them on one page: the Top-ups page showed "P15000.00" in the confirmation you
       approve money against and "P15,000.00" in the modal a hundred lines
       below. At a glance those two are easy to confuse. */
    window.gpMoney = function (pesos) {
        var n = Number(pesos);
        if (!isFinite(n)) { n = 0; }
        return '₱' + n.toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    };

    /* ── toast ───────────────────────────────────────────────────────────────
       The container is created on demand so a page opts in with the script tag
       alone and there is no markup to forget. */
    function toastHost() {
        var wrap = document.getElementById('gpToastHost');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.id = 'gpToastHost';
            wrap.className = 'gp-toast-host';
            wrap.setAttribute('aria-live', 'polite');
            wrap.setAttribute('aria-atomic', 'true');
            document.body.appendChild(wrap);
        }
        return wrap;
    }

    window.gpToast = function (message, ok) {
        var isOk = ok !== false;
        var el = document.createElement('div');
        el.className = 'toast gp-toast ' + (isOk ? 'gp-toast--ok' : 'gp-toast--err');
        el.setAttribute('role', isOk ? 'status' : 'alert');
        el.innerHTML =
            '<div class="d-flex">' +
                '<div class="toast-body"><i class="fa-solid ' +
                    (isOk ? 'fa-circle-check' : 'fa-circle-exclamation') +
                '"></i><span></span></div>' +
                '<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Dismiss"></button>' +
            '</div>';
        el.querySelector('.toast-body span').textContent =
            String(message || (isOk ? 'Done.' : 'Something went wrong.'));
        toastHost().appendChild(el);

        /* Failures stay up longer — they usually carry something to act on. */
        var t = new bootstrap.Toast(el, { delay: isOk ? 4500 : 8000 });
        t.show();
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        return t;
    };

    /* ── confirm ─────────────────────────────────────────────────────────────
       Returns a Promise<boolean>. Resolves false on cancel, Esc, or backdrop.

       opts:
         title          required, the question
         body           optional sentence of context
         detail         optional [[label, value], ...] — what / how much / to whom
         note           optional consequence line, shown in a tinted strip
         confirmLabel   default 'Confirm'
         cancelLabel    default 'Cancel'
         danger         true -> red confirm button
         typeToConfirm  e.g. 'BAN' — the button stays disabled until it matches */
    window.gpConfirm = function (opts) {
        opts = opts || {};

        return new Promise(function (resolve) {
            var rows = (opts.detail || []).map(function (pair) {
                return '<div class="gp-confirm-row"><span>' + esc(pair[0]) +
                       '</span><strong>' + esc(pair[1]) + '</strong></div>';
            }).join('');

            var typeWord = opts.typeToConfirm ? String(opts.typeToConfirm) : '';

            var el = document.createElement('div');
            el.className = 'modal fade';
            el.tabIndex = -1;
            el.setAttribute('aria-modal', 'true');
            el.setAttribute('role', 'dialog');
            el.innerHTML =
                '<div class="modal-dialog modal-dialog-centered gp-confirm-dialog">' +
                  '<div class="modal-content">' +
                    '<div class="modal-header border-0 pb-0">' +
                      '<h5 class="modal-title gp-confirm-title"></h5>' +
                      '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body">' +
                      (opts.body ? '<p class="gp-confirm-body"></p>' : '') +
                      (rows ? '<div class="gp-confirm-detail">' + rows + '</div>' : '') +
                      (opts.note ? '<p class="gp-confirm-note' +
                            (opts.danger ? ' gp-confirm-note--danger' : '') + '"></p>' : '') +
                      (typeWord
                        ? '<label class="gp-confirm-typelabel" for="gpConfirmType">Type ' +
                          '<strong>' + esc(typeWord) + '</strong> to continue</label>' +
                          '<input type="text" class="form-control" id="gpConfirmType" ' +
                          'autocomplete="off" spellcheck="false">'
                        : '') +
                    '</div>' +
                    '<div class="modal-footer border-0 pt-0">' +
                      '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"></button>' +
                      '<button type="button" class="btn ' +
                        (opts.danger ? 'btn-danger' : 'btn-success') + '" id="gpConfirmGo"></button>' +
                    '</div>' +
                  '</div>' +
                '</div>';

            /* textContent, not innerHTML — these carry user-supplied names. */
            el.querySelector('.gp-confirm-title').textContent = opts.title || 'Are you sure?';
            if (opts.body) { el.querySelector('.gp-confirm-body').textContent = opts.body; }
            if (opts.note) { el.querySelector('.gp-confirm-note').textContent = opts.note; }
            el.querySelector('.modal-footer .btn-outline-secondary').textContent =
                opts.cancelLabel || 'Cancel';

            var go = el.querySelector('#gpConfirmGo');
            go.textContent = opts.confirmLabel || 'Confirm';

            document.body.appendChild(el);
            var modal = new bootstrap.Modal(el);
            var answered = false;

            if (typeWord) {
                var field = el.querySelector('#gpConfirmType');
                go.disabled = true;
                field.addEventListener('input', function () {
                    go.disabled = field.value.trim().toUpperCase() !== typeWord.toUpperCase();
                });
                el.addEventListener('shown.bs.modal', function () { field.focus(); });
            } else {
                el.addEventListener('shown.bs.modal', function () { go.focus(); });
            }

            go.addEventListener('click', function () {
                answered = true;
                modal.hide();
            });

            el.addEventListener('hidden.bs.modal', function () {
                el.remove();
                resolve(answered);
            });

            modal.show();
        });
    };

    /* ── inline explainers ───────────────────────────────────────────────────
       The .gp-howto collapsible ("How rent collection works here") is a good
       component that existed on exactly one page, with its open/closed state
       bound by id inside admin_leases.js. This is the same behaviour, bound by
       attribute so any page can use it:

         <section class="gp-howto is-footnote">
           <button class="gp-howto-toggle" data-howto="topupVault" aria-expanded="true">…</button>
           <div class="gp-howto-body">…</div>
         </section>

       data-howto is the localStorage key. leases.php's toggle deliberately does
       NOT carry the attribute, so its own handler keeps working and nothing
       double-binds; it can migrate here later. */
    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(
            document.querySelectorAll('.gp-howto-toggle[data-howto]'),
            function (toggle) {
                var body = toggle.parentElement.querySelector('.gp-howto-body');
                if (!body) { return; }
                var key = 'gpHowto.' + toggle.dataset.howto;
                var collapsed = false;
                try { collapsed = localStorage.getItem(key) === 'collapsed'; } catch (e) {}

                function apply() {
                    body.classList.toggle('is-collapsed', collapsed);
                    toggle.classList.toggle('is-collapsed', collapsed);
                    toggle.setAttribute('aria-expanded', String(!collapsed));
                }
                apply();

                toggle.addEventListener('click', function () {
                    collapsed = !collapsed;
                    apply();
                    try {
                        localStorage.setItem(key, collapsed ? 'collapsed' : 'open');
                    } catch (e) {}
                });
            }
        );
    });

    /* ── fetch ───────────────────────────────────────────────────────────────
       Always resolves to an object with .success and .message, so no caller
       needs its own try/catch. Two failures were reaching the finance officer
       raw: a PHP fatal made the endpoint emit HTML and res.json() threw
       "Unexpected token '<'", and four money actions had no catch at all, so a
       dropped connection showed nothing whatsoever.

       Bodies: a FormData is sent as-is, a plain object becomes FormData (the
       house convention for admin/api/*), and {json: {...}} sends a JSON request
       body — admin/api/mint.php is the one endpoint that reads php://input, so
       it gets JSON rather than having its contract changed to suit this. */
    window.gpFetchJson = function (url, body) {
        var init = { method: 'POST' };
        if (body instanceof FormData) {
            init.body = body;
        } else if (body && typeof body === 'object' && body.json) {
            init.headers = { 'Content-Type': 'application/json' };
            init.body = JSON.stringify(body.json);
        } else if (body && typeof body === 'object') {
            var fd = new FormData();
            Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
            init.body = fd;
        }

        return fetch(url, init).then(function (res) {
            return res.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Bad response from ' + url + ':', text);
                    return {
                        success: false,
                        message: res.ok
                            ? 'The server returned an invalid response. Nothing was changed.'
                            : 'The server returned an error (' + res.status + '). Nothing was changed.'
                    };
                }
            });
        }).catch(function (err) {
            console.error('Request to ' + url + ' failed:', err);
            return {
                success: false,
                message: 'Could not reach the server. Check your connection and try again.'
            };
        });
    };
}());
