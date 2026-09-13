<?php
session_start();
require_once __DIR__ . "/../connection/config.php";
require_once __DIR__ . "/../connection/pdo.php";
require_once __DIR__ . "/../connection/app.php";

gjc_require_role(["merchant"]);
if (
    !gjc_is_merchant_admin() &&
    (gjc_current_role() !== "merchant" || gjc_is_merchant_staff())
) {
    header("Location: " . DASHBOARD_URL);
    exit();
}

$currentUser = gjc_current_user($db);
$merchantUserId = $currentUser["id"];

// Fetch staff accounts created by this merchant admin
$staffList = [];
gjc_ensure_staff_position_schema($db);

$stmt = $db->prepare(
    "SELECT userID, first_name, middle_name, last_name, suffix, position, email, contact_number, sub_role, created_at, status
       FROM users
      WHERE merchant_owner_id = ? AND roleID = 6
      ORDER BY created_at DESC",
);
$stmt->execute([$merchantUserId]);
$staffList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentPage = "staff";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=54">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=30">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="gp-theme">
<div class="merchant-layout">
    <?php require __DIR__ .
        "/../includes/partials/" .
        (gjc_is_merchant_staff()
            ? "sidebar_merchant_staff.php"
            : "sidebar_merchant_admin.php"); ?>

    <main class="merchant-main">
        <?php
        $topbarTitle = 'Staff Management';
        $topbarSubtitle = 'Create and manage staff accounts for your stall.';
        require __DIR__ . '/../includes/partials/topbar_merchant.php';
        ?>

        <section class="merchant-premium-panel">
            <div class="merchant-panel-header d-flex justify-content-between align-items-center">
                <div>
                    <h3>Staff Accounts</h3>
                    <p><?= count($staffList) ?> staff member(s) registered.</p>
                </div>
                <button class="merchant-view-btn" data-bs-toggle="modal" data-bs-target="#staffModal">+ Add Staff</button>
            </div>

            <!-- Status Filter -->
            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-sm btn-success active" id="filter-Active" onclick="setStatusFilter('Active')">Active Only</button>
                <button class="btn btn-sm btn-outline-secondary" id="filter-Inactive" onclick="setStatusFilter('Inactive')">Inactive</button>
                <button class="btn btn-sm btn-outline-secondary" id="filter-All" onclick="setStatusFilter('All')">Show All</button>
            </div>

            <div class="table-responsive">
                <table class="table merchant-premium-table align-middle" id="staffTable">
                    <thead>
                        <tr>
                            <th data-card="title">Name</th>
                            <th data-card="hide">Email</th>
                            <th data-card="hide">Contact</th>
                            <th>Position</th>
                            <th data-card="hide">Date Hired</th>
                            <th style="cursor:pointer" onclick="sortByStatus()" title="Sort by status">
                                Status <i class="fa-solid fa-sort ms-1" id="statusSortIcon"></i>
                            </th>
                            <th>Active</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody id="staffTableBody">
                    <?php if (empty($staffList)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-5">No staff accounts yet. Add your first staff member.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($staffList as $s): ?>
                        <?php
                        $isActive = ($s['status'] ?? 'Active') === 'Active';
                        $fullName = implode(" ", array_filter([
                            $s["first_name"],
                            $s["middle_name"] ?? '',
                            $s["last_name"],
                            $s["suffix"] ?? '',
                        ], fn($part) => trim((string) $part) !== ''));
                        ?>
                        <tr data-status="<?= $isActive ? 'Active' : 'Inactive' ?>">
                            <td><strong><?= gjc_e($fullName) ?></strong></td>
                            <td><?= gjc_e($s["email"]) ?></td>
                            <td><?= gjc_e($s["contact_number"]) ?></td>
                            <td><span class="merchant-type-pill"><?= gjc_e($s["position"] !== null && trim((string) $s["position"]) !== '' ? $s["position"] : 'Merchant Staff') ?></span></td>
                            <td><?= date("M d, Y", strtotime($s["created_at"])) ?></td>
                            <td>
                                <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?> status-badge">
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                        <?= $isActive ? 'checked' : '' ?>
                                        onchange="toggleStaffStatus(<?= (int) $s['userID'] ?>, this)">
                                </div>
                            </td>
                            <td class="text-end">
                                <!-- Read view: signed HMAC token, re-checked in view_staff.php
                                     against this owner's roster before rendering. -->
                                <a class="merchant-row-btn merchant-row-btn--view"
                                    href="<?= MERCHANT_URL ?>/view_staff.php?token=<?= urlencode(gjc_make_view_token((int) $s['userID'], 'merchant_staff')) ?>">
                                    <i class="fa-regular fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- Add Staff Modal -->
<div class="modal fade" id="staffModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered staff-modal-dialog">
        <div class="modal-content custom-modal staff-modal">
            <div class="modal-header staff-modal-header">
                <div>
                    <h5 class="modal-title">Create Staff Account</h5>
                    <p class="staff-modal-subtitle">Give a team member their own POS login for your stall.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <form id="staffForm" class="staff-form-grid">
                    <input type="hidden" name="action" value="create_staff">

                    <aside class="staff-id-rail">
                        <div class="staff-avatar" id="staffAvatarPreview">?</div>
                        <div class="staff-id-name" id="staffNamePreview">New staff member</div>
                        <div class="staff-id-position" id="staffPositionPreview">Position not set</div>
                        <ul class="staff-id-perks">
                            <li><i class="fa-solid fa-cash-register"></i> Can run POS sales for your stall</li>
                            <li><i class="fa-solid fa-right-to-bracket"></i> Signs in with their own email</li>
                            <li><i class="fa-solid fa-power-off"></i> You can deactivate anytime</li>
                        </ul>
                    </aside>

                    <div class="staff-fields">
                        <div class="staff-section">
                            <div class="staff-section-title"><i class="fa-solid fa-id-card"></i>Full legal name</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">First Name *</label>
                                    <input type="text" class="form-control staff-name-input" name="first_name" placeholder="Juan" maxlength="60" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Middle Name</label>
                                    <input type="text" class="form-control staff-name-input" name="middle_name" placeholder="Santos" maxlength="60">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Last Name *</label>
                                    <input type="text" class="form-control staff-name-input" name="last_name" placeholder="Dela Cruz" maxlength="60" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Suffix</label>
                                    <input type="text" class="form-control" name="suffix" placeholder="Jr., Sr., III" maxlength="20">
                                </div>
                            </div>
                        </div>

                        <div class="staff-section">
                            <div class="staff-section-title"><i class="fa-solid fa-briefcase"></i>Role &amp; contact</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Position</label>
                                    <input type="text" class="form-control staff-name-input" name="position" id="staffPositionInput" placeholder="e.g. Cashier" list="staffPositionOptions" maxlength="60">
                                    <datalist id="staffPositionOptions">
                                        <option value="Cashier">
                                        <option value="Cook">
                                        <option value="Kitchen Helper">
                                        <option value="Inventory Clerk">
                                        <option value="Server">
                                    </datalist>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Contact Number</label>
                                    <input type="text" class="form-control" name="contact_number" placeholder="09XXXXXXXXX" maxlength="20">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Email *</label>
                                    <input type="email" class="form-control" name="email" required>
                                </div>
                            </div>
                        </div>

                        <div class="staff-section">
                            <div class="staff-section-title"><i class="fa-solid fa-lock"></i>Account access</div>
                            <label class="form-label fw-semibold">Temporary Password *</label>
                            <div class="staff-password-row">
                                <div class="staff-password-wrap">
                                    <input type="password" class="form-control" name="password" id="staffPasswordInput" required minlength="6">
                                    <button type="button" class="staff-pw-toggle" id="staffPwToggle" aria-label="Show password" title="Show password"><i class="fa-solid fa-eye"></i></button>
                                </div>
                                <button type="button" class="btn btn-outline-secondary staff-pw-generate" id="staffPwGenerate"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
                            </div>
                            <div class="staff-pw-hint" id="staffPwHint">At least 6 characters. Share it with them directly — this field isn't emailed automatically.</div>
                        </div>

                        <div id="staffMsg"></div>
                        <div class="staff-form-actions">
                            <button type="submit" class="login-btn" id="staffSubmitBtn">Create Account</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.staff-modal-dialog { max-width: 760px; }
.staff-modal { overflow: hidden; }
.staff-modal-header { align-items: flex-start; border-bottom: 1px solid var(--ad-line); padding: 20px 24px 16px; }
.staff-modal-subtitle { margin: 3px 0 0; font-size: 13px; color: var(--text-muted); font-weight: 500; }

.staff-form-grid { display: flex; align-items: stretch; }

.staff-id-rail {
    flex: 0 0 210px;
    background: var(--gp-grad-shell, linear-gradient(180deg, var(--gp-green-950), var(--gp-green-900)));
    color: #fff;
    padding: 28px 18px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 12px;
}
.staff-avatar {
    width: 68px; height: 68px; border-radius: 50%;
    background: rgba(255,255,255,0.08);
    border: 2px solid var(--gp-gold);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 800; color: var(--gp-gold-light);
    letter-spacing: 0.02em;
}
.staff-id-name { font-size: 15px; font-weight: 800; line-height: 1.3; word-break: break-word; }
.staff-id-position {
    font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--gp-gold-light); opacity: 0.9;
}
.staff-id-perks { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; gap: 11px; text-align: left; width: 100%; }
.staff-id-perks li { font-size: 12px; line-height: 1.4; color: rgba(255,255,255,0.78); display: flex; gap: 8px; align-items: flex-start; }
.staff-id-perks li i { color: var(--gp-gold-light); width: 14px; margin-top: 2px; }

