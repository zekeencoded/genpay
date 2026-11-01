/* Loading state for money actions (text only, no spinner).
 *
 * Usage
 *   Plain POST form:  <form method="POST" data-busy="Submitting…">
 *   Async handler:    if (!gpBusy(btn, 'Sending…')) return;   // already in flight
 *                     ... await ...
 *                     gpBusyDone(btn);                        // on EVERY failure path
 */
(function () {
    'use strict';

    var DEFAULT = 'Processing…';

    /* Returns false if already busy (ignore the double-click). */
    window.gpBusy = function (btn, text) {
        if (!btn || btn.dataset.gpBusy === '1') { return false; }
        btn.dataset.gpLabel = btn.innerHTML;
        btn.dataset.gpBusy = '1';
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
        btn.textContent = text || DEFAULT;
        return true;
    };

    window.gpBusyDone = function (btn) {
        if (!btn || btn.dataset.gpBusy !== '1') { return; }
        btn.innerHTML = btn.dataset.gpLabel;
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        delete btn.dataset.gpBusy;
        delete btn.dataset.gpLabel;
    };

    /* Plain POST forms; skip if validation called preventDefault. */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.matches || !form.matches('form[data-busy]')) { return; }
        if (e.defaultPrevented) { return; }
        gpBusy(form.querySelector('[type="submit"]'), form.dataset.busy);
    });
}());
