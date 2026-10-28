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
    "SELECT userID, first_name, middle_name, last_name, suffix, position, email, contact_number, created_at, status, profile_img
       FROM users
      WHERE merchant_owner_id = ? AND roleID = 6 AND staff_removed_at IS NULL
      ORDER BY created_at DESC",
);
$stmt->execute([$merchantUserId]);
$staffList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Per-staff remittance activity (sales they have handed in), plus roster counts
// for the summary tiles. Remittances are the only sales record tied to a staff id.
$activity = [];
try {
    gjc_ensure_sales_remittances_schema($db);
    $act = $db->prepare(
        "SELECT staff_user_id,
                COALESCE(SUM(amount), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount END), 0) AS today_amount,
                COALESCE(SUM(txn_count), 0) AS total_txns,
                MAX(created_at) AS last_at
           FROM sales_remittances
          WHERE merchant_wallet_id = (SELECT id FROM merchant_wallets WHERE user_id = ?)
          GROUP BY staff_user_id",
    );
    $act->execute([$merchantUserId]);
    foreach ($act->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $activity[(int) $row["staff_user_id"]] = $row;
    }
} catch (Throwable $ex) {
    $activity = [];
}
$totalStaff = count($staffList);
$activeStaff = count(array_filter($staffList, fn($s) => ($s["status"] ?? "Active") === "Active"));
$inactiveStaff = $totalStaff - $activeStaff;
$remittedToday = array_sum(array_map(fn($a) => (float) $a["today_amount"], $activity));
$remittedTotal = array_sum(array_map(fn($a) => (float) $a["total_amount"], $activity));

