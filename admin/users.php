<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);

gjc_backfill_student_ids($db);
gjc_ensure_account_suspension_schema($db);

// Lift timed suspensions that have run out before reading the table, so the
// list can never show one whose end date already passed.
gjc_expire_due_suspensions($db);

// The suspension columns are added by the self-healer above; if that ALTER
// couldn't run (older MySQL grant, say) the page still has to render, so the
// suspension parts are selected only when they actually exist.
$userCols = gjc_table_columns($db, 'users');
$hasSuspensionCols = in_array('suspended_until', $userCols, true);

$roleFilter    = trim((string) ($_GET['role'] ?? ''));
$statusFilter  = strtolower(trim((string) ($_GET['status'] ?? '')));
$excludeAdmin  = !empty($_GET['exclude_admin']);

// users.status is the only account-state column there is, so the filter offers
// exactly its labels rather than options with no data behind them.
$statusOptions = [
    'active'    => 'Active',
    'suspended' => 'Suspended',
    'banned'    => 'Banned',
    'inactive'  => 'Inactive',
];
if (!isset($statusOptions[$statusFilter])) {
    $statusFilter = '';
}

$query = "
    SELECT
        u.userID,
        u.first_name,
        u.last_name,
        u.email,
        u.roleID,
        u.sub_role,
        u.status,
        " . ($hasSuspensionCols ? "u.suspended_until,\n        u.suspension_reason," : "") . "
        r.role_name as role,
        si.studentID as student_id
    FROM users u
    LEFT JOIN role r ON u.roleID = r.roleID
    LEFT JOIN wallet w ON u.userID = w.userID
    LEFT JOIN student_info si ON si.userID = u.userID
";
$conditions = [];
$params = [];
if ($roleFilter !== '') {
    $conditions[] = 'LOWER(COALESCE(r.role_name, "")) = ?';
    $params[] = strtolower($roleFilter);
}
if ($statusFilter !== '') {
    $conditions[] = 'u.status = ?';
    $params[] = $statusOptions[$statusFilter];
}
if ($excludeAdmin) {
    $conditions[] = 'LOWER(COALESCE(r.role_name, "")) != ?';
    $params[] = 'finance';
}
if ($conditions) {
    $query .= ' WHERE ' . implode(' AND ', $conditions);
}
$query .= ' ORDER BY u.userID DESC';

$stmt = $db->prepare($query);
$stmt->execute($params);
$dbUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentAdminId = gjc_user_id();

// This page's older rows echo raw; anything added below is admin-entered free
// text (a suspension reason) or ends up in an HTML attribute, so it gets
// escaped properly — gjc_e() only casts to string, it does not escape.
$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$users = [];
foreach ($dbUsers as $u) {
    $roleName = ($u['role'] === 'finance') ? 'Finance' : ucfirst($u['role'] ?? 'User');
    $role = strtolower($u['role'] ?? '');

    if ($role === 'student') {
        $displayId = $u['student_id'] ?? ('GJC' . date('Y') . '-????');
    } elseif (in_array($role, ['merchant', 'merchant_admin', 'merchant_staff'], true)) {
        $displayId = 'MER-' . str_pad($u['userID'], 4, '0', STR_PAD_LEFT);
    } elseif ($role === 'finance') {
        $displayId = 'FIN-' . str_pad($u['userID'], 4, '0', STR_PAD_LEFT);
    } else {
        $displayId = 'GJC-' . str_pad($u['userID'], 4, '0', STR_PAD_LEFT);
    }

    $statusLabel = trim((string) ($u['status'] ?? 'Active'));
    if ($statusLabel === '') {
        $statusLabel = 'Active';
    }
    $isSuspended = ($statusLabel === 'Suspended');
    $isBanned    = ($statusLabel === 'Banned');

    // Mirrors users_suspend_guard() in admin/api/users.php. That endpoint is
    // the real gate — this only decides whether to offer the menu item, so the
    // two must agree or the UI offers an action that always fails. The same
    // guard covers Suspend and Ban: an account you may not suspend is an
    // account you may not ban either.
    $canSuspend = ((int) $u['userID'] !== $currentAdminId)
        && !in_array((int) ($u['roleID'] ?? 0), [3, 4], true)
        && (string) ($u['sub_role'] ?? '') !== 'super_admin';

    $users[] = [
        "id"          => (int) $u['userID'],
        "name"        => trim($u['first_name'] . ' ' . $u['last_name']),
        "role"        => $roleName,
        "school_id"   => $displayId,
        "email"       => $u['email'],
        "status"      => $statusLabel,
        "suspended"   => $isSuspended,
        "banned"      => $isBanned,
        // A null end date on a live suspension means indefinite. A ban never
        // has one — it ends only when finance lifts it.
        "suspended_until"   => $isSuspended ? ($u['suspended_until'] ?? null) : null,
        // The reason column is shared by both lockouts, so it is read for
        // either one — it is what the pill's tooltip shows.
        "suspension_reason" => ($isSuspended || $isBanned) ? trim((string) ($u['suspension_reason'] ?? '')) : '',
        "can_suspend" => $canSuspend,
    ];
}

