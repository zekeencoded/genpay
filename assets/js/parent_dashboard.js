/* Shared by the parent dashboard and its sub-pages (students, alerts, link student).
   Each page defines ALERTS_API, CONTROLS_API and LINK_API before loading this file.
   Handlers attach only where their elements exist, so every page can load the whole file. */

function toggleParentSidebar() {
    document.getElementById('parentSidebar').classList.toggle('collapsed');
}

/* ── Link student ───────────────────────────────────────────── */
document.getElementById('linkForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const sid = document.getElementById('linkSchoolId').value.trim();
    const msg = document.getElementById('linkMsg');
    const btn = this.querySelector('[type="submit"]');
    if (btn.disabled) return;
    if (!sid) { msg.innerHTML = '<span style="color:var(--gp-danger)">Please enter a school ID.</span>'; return; }
    btn.disabled = true;
    msg.textContent = '';
    let linked = false;
    try {
        gpBusy(btn, 'Checking student...');
        msg.textContent = 'Checking student...';
        const lookupRes = await fetch(LINK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'lookup_student', school_id: sid})
        });
        const student = await lookupRes.json();
        if (!student.success) {
            msg.innerHTML = '<span style="color:var(--gp-danger)">' + gpEsc(student.error || 'Student lookup failed.') + '</span>';
            return;
        }
        msg.textContent = '';
        const confirmed = await gpConfirm({
            title: 'Link this student?',
            body: 'Please check the name and school ID before linking this student to your account.',
            detail: [['Student Name', student.student_name], ['Student School ID', student.school_id]],
            note: 'The student will be notified and must approve the link from their GenPay profile. Once approved you can view their wallet and transactions, send allowances, and manage spending controls.',
            confirmLabel: 'Send Request'
        });
        if (!confirmed) {
            document.getElementById('linkSchoolId').focus();
            return;
        }
        btn.textContent = 'Sending...';
        msg.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Sending request...';
        const res = await fetch(LINK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'link_student', school_id: student.school_id})
        });
        const data = await res.json();
        if (data.success) {
            linked = true;
            msg.innerHTML = '<span style="color:var(--gp-success)"><i class="fa-solid fa-check me-1"></i>' + gpEsc(data.message) + '</span>';
            // The link page sends the parent back to the student list; the dashboard reloads in place.
            const next = this.dataset.next;
            setTimeout(() => { next ? (location.href = next) : location.reload(); }, 1000);
        } else {
            msg.innerHTML = '<span style="color:var(--gp-danger)">' + gpEsc(data.error || 'Failed.') + '</span>';
        }
    } catch(err) {
        msg.innerHTML = '<span style="color:var(--gp-danger)">Request failed.</span>';
    } finally {
        if (!linked) {
            gpBusyDone(btn);
            btn.disabled = false;
        }
    }
});

/* ── Alert threshold ────────────────────────────────────────── */
document.getElementById('thresholdForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const val  = parseFloat(document.getElementById('thresholdInput').value) || 0;
    const msg  = document.getElementById('thresholdMsg');
    try {
        const res  = await fetch(CONTROLS_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'set_alert_threshold', amount: val})
        });
        const data = await res.json();
        msg.textContent  = data.success ? 'Saved!' : (data.error || 'Error');
        msg.style.color  = data.success ? 'var(--gp-success)' : 'var(--gp-danger)';
        setTimeout(() => { msg.textContent = ''; }, 2500);
    } catch(err) { msg.textContent = 'Error'; }
});

/* ── AJAX Alerts ────────────────────────────────────────────── */
function fmtDate(str) {
    const d = new Date(str.replace(' ', 'T'));
    return d.toLocaleDateString('en-PH', {month:'short', day:'numeric', year:'numeric'})
        + ' ' + d.toLocaleTimeString('en-PH', {hour:'numeric', minute:'2-digit'});
}

let _prevUnread = 0;

function showToast(msg) {
    let t = document.getElementById('alertToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'alertToast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:var(--gp-green-950);color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.2);opacity:0;transition:opacity .25s;max-width:300px;';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.opacity = '0'; }, 4000);
}