$csrfToken = gjc_csrf_token();
$e = gjc_e(...);
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
    <link rel="stylesheet" href="<?= CSS_URL ?>/merchant.css?v=63">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
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

        <section class="sd-stats mb-4">
            <div class="sd-stat">
                <div class="sd-stat-top"><span>Total Staff</span><span class="sd-stat-icon is-txns"><i class="fa-solid fa-users"></i></span></div>
                <h2 class="sd-num"><?= $totalStaff ?></h2>
                <p><?= $activeStaff ?> active &middot; <?= $inactiveStaff ?> inactive</p>
            </div>
            <div class="sd-stat">
                <div class="sd-stat-top"><span>Remitted Today</span><span class="sd-stat-icon is-spent"><i class="fa-solid fa-paper-plane"></i></span></div>
                <h2 class="sd-num"><?= gjc_gc_amount($remittedToday) ?> GC</h2>
                <p>&asymp; <?= gjc_money($remittedToday) ?> &middot; handed in today</p>
            </div>
            <div class="sd-stat">
                <div class="sd-stat-top"><span>Total Remitted</span><span class="sd-stat-icon is-status"><i class="fa-solid fa-money-bill-wave"></i></span></div>
                <h2 class="sd-num"><?= gjc_gc_amount($remittedTotal) ?> GC</h2>
                <p>&asymp; <?= gjc_money($remittedTotal) ?> &middot; all-time across your team</p>
            </div>
        </section>

        <section class="merchant-premium-panel">
            <div class="merchant-panel-header d-flex justify-content-between align-items-center">
                <div>
                    <h3>Staff Accounts</h3>
                    <p><?= count($staffList) ?> staff member(s) registered.</p>
                </div>
                <button class="merchant-view-btn" data-bs-toggle="modal" data-bs-target="#staffModal">+ Add Staff</button>
            </div>

            <!-- Status Filter -->
            <div class="staff-toolbar mb-3">
            <div class="staff-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" id="staffSearch" class="form-control" placeholder="Search name, email, position…" autocomplete="off">
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-success active" id="filter-Active" onclick="setStatusFilter('Active')">Active Only</button>
                <button class="btn btn-sm btn-outline-secondary" id="filter-Inactive" onclick="setStatusFilter('Inactive')">Inactive</button>
                <button class="btn btn-sm btn-outline-secondary" id="filter-All" onclick="setStatusFilter('All')">Show All</button>
            </div>
            </div>

            <div class="table-responsive">
                <table class="table merchant-premium-table align-middle" id="staffTable">
                    <thead>
                        <tr>
                            <th data-card="title">Name</th>
                            <th>Position</th>
                            <th data-card="hide">Date Hired</th>
                            <th data-card="hide">Remitted</th>
                            <th data-card="hide">Last Remit</th>
                            <th style="cursor:pointer" onclick="sortByStatus()" title="Sort by status">
                                Status <i class="fa-solid fa-sort ms-1" id="statusSortIcon"></i>
                            </th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody id="staffTableBody">
                    <?php if (empty($staffList)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-5">No staff accounts yet. Add your first staff member.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($staffList as $s): ?>
                        <?php
                        $isActive = ($s['status'] ?? 'Active') === 'Active';
                        $act = $activity[(int) $s['userID']] ?? null;
                        // Same path convention as view_staff.php.
                        $photo = trim((string) ($s['profile_img'] ?? ''));
                        $photoUrl = $photo !== '' ? BASE_URL . '/' . ltrim($photo, '/') : '';
                        $fullName = implode(" ", array_filter([
                            $s["first_name"],
                            $s["middle_name"] ?? '',
                            $s["last_name"],
                            $s["suffix"] ?? '',
                        ], fn($part) => trim((string) $part) !== ''));
                        ?>
                        <tr data-status="<?= $isActive ? 'Active' : 'Inactive' ?>"
                            data-search="<?= $e(strtolower($fullName . ' ' . $s['email'] . ' ' . ($s['position'] ?? '') . ' ' . ($s['contact_number'] ?? ''))) ?>">
                            <td><div class="staff-namecell"><span class="staff-mini-avatar"><?php if ($photoUrl !== ''): ?><img src="<?= $e($photoUrl) ?>" alt="" onerror="this.remove()"><?php endif; ?><?= $e(strtoupper(mb_substr((string) $s['first_name'], 0, 1) . mb_substr((string) $s['last_name'], 0, 1))) ?></span><div class="staff-name-text"><strong><?= $e($fullName) ?></strong>
                                <div class="staff-sub"><?= $e($s["email"]) ?><?= trim((string) $s["contact_number"]) !== '' ? ' &middot; ' . $e($s["contact_number"]) : '' ?></div></div></div></td>
                            <td><span class="merchant-type-pill"><?= $e($s["position"] !== null && trim((string) $s["position"]) !== '' ? $s["position"] : 'Merchant Staff') ?></span></td>
                            <td><?= date("M d, Y", strtotime($s["created_at"])) ?></td>
                            <td><strong><?= gjc_gc_amount($act['total_amount'] ?? 0) ?> GC</strong>
                                <div class="staff-sub">&asymp; <?= gjc_money($act['total_amount'] ?? 0) ?><?php if ($act): ?> &middot; <?= (int) $act['total_txns'] ?> sale(s)<?php endif; ?></div></td>
                            <td><?= $act && $act['last_at'] ? $e(date("M d, g:i A", strtotime($act['last_at']))) : '<span class="text-muted">Never</span>' ?></td>
                            <td>
                                <div class="staff-status-cell">
                                    <span class="badge <?= $isActive ? 'bg-success' : 'bg-secondary' ?> status-badge"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch" title="Activate / deactivate"
                                            <?= $isActive ? 'checked' : '' ?>
                                            onchange="toggleStaffStatus(<?= (int) $s['userID'] ?>, this)">
                                    </div>
                                </div>
                            </td>
                            <td class="text-end">
                                <div class="merchant-row-actions">
                                <!-- Read view: signed HMAC token, re-checked in view_staff.php
                                     against this owner's roster before rendering. -->
                                <a class="merchant-row-btn merchant-row-btn--view"
                                    href="<?= MERCHANT_URL ?>/view_staff.php?token=<?= urlencode(gjc_make_view_token((int) $s['userID'], 'merchant_staff')) ?>">
                                    <i class="fa-regular fa-eye"></i> View
                                </a>
                                <button type="button" class="merchant-row-btn"
                                    onclick="openManageStaff(this)"
                                    data-id="<?= (int) $s['userID'] ?>"
                                    data-first="<?= $e($s['first_name']) ?>"
                                    data-middle="<?= $e($s['middle_name'] ?? '') ?>"
                                    data-last="<?= $e($s['last_name']) ?>"
                                    data-suffix="<?= $e($s['suffix'] ?? '') ?>"
                                    data-position="<?= $e($s['position'] ?? '') ?>"
                                    data-name="<?= $e($fullName) ?>"
                                    data-email="<?= $e($s['email']) ?>"
                                    data-contact="<?= $e($s['contact_number'] ?? '') ?>"
                                    data-hired="<?= $e(date('M d, Y', strtotime($s['created_at']))) ?>"
                                    data-remitted="<?= $e(gjc_gc_amount($act['total_amount'] ?? 0) . ' GC ≈ ' . html_entity_decode(gjc_money($act['total_amount'] ?? 0), ENT_QUOTES, 'UTF-8')) ?>"
                                    data-sales="<?= (int) ($act['total_txns'] ?? 0) ?>"
                                    data-photo="<?= $e($photoUrl) ?>"
                                    data-lastremit="<?= $act && $act['last_at'] ? $e(date('M d, g:i A', strtotime($act['last_at']))) : 'Never' ?>">
                                    <i class="fa-solid fa-user-gear"></i> Manage
                                </button>
                                </div>
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
                                    <input type="password" class="form-control" name="password" id="staffPasswordInput" required minlength="8">
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

<!-- Manage Staff Modal — details, password reset, remove. Filled per row by
     openManageStaff() from the Manage button's data-* attributes. -->
<div class="modal fade" id="manageStaffModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered staff-manage-dialog">
        <div class="modal-content custom-modal staff-modal">
            <div class="modal-header staff-modal-header">
                <div>
                    <h5 class="modal-title">Manage Staff</h5>
                    <p class="staff-modal-subtitle">Review their activity, edit details, reset their password or remove them.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body staff-manage-body">
                <div class="staff-profile">
                    <div class="staff-profile-top">
                        <div class="staff-avatar staff-avatar--light" id="manageAvatar">?</div>
                        <div class="staff-profile-id">
                            <div class="staff-profile-name" id="manageStaffName">Staff</div>
                            <div class="staff-profile-meta">
                                <span class="merchant-type-pill" id="managePosition"></span>
                                <span class="badge status-badge" id="manageStatusBadge"></span>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-0" title="Activate / deactivate">
                            <input class="form-check-input" type="checkbox" role="switch" id="manageStatusSwitch">
                        </div>
                    </div>
                    <dl class="staff-profile-grid">
                        <div><dt><i class="fa-regular fa-envelope"></i> Email</dt><dd id="manageEmail"></dd></div>
                        <div><dt><i class="fa-solid fa-phone"></i> Contact</dt><dd id="manageContact"></dd></div>
                        <div><dt><i class="fa-regular fa-calendar-plus"></i> Date hired</dt><dd id="manageHired"></dd></div>
                        <div><dt><i class="fa-solid fa-paper-plane"></i> Last remit</dt><dd id="manageLastRemit"></dd></div>
                        <div><dt><i class="fa-solid fa-money-bill-wave"></i> Total remitted</dt><dd id="manageRemitted"></dd></div>
                        <div><dt><i class="fa-solid fa-receipt"></i> Sales handled</dt><dd id="manageSales"></dd></div>
                    </dl>
                    <a class="btn btn-sm btn-outline-secondary w-100 fw-bold mt-3" id="manageViewLink" href="#"><i class="fa-regular fa-eye me-1"></i> Open full record</a>
                </div>

                <form id="manageStaffForm" class="staff-section">
                    <input type="hidden" name="action" value="update_staff">
                    <input type="hidden" name="user_id">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <div class="staff-section-title"><i class="fa-solid fa-id-card"></i>Name &amp; position</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name *</label>
                            <input type="text" class="form-control" name="first_name" maxlength="60" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name" maxlength="60">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" maxlength="60" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Suffix</label>
                            <input type="text" class="form-control" name="suffix" maxlength="20">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Position</label>
                            <input type="text" class="form-control" name="position" placeholder="e.g. Cashier" list="staffPositionOptions" maxlength="60">
                        </div>
                    </div>
                    <button type="submit" class="login-btn mt-3 w-100" id="manageSaveBtn">Save Details</button>
                </form>

                <form id="resetStaffPwForm" class="staff-section">
                    <input type="hidden" name="action" value="reset_staff_password">
                    <input type="hidden" name="user_id">
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <div class="staff-section-title"><i class="fa-solid fa-key"></i>Reset password</div>
                    <div class="staff-password-row">
                        <div class="staff-password-wrap">
                            <input type="text" class="form-control" name="password" id="resetPwInput" minlength="8" placeholder="New temporary password" autocomplete="new-password" required>
                        </div>
                        <button type="button" class="btn btn-outline-secondary staff-pw-generate" id="resetPwGenerate"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
                    </div>
                    <div class="staff-pw-hint">Give this to them in person. They will have to choose their own password when they next sign in.</div>
                    <button type="submit" class="btn btn-outline-secondary mt-3 w-100 fw-bold" id="resetPwBtn">Reset Password</button>
                </form>

                <div class="staff-section">
                    <div class="staff-section-title"><i class="fa-solid fa-user-xmark"></i>Remove from stall</div>
                    <p class="staff-pw-hint mt-0">Signs them out and blocks their login. Their past sales stay in your history. This cannot be undone from here.</p>
                    <button type="button" class="btn btn-outline-danger w-100 fw-bold" id="removeStaffBtn">
                        <i class="fa-solid fa-trash-can me-1"></i> Remove Staff Member
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.staff-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; }
.staff-search { position: relative; flex: 1 1 260px; max-width: 360px; }
.staff-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; }
.staff-search input { padding-left: 34px; }
.staff-namecell { display: flex; align-items: center; gap: 10px; min-width: 0; }
.staff-name-text { min-width: 0; }
.staff-name-text .staff-sub { overflow-wrap: anywhere; }
.staff-status-cell { display: flex; align-items: center; gap: 10px; white-space: nowrap; }
.merchant-row-actions { flex-wrap: nowrap; }
#staffTable { width: 100%; }
.staff-mini-avatar { width: 32px; height: 32px; border-radius: 50%; flex: 0 0 32px; background: var(--gp-green-900); color: var(--gp-gold-light); font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.staff-sub { font-size: 11px; color: var(--text-muted); }
.staff-empty-search { display: none; }
.staff-modal-dialog { max-width: 760px; }
.staff-manage-dialog { max-width: 860px; }
.staff-profile { border: 1px solid var(--ad-line); border-radius: 12px; padding: 16px; margin-bottom: 20px; background: var(--gp-cream, #faf8f2); }
.staff-profile-top { display: flex; align-items: center; gap: 14px; }
.staff-avatar.staff-avatar--light { width: 56px; height: 56px; flex: 0 0 56px; aspect-ratio: 1; font-size: 18px; background: var(--gp-green-900); border: 0; }
.staff-mini-avatar { aspect-ratio: 1; min-width: 32px; }
.staff-mini-avatar, .staff-avatar { position: relative; overflow: hidden; }
.staff-mini-avatar img, .staff-avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.staff-profile-id { flex: 1; min-width: 0; }
.staff-profile-name { font-size: 16px; font-weight: 800; line-height: 1.25; overflow-wrap: anywhere; }
.staff-profile-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 5px; align-items: center; }
.staff-profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; margin: 14px 0 0; }
.staff-profile-grid dt { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 2px; }
.staff-profile-grid dt i { width: 14px; color: var(--emerald-800); }
.staff-profile-grid dd { margin: 0; font-size: 13px; font-weight: 600; overflow-wrap: anywhere; }
@media (min-width: 768px) { .staff-profile-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 480px) { .staff-profile-grid { grid-template-columns: 1fr; } }
.staff-manage-body { padding: 20px 24px 24px; max-height: 76vh; overflow-y: auto; }
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

// ── Manage: edit details, reset password, remove ─────────────────────────────
let manageRow = null;
const manageModalEl = document.getElementById('manageStaffModal');

function refreshManageStatus(isActive) {
    const badge = document.getElementById('manageStatusBadge');
    badge.className = 'badge status-badge ' + (isActive ? 'bg-success' : 'bg-secondary');
    badge.textContent = isActive ? 'Active' : 'Inactive';
    document.getElementById('manageStatusSwitch').checked = isActive;
}

// The modal's switch drives the row's own toggle, so the table, badge and
// filter all stay in sync with one code path.
document.getElementById('manageStatusSwitch').addEventListener('change', async function () {
    const rowSwitch = manageRow?.querySelector('.form-switch input');
    if (!rowSwitch) return;
    const id = parseInt(manageRow.querySelector('[data-id]').dataset.id, 10);
    rowSwitch.checked = this.checked;   // mirror first: its error path flips it back
    this.disabled = true;
    await toggleStaffStatus(id, rowSwitch);
    refreshManageStatus(manageRow.dataset.status === 'Active');
    this.disabled = false;
});

function openManageStaff(btn) {
    manageRow = btn.closest('tr');
    const d = btn.dataset;
    const isActive = manageRow.dataset.status === 'Active';
    const set = (id, v) => { document.getElementById(id).textContent = v || '—'; };
    set('manageStaffName', d.name);
    set('managePosition', d.position || 'Merchant Staff');
    set('manageEmail', d.email);
    set('manageContact', d.contact);
    set('manageHired', d.hired);
    set('manageLastRemit', d.lastremit);
    set('manageRemitted', d.remitted);
    set('manageSales', d.sales + ' sale(s)');
    const avatar = document.getElementById('manageAvatar');
    avatar.textContent = ((d.first[0] || '') + (d.last[0] || '')).toUpperCase() || '?';
    if (d.photo) {
        // Initials stay underneath as the fallback if the image fails to load.
        const img = document.createElement('img');
        img.src = d.photo;
        img.alt = '';
        img.onerror = () => img.remove();
        avatar.prepend(img);
    }
    refreshManageStatus(isActive);
    document.getElementById('manageViewLink').href =
        manageRow.querySelector('.merchant-row-btn--view')?.href || '#';
    manageModalEl.querySelectorAll('input[name="user_id"]').forEach(i => { i.value = d.id; });
    const f = document.getElementById('manageStaffForm');
    f.first_name.value = d.first;
    f.middle_name.value = d.middle;
    f.last_name.value = d.last;
    f.suffix.value = d.suffix;
    f.position.value = d.position;
    document.getElementById('resetPwInput').value = '';
    bootstrap.Modal.getOrCreateInstance(manageModalEl).show();
}

document.getElementById('manageStaffForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('manageSaveBtn');
    btn.disabled = true;
    const d = await gpFetchJson(STAFF_API, new FormData(this));
    btn.disabled = false;
    gpToast(d.message, d.success);
    if (d.success) setTimeout(() => location.reload(), 900);
});