$currentPage = 'users';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Users Management | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=37">
    <link rel="stylesheet" href="<?= CSS_URL ?>/users.css?v=11">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
</head>

<body class="gp-theme">

    <div class="admin-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

        <main class="admin-main">

            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>

                <div>
                    <h1>Users Management</h1>
                    <p>Manage users, roles, status, wallet access, and account controls.</p>
                </div>

                <div class="admin-user">
                    <span>Admin</span>
                    <div class="avatar">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                </div>
            </header>

            <section class="users-command-panel">

                <div class="users-panel-header">
                    <div>
                        <h3>All Users</h3>
                        <p>Search, filter, and manage every account's wallet access and status.</p>
                    </div>
                </div>

                <form class="users-filter-grid" method="GET" action="<?= ADMIN_URL ?>/users.php">

                    <div class="premium-field search-field">
                        <label>Search User</label>
                        <input type="text" id="usersSearchInput" placeholder="Name, email, school ID, or student"
                            onkeydown="if (event.key === 'Enter') { event.preventDefault(); }">
                    </div>

                    <div class="premium-field">
                        <label>Role</label>
                        <select name="role">
                            <option value="" <?= $roleFilter === '' ? 'selected' : '' ?>>All Roles</option>
                            <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option>
                            <option value="merchant" <?= $roleFilter === 'merchant' ? 'selected' : '' ?>>Merchant</option>
                            <option value="finance" <?= $roleFilter === 'finance' ? 'selected' : '' ?>>Finance</option>
                        </select>
                    </div>

                    <div class="premium-field">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <?php foreach ($statusOptions as $value => $label): ?>
                            <option value="<?= $esc($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>>
                                <?= $esc($label) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="filter-btn">
                        Filter
                    </button>

                </form>

                <div class="table-responsive">
                    <table class="table users-table align-middle js-datatable" id="usersTable" data-page-length="10" data-hide-filter="true">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>School ID</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach($users as $u): ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar">
                                            <?php echo strtoupper(substr($u['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <strong><?php echo $u['name']; ?></strong>
                                            <small><?php echo $u['role']; ?> Account</small>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span class="role-pill">
                                        <?php echo $u['role']; ?>
                                    </span>
                                </td>

                                <td><?php echo $u['school_id']; ?></td>

                                <td><?php echo $u['email']; ?></td>

                                <td>
                                    <?php
                                    $statusClass = strtolower($u['status']);
                                    // Second line under a Suspended pill, for
                                    // its end date. A ban has none — the pill
                                    // alone says it. The full reason is the
                                    // pill's tooltip either way: it is free
                                    // text and too long for the cell.
                                    $untilNote = '';
                                    if ($u['suspended']) {
                                        $untilNote = $u['suspended_until']
                                            ? 'until ' . date('M d, Y', strtotime((string) $u['suspended_until']))
                                            : 'indefinite';
                                    }
                                    ?>
                                    <span class="status-pill <?php echo $statusClass; ?>"
                                        <?= $u['suspension_reason'] !== '' ? 'title="' . $esc($u['suspension_reason']) . '"' : '' ?>>
                                        <?php echo $esc($u['status']); ?>
                                    </span>
                                    <?php if ($untilNote !== ''): ?>
                                    <small class="status-note"><?= $esc($untilNote) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="action-area">
                                        <!-- Read view: the record is identified by a signed HMAC
                                             token. Tampering with it in the URL fails verification. -->
                                        <a class="premium-action-btn text-decoration-none d-inline-flex align-items-center"
                                            href="<?= ADMIN_URL ?>/view_user.php?token=<?= urlencode(gjc_make_view_token($u['id'], 'user')) ?>">
                                            <i class="fa-regular fa-eye me-2"></i>View
                                        </a>

                                        <div class="dropdown">
                                            <button class="premium-action-btn dropdown-toggle" type="button"
                                                data-bs-toggle="dropdown">
                                                Manage
                                            </button>

                                            <ul class="dropdown-menu premium-dropdown">
                                                <?php if ($u['banned']): ?>
                                                <!-- A ban outranks a suspension, so it is the only
                                                     lockout action a banned account offers: there is
                                                     nothing to suspend on top of it, and Suspend
                                                     would be refused by the endpoint anyway. -->
                                                <li><button type="button" class="dropdown-item js-lift-ban"
                                                        data-user-id="<?= (int) $u['id'] ?>"
                                                        data-user-name="<?= $esc($u['name']) ?>">
                                                        Lift Ban
                                                    </button></li>
                                                <?php else: ?>
                                                <?php if ($u['suspended']): ?>
                                                <li><button type="button" class="dropdown-item js-lift-suspension"
                                                        data-user-id="<?= (int) $u['id'] ?>"
                                                        data-user-name="<?= $esc($u['name']) ?>">
                                                        Lift Suspension
                                                    </button></li>
                                                <?php elseif ($u['can_suspend']): ?>
                                                <li><button type="button" class="dropdown-item js-suspend"
                                                        data-user-id="<?= (int) $u['id'] ?>"
                                                        data-user-name="<?= $esc($u['name']) ?>">
                                                        Suspend
                                                    </button></li>
                                                <?php else: ?>
                                                <li><span class="dropdown-item disabled"
                                                        title="Finance and super-admin accounts can't be suspended here, and you can't suspend yourself.">
                                                        Suspend
                                                    </span></li>
                                                <?php endif; ?>

                                                <?php if ($u['can_suspend']): ?>
                                                <li><button type="button" class="dropdown-item js-ban"
                                                        data-user-id="<?= (int) $u['id'] ?>"
                                                        data-user-name="<?= $esc($u['name']) ?>"
                                                        data-suspended="<?= $u['suspended'] ? '1' : '0' ?>">
                                                        Ban
                                                    </button></li>
                                                <?php else: ?>
                                                <li><span class="dropdown-item disabled"
                                                        title="Finance and super-admin accounts can't be banned here, and you can't ban yourself.">
                                                        Ban
                                                    </span></li>
                                                <?php endif; ?>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
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

    <!-- Suspend Account Modal -->
    <div class="modal fade" id="suspendModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content custom-modal">
                <div class="modal-header"><h5 class="modal-title">Suspend Account</h5></div>
                <div class="modal-body">
                    <form id="suspendForm">
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="user_id" id="suspendUserId">

                        <p class="mb-3">
                            Suspending <strong id="suspendUserName"></strong> ends any signed-in session,
                            blocks their login, freezes their wallet, and stops money being sent to them.
                            A merchant owner's stall stops selling and their staff are locked out too.
                        </p>

                        <div class="premium-field mb-3">
                            <label for="suspendDuration">Duration</label>
                            <select name="duration" id="suspendDuration" class="form-select">
                                <option value="3">3 days</option>
                                <option value="7" selected>7 days</option>
                                <option value="30">30 days</option>
                                <option value="indefinite">Indefinite — until lifted by finance</option>
                            </select>
                        </div>

                        <div class="premium-field">
                            <label for="suspendReason">Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" id="suspendReason" class="form-control" rows="3"
                                maxlength="255" required
                                placeholder="Shown to the user and recorded in the audit trail."></textarea>
                        </div>

                        <div id="suspendMsg" class="mt-3"></div>
                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="login-btn" style="flex:1" id="suspendSubmitBtn">Suspend Account</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Ban Account Modal -->
    <div class="modal fade" id="banModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content custom-modal">
                <div class="modal-header"><h5 class="modal-title">Ban Account</h5></div>
                <div class="modal-body">
                    <form id="banForm">
                        <input type="hidden" name="action" value="ban">
                        <input type="hidden" name="user_id" id="banUserId">

                        <p class="mb-3">
                            Banning <strong id="banUserName"></strong> is <strong>permanent</strong>. It ends any
                            signed-in session, blocks their login for good, freezes their wallet, and stops money
                            being sent to them. A merchant owner's stall stops selling and their staff are locked
                            out too. Unlike a suspension it never expires — only a finance admin can lift it.
                        </p>

                        <!-- Shown only when the ban replaces a live suspension, so the
                             admin knows this escalates rather than starts a lockout. -->
                        <div id="banEscalationNote" class="alert alert-warning d-none">
                            This account is currently suspended. Banning it replaces that suspension.
                        </div>

                        <div class="premium-field mb-3">
                            <label for="banReason">Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" id="banReason" class="form-control" rows="3"
                                maxlength="255" required
                                placeholder="Shown to the user and recorded in the audit trail."></textarea>
                        </div>

                        <div class="premium-field">
                            <label for="banConfirm">Type <strong>BAN</strong> to confirm <span class="text-danger">*</span></label>
                            <input type="text" name="confirm" id="banConfirm" class="form-control"
                                autocomplete="off" required placeholder="BAN">
                        </div>

                        <div id="banMsg" class="mt-3"></div>
                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-danger" style="flex:1" id="banSubmitBtn">Ban Account</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>

    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }

    // The filter-row "Search User" field drives the users table's own
    // DataTables search directly, instead of the table showing its own
    // auto-rendered search box too (data-hide-filter="true" above suppresses
    // that one) — one search field instead of two.
    jQuery(function ($) {
        const $input = $("#usersSearchInput");
        const $table = $("#usersTable");
        if (!$input.length || !$table.length) {
            return;
        }
        $input.on("input", function () {
            $table.DataTable().search(this.value).draw();
        });
    });

    // ── Suspend / ban / lift ────────────────────────────────────────────────
    // Delegated off the table body: DataTables re-renders rows on every page
    // change and sort, so per-button listeners bound at load would go stale.
    const USERS_API = "<?= ADMIN_URL ?>/api/users.php";

    document.getElementById("usersTable").addEventListener("click", function (e) {
        const suspendBtn = e.target.closest(".js-suspend");
        if (suspendBtn) {
            document.getElementById("suspendUserId").value = suspendBtn.dataset.userId;
            document.getElementById("suspendUserName").textContent = suspendBtn.dataset.userName;
            document.getElementById("suspendReason").value = "";
            document.getElementById("suspendMsg").innerHTML = "";
            bootstrap.Modal.getOrCreateInstance(document.getElementById("suspendModal")).show();
            return;
        }

        const banBtn = e.target.closest(".js-ban");
        if (banBtn) {
            document.getElementById("banUserId").value = banBtn.dataset.userId;
            document.getElementById("banUserName").textContent = banBtn.dataset.userName;
            document.getElementById("banReason").value = "";
            document.getElementById("banConfirm").value = "";
            document.getElementById("banMsg").innerHTML = "";
            document.getElementById("banEscalationNote")
                .classList.toggle("d-none", banBtn.dataset.suspended !== "1");
            bootstrap.Modal.getOrCreateInstance(document.getElementById("banModal")).show();
            return;
        }

        const liftBanBtn = e.target.closest(".js-lift-ban");
        if (liftBanBtn) {
            liftLockout(liftBanBtn, "lift_ban",
                "Lift the ban on NAME? They will be able to sign in and transact again immediately.");
            return;
        }

        const liftBtn = e.target.closest(".js-lift-suspension");
        if (liftBtn) {
            liftLockout(liftBtn, "lift_suspension",
                "Lift the suspension on NAME? They will be able to sign in and transact again immediately.");
        }
    });

    document.getElementById("suspendForm").addEventListener("submit", async function (e) {
        e.preventDefault();
        const btn = document.getElementById("suspendSubmitBtn");
        const msg = document.getElementById("suspendMsg");
        btn.disabled = true;
        btn.textContent = "Suspending...";
        try {
            const resp = await fetch(USERS_API, { method: "POST", body: new FormData(this) });
            const data = await resp.json();
            if (data.success) {
                location.reload();
                return;
            }
            msg.innerHTML = '<div class="alert alert-danger mb-0"></div>';
            msg.firstChild.textContent = data.message;
        } catch (err) {
            msg.innerHTML = '<div class="alert alert-danger mb-0">Could not reach the server. Try again.</div>';
        }
        btn.disabled = false;
        btn.textContent = "Suspend Account";
    });

    document.getElementById("banForm").addEventListener("submit", async function (e) {
        e.preventDefault();
        const btn = document.getElementById("banSubmitBtn");
        const msg = document.getElementById("banMsg");

        // Client-side echo of the endpoint's own check, so a mistyped
        // confirmation costs a keystroke instead of a round trip. The endpoint
        // still enforces it — this is convenience, not the gate.
        if (document.getElementById("banConfirm").value.trim().toUpperCase() !== "BAN") {
            msg.innerHTML = '<div class="alert alert-danger mb-0">Type BAN in the confirmation box to continue.</div>';
            return;
        }

        btn.disabled = true;
        btn.textContent = "Banning...";
        try {
            const resp = await fetch(USERS_API, { method: "POST", body: new FormData(this) });
            const data = await resp.json();
            if (data.success) {
                location.reload();
                return;
            }
            msg.innerHTML = '<div class="alert alert-danger mb-0"></div>';
            msg.firstChild.textContent = data.message;
        } catch (err) {
            msg.innerHTML = '<div class="alert alert-danger mb-0">Could not reach the server. Try again.</div>';
        }
        btn.disabled = false;
        btn.textContent = "Ban Account";
    });

    // Both lifts are the same round trip against the same endpoint — only the
    // action name and the confirm wording differ, so they share one function
    // rather than two copies that could drift apart.
    async function liftLockout(btn, action, promptTemplate) {
        if (!confirm(promptTemplate.replace("NAME", btn.dataset.userName))) {
            return;
        }
        btn.disabled = true;
        const body = new FormData();
        body.append("action", action);
        body.append("user_id", btn.dataset.userId);
        try {
            const resp = await fetch(USERS_API, { method: "POST", body: body });
            const data = await resp.json();
            if (data.success) {
                location.reload();
                return;
            }
            alert(data.message);
        } catch (err) {
            alert("Could not reach the server. Try again.");
        }
        btn.disabled = false;
    }
    </script>

</body>

</html>