.staff-fields { flex: 1; min-width: 0; padding: 22px 26px 24px; max-height: 72vh; overflow-y: auto; }
.staff-section + .staff-section { margin-top: 20px; }
.staff-section-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;
    color: var(--emerald-800); margin-bottom: 12px; padding-bottom: 8px;
    border-bottom: 1px dashed var(--ad-line);
}
.staff-section-title i { color: var(--gp-gold-deep); }

.staff-password-row { display: flex; gap: 8px; }
.staff-password-wrap { position: relative; flex: 1; min-width: 0; }
.staff-password-wrap input { padding-right: 40px; }
.staff-pw-toggle {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    border: none; background: none; color: var(--text-muted); padding: 6px;
}
.staff-pw-toggle:hover { color: var(--emerald-900); }
.staff-pw-generate { white-space: nowrap; font-size: 13px; font-weight: 700; }
.staff-pw-hint { margin-top: 7px; font-size: 12px; color: var(--text-muted); }

.staff-form-actions { display: flex; gap: 8px; margin-top: 22px; }
.staff-form-actions .login-btn { flex: 1; }

@media (max-width: 650px) {
    .staff-form-grid { flex-direction: column; }
    .staff-id-rail { flex-direction: row; text-align: left; padding: 16px 20px; }
    .staff-id-name, .staff-id-position { text-align: left; }
    .staff-id-perks { display: none; }
    .staff-fields { max-height: none; }
}
</style>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
<script>
const STAFF_API = '<?= MERCHANT_URL ?>/api/staff.php';