document.getElementById('resetPwGenerate').addEventListener('click', function () {
    const all = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    const buf = new Uint32Array(10);
    crypto.getRandomValues(buf);
    document.getElementById('resetPwInput').value = Array.from(buf, n => all[n % all.length]).join('');
});

document.getElementById('resetStaffPwForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const name = document.getElementById('manageStaffName').textContent;
    const ok = await gpConfirm({
        title: 'Reset this password?',
        detail: [['Staff member', name]],
        note: 'Their current password stops working right away.',
        confirmLabel: 'Reset Password',
    });
    if (!ok) return;
    const btn = document.getElementById('resetPwBtn');
    btn.disabled = true;
    const d = await gpFetchJson(STAFF_API, new FormData(this));
    btn.disabled = false;
    gpToast(d.message, d.success);
});

document.getElementById('removeStaffBtn').addEventListener('click', async function () {
    const name = document.getElementById('manageStaffName').textContent;
    const ok = await gpConfirm({
        title: 'Remove ' + name + '?',
        body: 'They are signed out and can no longer log in to your POS.',
        note: 'Their past sales stay in your history.',
        confirmLabel: 'Remove',
        danger: true,
    });
    if (!ok) return;
    const f = new FormData();
    f.append('action', 'remove_staff');
    f.append('user_id', document.querySelector('#manageStaffForm input[name="user_id"]').value);
    f.append('csrf_token', <?= json_encode($csrfToken) ?>);
    this.disabled = true;
    const d = await gpFetchJson(STAFF_API, f);
    this.disabled = false;
    gpToast(d.message, d.success);
    if (d.success) {
        bootstrap.Modal.getInstance(manageModalEl)?.hide();
        manageRow?.remove();
    }
});

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
        const q = (document.getElementById('staffSearch')?.value || '').trim().toLowerCase();
        const show = (currentFilter === 'All' || row.dataset.status === currentFilter)
            && (!q || (row.dataset.search || '').includes(q));
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

document.getElementById('staffSearch').addEventListener('input', applyStatusFilter);

// Default: show Active only on page load
document.addEventListener('DOMContentLoaded', () => setStatusFilter('Active'));
</script>
<?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
<?php require __DIR__ . '/../includes/partials/bottom_nav_merchant.php'; ?>
</body>
</html>
