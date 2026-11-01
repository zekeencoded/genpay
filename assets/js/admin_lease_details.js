/* admin/lease_details.php: wires the write actions to admin/api/leases.php, then reloads. */
(function () {
    const config = window.leaseDetailConfig || {};
    const endpoint = config.endpoint;
    if (!endpoint) return;

    const FLASH_KEY = 'gpLeaseFlash';

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const money = (value) => new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(value || 0));

    const alertBox = document.getElementById('leaseAlert');

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

    const postForm = async (formData) => {
        const response = await fetch(endpoint, { method: 'POST', body: formData });
        return response.json();
    };

    /* ── Suggested amount follows the month being paid ─────────────────── */
    const periodSelect = document.getElementById('leasePayPeriod');
    const amountField = document.getElementById('leasePayAmount');
    const periodHint = document.getElementById('leasePeriodHint');

    function syncSuggestedAmount() {
        const opt = periodSelect?.options[periodSelect.selectedIndex];
        if (!opt || !amountField) return;

        const owed = Number(opt.dataset.owed || 0);
        if (owed > 0) {
            amountField.value = owed.toFixed(2);
            if (periodHint) periodHint.textContent = `${money(owed)} outstanding for this month.`;
        } else {
            amountField.value = '';
            if (periodHint) {
                periodHint.textContent = 'This month is already covered — anything you add counts as extra.';
            }
        }
    }

    periodSelect?.addEventListener('change', syncSuggestedAmount);
    if (periodSelect && periodSelect.value) syncSuggestedAmount();

    /* ── Clicking a month in the schedule pays that month ──────────────── */
    document.querySelectorAll('.js-schedule-row').forEach((tr) => {
        const jump = () => {
            if (!periodSelect) return;
            periodSelect.value = tr.dataset.period;
            syncSuggestedAmount();
            amountField?.focus();
            amountField?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        };
        tr.addEventListener('click', jump);
        tr.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                jump();
            }
        });
    });

    /* ── Record a payment ──────────────────────────────────────────────── */
    document.getElementById('leasePaymentForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('leasePaySubmit');
        btn.disabled = true;
        btn.textContent = 'Recording…';

        try {
            const data = await postForm(new FormData(e.currentTarget));
            if (data.success) {
                flashAndReload(data.message, 'success');
                return;
            }
            setAlert(data.message || 'Could not record that payment.', 'danger');
        } catch (err) {
            setAlert('Network error. The payment was not recorded.', 'danger');
        }

        btn.disabled = false;
        btn.textContent = 'Record payment';
    });

    /* ── Save the contract ─────────────────────────────────────────────── */
    document.getElementById('leaseEditForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('leaseEditSubmit');
        btn.disabled = true;
        btn.textContent = 'Saving…';

        try {
            const data = await postForm(new FormData(e.currentTarget));
            if (data.success) {
                flashAndReload(data.message || 'Contract updated.', 'success');
                return;
            }
            setAlert(data.message || 'Could not save the contract.', 'danger');
        } catch (err) {
            setAlert('Network error. The contract was not saved.', 'danger');
        }

        btn.disabled = false;
        btn.textContent = 'Save contract';
    });

    /* ── Remove a mis-keyed receipt ────────────────────────────────────── */
    document.querySelectorAll('.js-void-payment').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const ok = window.confirm(
                `Remove ${btn.dataset.amount} (${btn.dataset.ref}) from this lease?\n\n` +
                'The month it covered goes back to unpaid and the next due date recalculates.'
            );
            if (!ok) return;

            btn.disabled = true;
            const fd = new FormData();
            fd.append('action', 'void_payment');
            fd.append('lease_id', config.leaseId);
            fd.append('payment_id', btn.dataset.paymentId);

            try {
                const data = await postForm(fd);
                if (data.success) {
                    flashAndReload(data.message, 'success');
                    return;
                }
                setAlert(data.message || 'Could not remove that payment.', 'danger');
            } catch (err) {
                setAlert('Network error. Nothing was removed.', 'danger');
            }

            btn.disabled = false;
        });
    });
})();
