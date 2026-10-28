<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

// A backup holds every wallet, password hash and uploaded permit — super-admin only, like the audit log.
gjc_require_role(['finance']);
if (gjc_sub_role() !== 'super_admin') {
    http_response_code(403);
    $currentUser = gjc_current_user($db);
    require __DIR__ . '/../includes/partials/audit_forbidden.php';
    exit;
}

$currentUser = gjc_current_user($db);
$currentPage = 'backup';
$backups = gjc_backup_list();

$lastAuto = null;
foreach ($backups as $b) {
    if ($b['label'] === 'auto') { $lastAuto = $b; break; }
}
$autoStale = $lastAuto === null || (time() - strtotime($lastAuto['created_at'])) > 36 * 3600;

function backup_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function backup_size(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format($bytes / 1024) . ' KB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup &amp; Restore | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
    <style>
        .bk-panel { background: #fff; border-radius: 16px; padding: 22px 24px; box-shadow: var(--gjc-shadow-sm, 0 1px 4px rgba(0,0,0,.06)); margin-bottom: 20px; }
        .bk-panel h3 { font-size: 16px; font-weight: 700; color: #111; margin: 0 0 4px; }
        .bk-sub { font-size: 13px; color: var(--gp-muted); margin: 0 0 16px; }
        .bk-head { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; align-items: flex-start; }
        .bk-btn { border: none; border-radius: 10px; padding: 10px 20px; font-weight: 600; font-size: 14px; cursor: pointer; }
        .bk-btn.primary { background: var(--gp-success, #16a34a); color: #fff; }
        .bk-btn.ghost { background: var(--gjc-soft-2); color: var(--gjc-slate); }
        .bk-btn.danger { background: var(--gp-danger, #dc2626); color: #fff; }
        .bk-btn.sm { padding: 6px 10px; font-size: 12px; border-radius: 8px; }
        .bk-btn:disabled { opacity: .6; cursor: not-allowed; }
        .bk-actions { display: flex; flex-wrap: wrap; gap: 6px; }
        .bk-badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 50px; background: var(--gjc-soft-2); color: var(--gjc-slate); }
        .bk-badge.auto { background: var(--gp-success-bg, #ecfdf3); color: var(--gp-success, #16a34a); }
        .bk-badge.pre-restore { background: var(--gp-warning-bg); color: var(--gp-warning); }
        .bk-banner { display: flex; gap: 12px; align-items: flex-start; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; }
        .bk-banner.warn { background: var(--gp-warning-bg); color: var(--gp-warning); }
        .bk-banner.ok { background: var(--gp-success-bg, #ecfdf3); color: var(--gp-success, #16a34a); }
        .bk-msg { font-size: 13px; margin-top: 10px; min-height: 18px; }
        .bk-msg.ok { color: var(--gp-success, #16a34a); }
        .bk-msg.err { color: var(--gp-danger, #dc2626); }
        .bk-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .bk-field label { font-size: 12px; font-weight: 600; color: var(--gjc-slate); }
        .bk-field input { border: 1.5px solid var(--gp-line); border-radius: 10px; padding: 9px 12px; font-size: 14px; }
        .bk-name { font-family: ui-monospace, Consolas, monospace; font-size: 12px; word-break: break-all; }
        .bk-checks li { margin-bottom: 4px; }
    </style>
</head>
<body class="gp-theme">
    <div class="admin-layout">
        <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

        <main class="admin-main">
            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div>
                    <h1>Backup &amp; Restore</h1>
                    <p>Full copies of the database, a readable PDF of every table, and all uploaded documents.</p>
                </div>
                <div class="admin-user">
                    <span><?= backup_e($currentUser['name'] ?? 'Admin') ?></span>
                    <?php require __DIR__ . '/../includes/partials/avatar_admin.php'; ?>
                </div>
            </header>

            <?php if ($autoStale): ?>
            <div class="bk-banner warn" role="status">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>No automatic backup in the last 36 hours.</strong>
                    <?= $lastAuto ? 'Last one: ' . backup_e(date('M j, Y g:i A', strtotime($lastAuto['created_at']))) . '.' : 'None has run yet.' ?>
                    Check the "GenPay nightly backup" task in Windows Task Scheduler (see <code>database/backup_cron.php</code>).
                </div>
            </div>
            <?php else: ?>
            <div class="bk-banner ok" role="status">
                <i class="fa-solid fa-circle-check"></i>
                <div>Last automatic backup: <?= backup_e(date('M j, Y g:i A', strtotime($lastAuto['created_at']))) ?>.</div>
            </div>
            <?php endif; ?>

            <section class="bk-panel">
                <div class="bk-head">
                    <div>
                        <h3>Backups</h3>
                        <p class="bk-sub">Automatic backups run nightly; the newest <?= GJC_BACKUP_KEEP_AUTO ?> are kept. Manual and pre-restore backups are kept until you delete them.</p>
                    </div>
                    <button type="button" class="bk-btn primary" id="createBtn"><i class="fa-solid fa-plus"></i> Create backup now</button>
                </div>
                <div class="bk-msg" id="createMsg" aria-live="polite"></div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr><th>Created</th><th>Type</th><th>By</th><th>Contents</th><th>Size</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php if (!$backups): ?>
                            <tr><td colspan="6" class="text-center py-3">No backups yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($backups as $b): ?>
                            <tr>
                                <td>
                                    <?= backup_e(date('M j, Y g:i A', strtotime($b['created_at']))) ?>
                                    <div class="bk-name"><?= backup_e($b['name']) ?></div>
                                </td>
                                <td><span class="bk-badge <?= backup_e($b['label']) ?>"><?= backup_e($b['label']) ?></span></td>
                                <td><?= backup_e($b['created_by'] !== '' ? $b['created_by'] : 'System') ?></td>
                                <td>
                                    <?= $b['tables'] ?> tables, <?= number_format($b['rows']) ?> rows, <?= number_format($b['files']) ?> files
                                    <?php if (str_starts_with($b['pdf'], 'failed')): ?>
                                        <div class="bk-msg err" style="margin:0">PDF report failed</div>
                                    <?php endif; ?>
                                </td>
                                <td><?= backup_size($b['size']) ?></td>
                                <td>
                                    <div class="bk-actions">
                                        <a class="bk-btn ghost sm" href="<?= ADMIN_URL ?>/api/backup.php?action=download&amp;name=<?= urlencode($b['name']) ?>"><i class="fa-solid fa-download"></i> Zip</a>
                                        <?php if ($b['pdf'] === 'ok'): ?>
                                        <a class="bk-btn ghost sm" href="<?= ADMIN_URL ?>/api/backup.php?action=download&amp;part=pdf&amp;name=<?= urlencode($b['name']) ?>"><i class="fa-solid fa-file-pdf"></i> PDF</a>
                                        <?php endif; ?>
                                        <?php if ($b['valid']): ?>
                                        <button type="button" class="bk-btn danger sm" data-restore="<?= backup_e($b['name']) ?>"><i class="fa-solid fa-clock-rotate-left"></i> Restore</button>
                                        <?php endif; ?>
                                        <button type="button" class="bk-btn ghost sm" data-delete="<?= backup_e($b['name']) ?>" aria-label="Delete backup"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="bk-sub" style="margin:8px 0 0">To restore a backup from another computer, copy its zip into the <code>backups/</code> folder and reload this page.</p>
            </section>
        </main>
    </div>

    <div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true" aria-labelledby="restoreTitle">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header">
                    <h5 class="modal-title" id="restoreTitle">Restore backup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:14px;">
                    <div id="restoreForm">
                        <p>This replaces <strong>all</strong> data and uploaded documents with the copy in
                            <span class="bk-name" id="restoreName"></span>.
                            Everything since then (payments, top-ups, new accounts) will be lost.
                            The site is unavailable to everyone while it runs. A safety backup of the current state is taken first.</p>
                        <div class="bk-field">
                            <label for="restorePassword">Your password</label>
                            <input type="password" id="restorePassword" autocomplete="current-password">
                        </div>
                        <div class="bk-field">
                            <label for="restoreConfirm">Type RESTORE to confirm</label>
                            <input type="text" id="restoreConfirm" autocomplete="off">
                        </div>
                    </div>
                    <div class="bk-msg" id="restoreMsg" aria-live="polite"></div>
                    <ul class="bk-checks" id="restoreChecks" hidden></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="bk-btn ghost" data-bs-dismiss="modal" id="restoreCancel">Cancel</button>
                    <button type="button" class="bk-btn danger" id="restoreGo">Restore</button>
                </div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/../includes/partials/csrf_bootstrap.php'; ?>
    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }

    const API = <?= json_encode(ADMIN_URL . '/api/backup.php') ?>;

    async function post(data) {
        const res = await fetch(API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        let json;
        try { json = await res.json(); } catch (e) { json = { success: false, error: 'Unexpected server response (' + res.status + ').' }; }
        return json;
    }

    function setMsg(el, text, ok) {
        el.textContent = text;
        el.className = 'bk-msg ' + (ok ? 'ok' : 'err');
    }

    document.getElementById('createBtn').addEventListener('click', async (e) => {
        const btn = e.currentTarget, msg = document.getElementById('createMsg');
        btn.disabled = true;
        msg.className = 'bk-msg';
        msg.textContent = 'Creating backup… this can take a minute.';
        const r = await post({ action: 'create' });
        if (r.success) {
            setMsg(msg, 'Backup created: ' + r.name + (r.pdf === 'ok' ? '' : ' (PDF report: ' + r.pdf + ')'), r.pdf === 'ok');
            setTimeout(() => location.reload(), 800);
        } else {
            setMsg(msg, r.error || 'Backup failed.', false);
            btn.disabled = false;
        }
    });

    document.querySelectorAll('[data-delete]').forEach(btn => btn.addEventListener('click', async () => {
        const name = btn.dataset.delete;
        btn.disabled = true;
        // Two-click confirm instead of a browser dialog.
        if (btn.dataset.armed !== '1') {
            btn.dataset.armed = '1';
            btn.innerHTML = 'Delete?';
            btn.disabled = false;
            return;
        }
        const r = await post({ action: 'delete', name });
        if (r.success) location.reload();
        else { setMsg(document.getElementById('createMsg'), r.error || 'Delete failed.', false); btn.disabled = false; }
    }));

    const modal = new bootstrap.Modal(document.getElementById('restoreModal'));
    let restoreTarget = '';
    document.querySelectorAll('[data-restore]').forEach(btn => btn.addEventListener('click', () => {
        restoreTarget = btn.dataset.restore;
        document.getElementById('restoreName').textContent = restoreTarget;
        document.getElementById('restorePassword').value = '';
        document.getElementById('restoreConfirm').value = '';
        document.getElementById('restoreMsg').textContent = '';
        document.getElementById('restoreChecks').hidden = true;
        document.getElementById('restoreForm').hidden = false;
        document.getElementById('restoreGo').hidden = false;
        document.getElementById('restoreGo').disabled = false;
        modal.show();
    }));

    document.getElementById('restoreGo').addEventListener('click', async (e) => {
        const btn = e.currentTarget, msg = document.getElementById('restoreMsg');
        if (document.getElementById('restoreConfirm').value !== 'RESTORE') {
            setMsg(msg, 'Type RESTORE to confirm.', false);
            return;
        }
        btn.disabled = true;
        document.getElementById('restoreCancel').disabled = true;
        msg.className = 'bk-msg';
        msg.textContent = 'Restoring… do not close this page.';
        const r = await post({
            action: 'restore',
            name: restoreTarget,
            password: document.getElementById('restorePassword').value,
            confirm: document.getElementById('restoreConfirm').value,
        });
        document.getElementById('restoreCancel').disabled = false;
        if (!r.success) {
            setMsg(msg, r.error || 'Restore failed.', false);
            btn.disabled = false;
            return;
        }
        const c = r.checks, list = document.getElementById('restoreChecks');
        const allOk = c.row_counts_ok && c.circulation_ok && c.audit_chain_ok !== false;
        setMsg(msg, allOk ? 'Restore complete. All checks passed.' : 'Restore finished, but some checks failed. Review below; the safety backup ' + r.safety_backup + ' holds the previous state.', allOk);
        list.innerHTML = '';
        [
            [c.row_counts_ok, 'Row counts match the backup' + (c.row_mismatch.length ? ' (mismatch: ' + c.row_mismatch.join(', ') + ')' : '')],
            [c.circulation_ok, 'GenCoin circulation balanced (drift ₱' + c.drift + ')'],
            [c.audit_chain_ok !== false, 'Audit trail hash chain intact' + (c.audit_chain_reason ? ' (' + c.audit_chain_reason + ')' : '')],
        ].forEach(([ok, text]) => {
            const li = document.createElement('li');
            li.textContent = (ok ? '✔ ' : '✖ ') + text;
            li.style.color = ok ? 'var(--gp-success, #16a34a)' : 'var(--gp-danger, #dc2626)';
            list.appendChild(li);
        });
        list.hidden = false;
        document.getElementById('restoreForm').hidden = true;
        btn.hidden = true;
        document.getElementById('restoreCancel').textContent = 'Close';
        document.getElementById('restoreModal').addEventListener('hidden.bs.modal', () => location.reload(), { once: true });
    });
    </script>
</body>
</html>