// ── Live identity preview (avatar initials, name, position) ─────────────────
const firstInput = document.querySelector('input[name="first_name"]');
const middleInput = document.querySelector('input[name="middle_name"]');
const lastInput = document.querySelector('input[name="last_name"]');
const suffixInput = document.querySelector('input[name="suffix"]');
const positionInput = document.getElementById('staffPositionInput');
const avatarPreview = document.getElementById('staffAvatarPreview');
const namePreview = document.getElementById('staffNamePreview');
const positionPreview = document.getElementById('staffPositionPreview');

function updateStaffPreview() {
    const first = firstInput.value.trim();
    const middle = middleInput.value.trim();
    const last = lastInput.value.trim();
    const suffix = suffixInput.value.trim();
    const fullName = [first, middle, last, suffix].filter(Boolean).join(' ');

    namePreview.textContent = fullName || 'New staff member';
    avatarPreview.textContent = ((first[0] || '') + (last[0] || '')).toUpperCase() || '?';
    positionPreview.textContent = positionInput.value.trim() || 'Position not set';
}
[firstInput, middleInput, lastInput, suffixInput, positionInput].forEach(el => {
    el.addEventListener('input', updateStaffPreview);
});

// ── Show/hide password ───────────────────────────────────────────────────────
const pwInput = document.getElementById('staffPasswordInput');
const pwToggle = document.getElementById('staffPwToggle');
pwToggle.addEventListener('click', function() {
    const shown = pwInput.type === 'text';
    pwInput.type = shown ? 'password' : 'text';
    this.innerHTML = shown ? '<i class="fa-solid fa-eye"></i>' : '<i class="fa-solid fa-eye-slash"></i>';
    this.title = shown ? 'Show password' : 'Hide password';
});

