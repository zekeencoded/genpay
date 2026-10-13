(function () {
    const config = window.leaseApiConfig || {};
    const endpoint = config.endpoint;
    if (!endpoint) return;

    const money = (value) => new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(Number(value || 0));

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const dayLabel = (date) => {
        if (!date) return '—';
        const d = new Date(date + 'T00:00:00');
        return Number.isNaN(d.getTime())
            ? date
            : d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    };

    // Local calendar date. toISOString() converts to UTC first, which in UTC+8
    // hands back yesterday for any time before 08:00.
    const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    const getJson = async (params) => {
        const url = `${endpoint}?${new URLSearchParams(params).toString()}`;
        const response = await fetch(url);
        return response.json();
    };

    const postForm = async (formData) => {
        const response = await fetch(endpoint, { method: 'POST', body: formData });
        return response.json();
    };

    /* ── "How this works" panel remembers being collapsed ───────────────── */
    const howtoToggle = document.getElementById('leaseHowtoToggle');
    const howtoBody = document.getElementById('leaseHowtoBody');
    if (howtoToggle && howtoBody) {
        let collapsed = false;
        try {
            collapsed = localStorage.getItem('gpLeaseHowto') === 'collapsed';
        } catch (err) { /* private browsing — just show it */ }

        const applyHowto = () => {
            howtoBody.classList.toggle('is-collapsed', collapsed);
            howtoToggle.setAttribute('aria-expanded', String(!collapsed));
            howtoToggle.classList.toggle('is-collapsed', collapsed);
        };
        applyHowto();

        howtoToggle.addEventListener('click', () => {
            collapsed = !collapsed;
            applyHowto();
            try {
                localStorage.setItem('gpLeaseHowto', collapsed ? 'collapsed' : 'open');
            } catch (err) { /* nothing to remember with */ }
        });
    }

    /* ── New Lease modal ───────────────────────────────────────────────── */
    let merchantsLoaded = false;
    const leaseModalEl = document.getElementById('leaseModal');
    const leaseModal = leaseModalEl ? new bootstrap.Modal(leaseModalEl) : null;

    async function loadMerchantPicker() {
        const select = document.getElementById('merchantUserId');
        const hint = document.getElementById('merchantPickerHint');
        if (!select) return;

        select.innerHTML = '<option value="">Loading merchants…</option>';
        const data = await getJson({ action: 'list_merchants' });

        if (!data.success || !data.merchants || !data.merchants.length) {
            select.innerHTML = '<option value="">No registered merchants found</option>';
            hint.textContent = 'A merchant account has to exist before it can be given a lease.';
            return;
        }

        select.innerHTML = '<option value="">Select a merchant…</option>' + data.merchants.map((m) => `
            <option value="${m.merchant_user_id}" data-stall-name="${escapeHtml(m.stall_name)}" data-stall-id="${escapeHtml(m.stall_id || '')}" data-active="${m.has_active_lease ? 1 : 0}">
                ${escapeHtml(m.proprietor_name)} — ${escapeHtml(m.stall_name)}${m.has_active_lease ? ' (already leasing)' : ''}
            </option>
        `).join('');
        merchantsLoaded = true;

        select.addEventListener('change', () => {
            const opt = select.options[select.selectedIndex];
            const stallNameField = document.getElementById('stallName');
            if (opt && opt.dataset.stallName && !stallNameField.value) {
                stallNameField.value = opt.dataset.stallName;
            }
            // Default the stall to the tenant's own registry stall.
            const stallIdField = document.getElementById('stallId');
            if (opt && opt.dataset.stallId && stallIdField && !stallIdField.value) {
                stallIdField.value = opt.dataset.stallId;
            }
            hint.textContent = opt && opt.dataset.active === '1'
                ? 'Heads up: this merchant already has an active lease. This creates a second one — it does not replace it.'
                : 'The merchant account that will be billed.';
            updateLeasePreview();
        });
    }

    const startField = document.getElementById('leaseStart');
    const endField = document.getElementById('leaseEnd');
    const rentField = document.getElementById('monthlyRent');
    const previewBox = document.getElementById('leasePreview');

    function addYear(iso) {
        const d = new Date(iso + 'T00:00:00');
        if (Number.isNaN(d.getTime())) return '';
        d.setFullYear(d.getFullYear() + 1);
        return isoDate(d);
    }

    function updateLeasePreview() {
        if (!previewBox) return;
        const start = startField?.value;
        const end = endField?.value;
        const rent = Number(rentField?.value || 0);

        if (!start || !end || !(rent > 0) || end <= start) {
            previewBox.innerHTML = '';
            previewBox.classList.remove('is-visible');
            return;
        }

        const s = new Date(start + 'T00:00:00');
        const e = new Date(end + 'T00:00:00');
        let months = (e.getFullYear() - s.getFullYear()) * 12 + (e.getMonth() - s.getMonth());
        if (e.getDate() <= s.getDate()) months -= 1;
        months = Math.max(1, months + 1);

        previewBox.innerHTML = `
            <i class="fa-solid fa-calendar-check"></i>
            <span>
                This lease will bill <strong>${months} month${months === 1 ? '' : 's'}</strong> of rent
                at ${money(rent)}, the first on <strong>${dayLabel(start)}</strong> and then on
                day ${s.getDate()} of each month — <strong>${money(months * rent)}</strong> over the term.
            </span>`;
        previewBox.classList.add('is-visible');
    }

    startField?.addEventListener('change', () => {
        if (startField.value && !endField.value) {
            endField.value = addYear(startField.value);
        }
        updateLeasePreview();
    });
    endField?.addEventListener('change', updateLeasePreview);
    rentField?.addEventListener('input', updateLeasePreview);

    window.openNewLeaseModal = function (presetMerchantId, presetStallName) {
        const form = document.getElementById('leaseForm');
        form.reset();
        document.getElementById('leaseFormMsg').innerHTML = '';
        previewBox.innerHTML = '';
        previewBox.classList.remove('is-visible');

        const btn = document.getElementById('leaseSubmitBtn');
        btn.disabled = false;
        btn.textContent = 'Create lease';

        const applyPreset = () => {
            if (!presetMerchantId) return;
            const select = document.getElementById('merchantUserId');
            select.value = String(presetMerchantId);
            if (presetStallName) document.getElementById('stallName').value = presetStallName;
            const opt = select.options[select.selectedIndex];
            const stallIdField = document.getElementById('stallId');
            if (opt && opt.dataset.stallId && stallIdField) stallIdField.value = opt.dataset.stallId;
        };

        if (!merchantsLoaded) {
            loadMerchantPicker().then(applyPreset);
        } else {
            applyPreset();
        }

        leaseModal.show();
    };

    document.querySelectorAll('.js-new-lease-for').forEach((btn) => {
        btn.addEventListener('click', () => {
            window.openNewLeaseModal(btn.dataset.merchantId, btn.dataset.stallName);
        });
    });

    document.getElementById('leaseForm')?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = document.getElementById('leaseSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'Saving…';
        const msg = document.getElementById('leaseFormMsg');
        try {
            const data = await postForm(new FormData(this));
            if (data.success) {
                msg.innerHTML = `<div class="alert alert-success">${escapeHtml(data.message)} Refreshing…</div>`;
                setTimeout(() => location.reload(), 1200);
            } else {
                msg.innerHTML = `<div class="alert alert-danger">${escapeHtml(data.message || 'Unknown error.')}</div>`;
                btn.disabled = false;
                btn.textContent = 'Create lease';
            }
        } catch (err) {
            msg.innerHTML = '<div class="alert alert-danger">Network error. Please try again.</div>';
            btn.disabled = false;
            btn.textContent = 'Create lease';
        }
    });

})();
