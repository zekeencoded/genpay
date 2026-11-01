/* admin/merchant_details.php: inventory compliance toggle, then reload. */
(function () {
    const config = window.merchantDetailConfig || {};
    const endpoint = config.endpoint;
    if (!endpoint) return;

    const FLASH_KEY = 'gpMerchantFlash';

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const alertBox = document.getElementById('merchantAlert');

    const setAlert = (message, type = 'success') => {
        if (!alertBox) return;
        alertBox.innerHTML = message
            ? `<div class="alert alert-${type} py-2">${escapeHtml(message)}</div>`
            : '';
        if (message) alertBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };

    /* A reload wipes the alert, so carry it across. */
    const flashAndReload = (message, type) => {
        try {
            sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message, type }));
        } catch (err) { /* private browsing — the message is simply lost */ }
        location.reload();
    };

    try {
        const stored = sessionStorage.getItem(FLASH_KEY);
        if (stored) {
            sessionStorage.removeItem(FLASH_KEY);
            const flash = JSON.parse(stored);
            setAlert(flash.message, flash.type);
        }
    } catch (err) { /* nothing to restore */ }

    document.querySelectorAll('.js-toggle-restriction').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const shouldRestrict = Number(btn.dataset.restrict) === 1;

            // Restricting needs a reason on the record; clearing does not.
            let note = '';
            if (shouldRestrict) {
                note = window.prompt(
                    `Why is "${btn.dataset.name}" being restricted?`,
                    'Restricted by school nutritional compliance review.'
                );
                if (note === null) return;
            }

            btn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'toggle_product_restriction');
            fd.append('item_id', btn.dataset.itemId);
            fd.append('restricted', shouldRestrict ? '1' : '0');
            fd.append('note', note);

            try {
                const response = await fetch(endpoint, { method: 'POST', body: fd });
                const data = await response.json();
                if (data.success) {
                    flashAndReload(data.message, 'success');
                    return;
                }
                setAlert(data.message || 'Could not update that product.', 'danger');
            } catch (err) {
                setAlert('Network error. The product was not changed.', 'danger');
            }

            btn.disabled = false;
        });
    });
})();