// ── Generate a strong temporary password ─────────────────────────────────────
document.getElementById('staffPwGenerate').addEventListener('click', function() {
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const lower = 'abcdefghijkmnpqrstuvwxyz';
    const digits = '23456789';
    const symbols = '!@#$%*';
    const all = upper + lower + digits + symbols;
    const pick = set => set[Math.floor(Math.random() * set.length)];
    const chars = [pick(upper), pick(lower), pick(digits), pick(symbols)];
    for (let i = 0; i < 6; i++) chars.push(pick(all));
    for (let i = chars.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }
    const generated = chars.join('');

    pwInput.value = generated;
    pwInput.type = 'text';
    pwToggle.innerHTML = '<i class="fa-solid fa-eye-slash"></i>';
    pwToggle.title = 'Hide password';

    const hint = document.getElementById('staffPwHint');
    const original = hint.textContent;
    navigator.clipboard?.writeText(generated).then(() => {
        hint.textContent = 'Generated and copied to clipboard — ' + generated;
        setTimeout(() => { hint.textContent = original; }, 4000);
    }).catch(() => {
        hint.textContent = 'Generated: ' + generated;
        setTimeout(() => { hint.textContent = original; }, 4000);
    });
});

// ── Reset the form/preview each time the modal opens ─────────────────────────
document.getElementById('staffModal').addEventListener('show.bs.modal', function() {
    document.getElementById('staffForm').reset();
    document.getElementById('staffMsg').innerHTML = '';
    pwInput.type = 'password';
    pwToggle.innerHTML = '<i class="fa-solid fa-eye"></i>';
    updateStaffPreview();
});

// ── Create staff ──────────────────────────────────────────────────────────────
document.getElementById('staffForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('staffSubmitBtn');
    btn.disabled = true; btn.textContent = 'Creating...';
    const r = await fetch(STAFF_API, { method:'POST', body: new FormData(this) });
    const d = await r.json();
    const msg = document.getElementById('staffMsg');
    if (d.success) {
        msg.innerHTML = '<div class="alert alert-success">Account created! Reloading...</div>';
        setTimeout(() => location.reload(), 1500);
    } else {
        msg.innerHTML = `<div class="alert alert-danger">${d.message}</div>`;
        btn.disabled = false; btn.textContent = 'Create Account';
    }
});

// ── Toggle staff Active / Inactive ───────────────────────────────────────────
async function toggleStaffStatus(userId, checkbox) {
    checkbox.disabled = true;
    const f = new FormData();
    f.append('action', 'toggle_staff_status');
    f.append('user_id', userId);
    try {
        const r = await fetch(STAFF_API, { method:'POST', body:f });
        const d = await r.json();
        if (d.success) {
            const row = checkbox.closest('tr');
            const isActive = d.new_status === 'Active';
            row.dataset.status = d.new_status;
            const badge = row.querySelector('.status-badge');
            badge.className = 'badge status-badge ' + (isActive ? 'bg-success' : 'bg-secondary');
            badge.textContent = d.new_status;
            checkbox.checked = isActive;
            applyStatusFilter();
        } else {
            checkbox.checked = !checkbox.checked;
            alert(d.message);
        }
    } catch {
        checkbox.checked = !checkbox.checked;
        alert('Network error. Please try again.');
    }
    checkbox.disabled = false;
}

// ── Status filter ─────────────────────────────────────────────────────────────
let currentFilter = 'Active';

function setStatusFilter(value) {
    currentFilter = value;
    ['Active','Inactive','All'].forEach(v => {
        const btn = document.getElementById('filter-' + v);
        if (!btn) return;
        const isActive = v === value;
        btn.className = 'btn btn-sm ' + (isActive
            ? (v === 'Active' ? 'btn-success active' : v === 'Inactive' ? 'btn-secondary active' : 'btn-dark active')
            : 'btn-outline-secondary');
    });
    applyStatusFilter();
}

function applyStatusFilter() {
    document.querySelectorAll('#staffTableBody tr[data-status]').forEach(row => {
        const show = currentFilter === 'All' || row.dataset.status === currentFilter;
        row.style.display = show ? '' : 'none';
    });
}

// ── Sort by status ────────────────────────────────────────────────────────────
let statusSortAsc = true;

function sortByStatus() {
    const tbody = document.getElementById('staffTableBody');
    const rows = Array.from(tbody.querySelectorAll('tr[data-status]'));
    rows.sort((a, b) => {
        const av = a.dataset.status === 'Active' ? 0 : 1;
        const bv = b.dataset.status === 'Active' ? 0 : 1;
        return statusSortAsc ? av - bv : bv - av;
    });
    statusSortAsc = !statusSortAsc;
    const icon = document.getElementById('statusSortIcon');
    if (icon) icon.className = 'fa-solid ms-1 ' + (statusSortAsc ? 'fa-sort-down' : 'fa-sort-up');
    rows.forEach(row => tbody.appendChild(row));
}

// Default: show Active only on page load
document.addEventListener('DOMContentLoaded', () => setStatusFilter('Active'));
</script>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