function renderAlerts(data) {
    const badge     = document.getElementById('alertBadge');
    const markBtn   = document.getElementById('markReadBtn');
    const container = document.getElementById('alertsContainer');
    const ts        = document.getElementById('alertsTimestamp');

    const count = parseInt(data.unread_count) || 0;

    // The phone tile on the dashboard; absent on the alerts page.
    const quickCount = document.getElementById('alertQuickCount');
    if (quickCount) {
        quickCount.textContent = count;
        quickCount.hidden = count === 0;
    }

    if (count > 0) {
        badge.textContent   = count;
        badge.style.display = 'inline';
        markBtn.style.display = 'inline';
        if (count > _prevUnread && _prevUnread !== -1) {
            const diff = count - _prevUnread;
            showToast(`${diff} new low-balance alert${diff > 1 ? 's' : ''}`);
        }
    } else {
        badge.style.display   = 'none';
        markBtn.style.display = 'none';
    }
    _prevUnread = count;

    const now = new Date();
    ts.textContent = 'Updated ' + now.toLocaleTimeString('en-PH', {hour:'numeric', minute:'2-digit', second:'2-digit'});

    if (!data.alerts || data.alerts.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:24px 16px;color:var(--gp-muted);">
                <i class="fa-solid fa-bell-slash" style="font-size:28px;margin-bottom:8px;display:block;"></i>
                <span style="font-size:13px;">No alerts yet. Alerts appear when a linked student's balance drops below your threshold.</span>
            </div>`;
        return;
    }

    container.innerHTML = data.alerts.map(a => {
        const name   = ((a.first_name || '') + ' ' + (a.last_name || '')).trim();
        const unread = parseInt(a.is_read) === 0;
        return `
        <div class="alert-row" style="${unread ? 'background:var(--gp-warning-bg);border-radius:8px;padding:10px 12px;margin-bottom:4px;' : ''}">
            <div class="alert-icon${unread ? '' : ' read'}">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="alert-text">
                <strong>${name}'s balance dropped to &#8369;${parseFloat(a.balance_at_alert).toFixed(2)}</strong>
                <small>${fmtDate(a.created_at)} &bull; Threshold: &#8369;${parseFloat(a.threshold).toFixed(2)}
                    ${unread ? '<span style="margin-left:6px;background:var(--gp-warning-bg);color:var(--gp-warning);padding:1px 6px;border-radius:8px;font-size:11px;font-weight:700;">NEW</span>' : ''}
                </small>
            </div>
        </div>`;
    }).join('');
}

function setRefreshSpinning(on) {
    const icon = document.getElementById('refreshIcon');
    if (!icon) return;
    if (on) icon.classList.add('fa-spin');
    else    icon.classList.remove('fa-spin');
}

async function fetchAlerts() {
    setRefreshSpinning(true);
    try {
        const res = await fetch(ALERTS_API, {credentials: 'same-origin'});
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.success) {
            renderAlerts(data);
        } else {
            document.getElementById('alertsContainer').innerHTML =
                `<div style="text-align:center;padding:20px;color:var(--gp-danger);font-size:13px;"><i class="fa-solid fa-circle-xmark me-1"></i>${data.error || 'Could not load alerts.'}</div>`;
        }
    } catch(err) {
        const container = document.getElementById('alertsContainer');
        if (container.querySelector('.fa-spinner')) {
            container.innerHTML =
                `<div style="text-align:center;padding:20px;color:var(--gp-muted);font-size:13px;"><i class="fa-solid fa-wifi me-1"></i>Could not reach alerts service. Will retry shortly.</div>`;
        }
    } finally {
        setRefreshSpinning(false);
    }
}

async function markAlertsRead() {
    try {
        const res = await fetch(ALERTS_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({action: 'mark_read'})
        });
        const data = await res.json();
        if (data.success) {
            _prevUnread = -1;
            fetchAlerts();
        }
    } catch(err) {}
}

/* Load on page open, then poll every 30 s (only on pages that show alerts or the tile) */
if (document.getElementById('alertsContainer')) {
    document.addEventListener('DOMContentLoaded', () => {
        _prevUnread = -1;
        fetchAlerts();
        setInterval(fetchAlerts, 30000);
    });
}
