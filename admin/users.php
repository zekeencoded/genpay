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
        " . gjc_sub_role_sql('u.roleID') . " AS sub_role,
        u.status,
        u.created_at,
        r.role_name as role,
        si.studentID as student_id" . (gjc_table_exists($db, 'parents') ? ', p.id AS parent_row_id, COALESCE(u.created_at, p.created_at) AS parent_created_at' : '') . (in_array('department', gjc_table_columns($db, 'student_info'), true) ? ', si.department' : '') . (in_array('graduated_at', gjc_table_columns($db, 'student_info'), true) ? ', si.graduated_at' : '') . "
    FROM users u
    LEFT JOIN role r ON u.roleID = r.roleID
    LEFT JOIN student_info si ON si.userID = u.userID
" . (gjc_table_exists($db, 'parents') ? ' LEFT JOIN parents p ON p.user_id = u.userID' : '') . "
";
$conditions = [];
$params = [];
// Staff a merchant removed stay in users (past sales point at them) but are no longer accounts to manage.
if (in_array('staff_removed_at', gjc_table_columns($db, 'users'), true)) {
    $conditions[] = 'u.staff_removed_at IS NULL';
}
if ($roleFilter !== '') {
    $conditions[] = 'LOWER(COALESCE(r.role_name, "")) = ?';
    $params[] = strtolower($roleFilter);
}
if ($statusFilter !== '') {
    $conditions[] = 'u.status = ?';
    $params[] = $statusOptions[$statusFilter];
}
if ($excludeAdmin) {
    $conditions[] = 'LOWER(COALESCE(r.role_name, "")) NOT IN (?, ?)';
    array_push($params, 'finance', 'finance_staff');
}
if ($conditions) {
    $query .= ' WHERE ' . implode(' AND ', $conditions);
}
$query .= ' ORDER BY u.userID DESC';

$stmt = $db->prepare($query);
$stmt->execute($params);
$dbUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currentAdminId = gjc_user_id();
// Finance staff get a read-only list; account actions are super-admin only (admin/api/users.php is off their allowlist).
$isSuperAdmin = gjc_sub_role() === 'super_admin';
// Choices for the Add User modal's student and merchant fields.
$addUserCourses = $isSuperAdmin ? $db->query("SELECT courseID, course_code, course_name FROM course WHERE course_code <> 'FACULTY' ORDER BY course_code")->fetchAll(PDO::FETCH_ASSOC) : [];
// Departments already on file, suggested in the Add/Edit faculty forms so spellings stay consistent.
$facultyDepartments = ($isSuperAdmin && in_array('department', gjc_table_columns($db, 'student_info'), true))
    ? $db->query("SELECT DISTINCT department FROM student_info WHERE department IS NOT NULL AND department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN)
    : [];
$addUserStalls = $isSuperAdmin ? $db->query(
    "SELECT s.stall_id, s.label FROM stalls s
      WHERE s.status = 'vacant' AND NOT EXISTS (SELECT 1 FROM merchant m WHERE m.stall_id = s.stall_id)
      ORDER BY s.label"
)->fetchAll(PDO::FETCH_ASSOC) : [];

$esc = gjc_e(...);

$users = [];
foreach ($dbUsers as $u) {
    $u = gjc_with_user_suspension($db, $u, (int) $u['userID']);
    $roleName = ['finance' => 'Finance Admin', 'finance_staff' => 'Finance Staff', 'parent' => 'Guardian', 'merchant_staff' => 'Merchant Staff', 'faculty' => 'Faculty'][$u['role'] ?? ''] ?? ucfirst($u['role'] ?? 'User');
    $role = strtolower($u['role'] ?? '');

    if ($role === 'student' || $role === 'faculty') {
        $displayId = $u['student_id'] ?? ('GJC' . date('Y') . '-????');
    } elseif (in_array($role, ['merchant', 'merchant_admin', 'merchant_staff'], true)) {
        $displayId = gjc_account_id('MER', (int) $u['userID'], $u['created_at'] ?? null);
    } elseif ($role === 'parent') {
        // GRD numbers come from parents.id, so a guardian without that row gets a placeholder.
        $displayId = !empty($u['parent_row_id'])
            ? gjc_guardian_id((int) $u['parent_row_id'], $u['parent_created_at'])
            : 'GRD' . date('Y') . '-????';
    } elseif ($role === 'finance' || $role === 'finance_staff') {
        $displayId = gjc_account_id('FIN', (int) $u['userID'], $u['created_at'] ?? null);
    } else {
        $displayId = gjc_account_id('USR', (int) $u['userID'], $u['created_at'] ?? null);
    }

    $statusLabel = trim((string) ($u['status'] ?? 'Active'));
    if ($statusLabel === '') {
        $statusLabel = 'Active';
    }
    $isSuspended = ($statusLabel === 'Suspended');
    $isBanned    = ($statusLabel === 'Banned');

    // Must match users_suspend_guard() in admin/api/users.php.
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
        "department"  => $role === 'faculty' ? trim((string) ($u['department'] ?? '')) : '',
        // Faculty reuse graduated_at as the date they left the school.
        "left_at"     => $role === 'faculty' && !empty($u['graduated_at']) ? (string) $u['graduated_at'] : '',
        "can_reset_password" => in_array((int) $u['roleID'], [1, GJC_FACULTY_ROLE_ID], true)
            && in_array((string) ($u['sub_role'] ?? ''), ['', 'student', 'faculty'], true),
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
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=45">
    <link rel="stylesheet" href="<?= CSS_URL ?>/users.css?v=13">
    <link rel="stylesheet" href="<?= CSS_URL ?>/maintenance.css?v=24">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=3">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                    <span><?= htmlspecialchars(gjc_current_user($db)['name'] ?? 'Finance', ENT_QUOTES, 'UTF-8') ?></span>
                    <?php require __DIR__ . '/../includes/partials/avatar_admin.php'; ?>
                </div>
            </header>

            <section class="users-command-panel">

                <div class="users-panel-header">
                    <div>
                        <h3>All Users</h3>
                        <p>Search, filter, and manage every account's wallet access and status.</p>
                    </div>
                    <?php if ($isSuperAdmin): ?>
                    <button type="button" class="filter-btn add-staff-btn" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                        <i class="fa-solid fa-user-plus me-2"></i>Add User
                    </button>
                    <?php endif; ?>
                </div>

                <form class="users-filter-grid" method="GET" action="<?= ADMIN_URL ?>/users.php">

                    <div class="premium-field search-field">
                        <label for="usersSearchInput">Search User</label>
                        <input type="search" id="usersSearchInput" placeholder="Name, email, or ID number" autocomplete="off"
                            onkeydown="if (event.key === 'Enter') { event.preventDefault(); }">
                    </div>

                    <div class="premium-field">
                        <label>Role</label>
                        <select name="role">
                            <option value="" <?= $roleFilter === '' ? 'selected' : '' ?>>All Roles</option>
                            <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option>
                            <option value="faculty" <?= $roleFilter === 'faculty' ? 'selected' : '' ?>>Faculty</option>
                            <option value="merchant" <?= $roleFilter === 'merchant' ? 'selected' : '' ?>>Merchant</option>
                            <option value="merchant_staff" <?= $roleFilter === 'merchant_staff' ? 'selected' : '' ?>>Merchant Staff</option>
                            <option value="parent" <?= $roleFilter === 'parent' ? 'selected' : '' ?>>Guardian</option>
                            <option value="finance" <?= $roleFilter === 'finance' ? 'selected' : '' ?>>Finance Admin</option>
                            <option value="finance_staff" <?= $roleFilter === 'finance_staff' ? 'selected' : '' ?>>Finance Staff</option>
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
                    <table class="table users-table align-middle js-datatable" id="usersTable" data-page-length="10" data-hide-filter="true"
                           data-empty-message="No accounts match these filters. Clear the search, role and status above to see everyone.">
                        <thead>
                            <tr>
                                <th data-card="hide">ID Number</th>
                                <th data-card="title">Name</th>
                                <th>Role</th>
                                <th data-card="hide">Email</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach($users as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $u['school_id'], ENT_QUOTES, 'UTF-8'); ?></td>

                                <td>
                                    <div class="user-cell">
                                        <div class="user-avatar">
                                            <?php echo htmlspecialchars(strtoupper(substr((string) $u['name'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars((string) $u['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <small><?php echo htmlspecialchars((string) $u['role'], ENT_QUOTES, 'UTF-8'); ?> Account</small>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <?php if ($u['department'] !== ''): ?>
                                    <span class="role-pill has-dept" title="<?= $esc($u['role'] . ', ' . $u['department']) ?>">
                                        <?= $esc($u['role']) ?>
                                        <span class="role-pill-dept"><?= $esc($u['department']) ?></span>
                                    </span>
                                    <?php else: ?>
                                    <span class="role-pill">
                                        <?php echo htmlspecialchars((string) $u['role'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($u['left_at'] !== ''): ?>
                                    <small class="status-note">Left school &middot; <?= $esc(date('M Y', strtotime($u['left_at']))) ?></small>
                                    <?php endif; ?>
                                </td>

                                <td><?php echo htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8'); ?></td>

                                <td>
                                    <?php
                                    $statusClass = strtolower($u['status']);
                                    // Suspension end date under the pill; reason is in the tooltip.
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

                                        <?php if ($isSuperAdmin): ?>
                                        <div class="dropdown">
                                            <button class="premium-action-btn dropdown-toggle" type="button"
                                                data-bs-toggle="dropdown">
                                                Manage
                                            </button>

                                            <ul class="dropdown-menu premium-dropdown">
                                                <?php if ($u['can_reset_password']): ?>
                                                <li><button type="button" class="dropdown-item js-reset-password"
                                                        data-user-id="<?= (int) $u['id'] ?>"
                                                        data-user-name="<?= $esc($u['name']) ?>"
                                                        data-student-id="<?= $esc($u['school_id']) ?>">
                                                        <i class="fa-solid fa-key me-2"></i>Reset Password
                                                    </button></li>
                                                <?php if ($u['role'] === 'Faculty'): ?>
                                                <li><button type="button" class="dropdown-item js-edit-faculty"
                                                        data-user-id="<?= (int) $u['id'] ?>"
                                                        data-user-name="<?= $esc($u['name']) ?>"
                                                        data-employee-id="<?= $esc($u['school_id']) ?>"
                                                        data-department="<?= $esc($u['department']) ?>">
                                                        <i class="fa-solid fa-pen me-2"></i>Edit Faculty Details
                                                    </button></li>
                                                <li><button type="button" class="dropdown-item js-faculty-exit"
                                                        data-action="<?= $u['left_at'] !== '' ? 'reinstate_faculty' : 'offboard_faculty' ?>"
                                                        data-user-id="<?= (int) $u['id'] ?>"
                                                        data-user-name="<?= $esc($u['name']) ?>">
                                                        <?php if ($u['left_at'] !== ''): ?>
                                                        <i class="fa-solid fa-rotate-left me-2"></i>Reinstate
                                                        <?php else: ?>
                                                        <i class="fa-solid fa-door-open me-2"></i>Offboard (left school)
                                                        <?php endif; ?>
                                                    </button></li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>
                                                <?php if ($u['banned']): ?>
                                                <!-- Banned accounts only offer Lift Ban. -->
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
                                        <?php endif; ?>
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

    <datalist id="facultyDepartments">
        <?php foreach ($facultyDepartments as $dept): ?><option value="<?= $esc($dept) ?>"></option><?php endforeach; ?>
    </datalist>

    <div class="modal fade" id="editFacultyModal" tabindex="-1" aria-labelledby="editFacultyTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content gp-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="editFacultyTitle">
                            <i class="fa-solid fa-pen"></i>Edit Faculty Details
                        </h5>
                        <p class="gp-modal-sub"><strong id="editFacultyName" style="color:var(--gp-ink)"></strong></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editFacultyForm" class="merchant-bypass-form" autocomplete="off" novalidate>
                        <input type="hidden" name="action" value="update_faculty">
                        <input type="hidden" name="user_id" id="editFacultyUserId">
                        <input type="hidden" name="csrf_token" value="<?= $esc(gjc_csrf_token()) ?>">
                        <label for="editFacultyEmployeeId">Employee ID *</label>
                        <input type="text" id="editFacultyEmployeeId" name="employee_id" maxlength="80" required>
                        <label for="editFacultyDepartment" class="mt-3">Department *</label>
                        <input type="text" id="editFacultyDepartment" name="department" maxlength="100" list="facultyDepartments" autocomplete="off" required>
                        <div class="mbf-credentials-note mt-2">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>Others type the Employee ID to send them GenCoin. Tell them if it changes.</span>
                        </div>
                    </form>
                    <div id="editFacultyMsg" role="status"></div>
                    <div class="maintenance-btn-row mt-3">
                        <button type="button" class="maintenance-btn muted" data-bs-dismiss="modal">Cancel</button>
                        <button class="maintenance-btn primary" type="submit" form="editFacultyForm" id="editFacultySubmit">Save</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content gp-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="">
                            <i class="fa-solid fa-key"></i>Reset Password
                        </h5>
                        <p class="gp-modal-sub"><strong id="resetPasswordName" style="color:var(--gp-ink)"></strong>, ID <span id="resetPasswordStudentId"></span></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="resetPasswordForm" class="merchant-bypass-form" autocomplete="off" novalidate>
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" id="resetPasswordUserId">
                        <input type="hidden" name="csrf_token" value="<?= $esc(gjc_csrf_token()) ?>">
                        <p style="font-size:13px;margin-bottom:6px">Check their ID above matches the person asking. Resetting will:</p>
                        <ul style="font-size:13px;color:var(--gp-muted);padding-left:18px;margin-bottom:18px">
                            <li>email a temporary password to their registered address</li>
                            <li>sign them out everywhere</li>
                            <li>ask them to choose a new password at login</li>
                        </ul>
                        <label for="resetPasswordConfirm">Type RESET to confirm *</label>
                        <input type="text" name="confirm" id="resetPasswordConfirm" pattern="RESET" data-error="Type RESET in capital letters." autocomplete="off" spellcheck="false" required>
                        <div class="mbf-credentials-note mt-2">
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                            <span>Suspensions and other account restrictions stay in place.</span>
                        </div>
                    </form>
                    <div id="resetPasswordMsg" role="status"></div>
                    <div class="maintenance-btn-row mt-3">
                        <button type="button" class="maintenance-btn muted" data-bs-dismiss="modal" id="resetPasswordClose">Cancel</button>
                        <button class="maintenance-btn primary" type="submit" form="resetPasswordForm" id="resetPasswordSubmit">Reset Password</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isSuperAdmin): ?>
    <!-- Add User Modal -->
    <!-- Same markup and styles as Maintenance → Add Merchant, so both account forms look alike. -->
    <div class="modal fade" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content gp-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="">
                            <i class="fa-solid fa-user-plus"></i>Add User
                        </h5>
                        <p class="gp-modal-sub">Choose a role to see the details it needs.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addStaffForm" class="merchant-bypass-form" autocomplete="off" novalidate>
                        <input type="hidden" name="action" value="add_user">
                        <input type="hidden" name="csrf_token" value="<?= $esc(gjc_csrf_token()) ?>">

                        <div class="mbf-section">
                            <div class="maintenance-form-grid">
                                <div class="full">
                                    <label for="addStaffRole">Role *</label>
                                    <select id="addStaffRole" name="role" required aria-describedby="addStaffRoleHint">
                                        <option value="" selected disabled>Select a role</option>
                                        <option value="student" data-hint="Enrolled student with a wallet.">Student</option>
                                        <option value="faculty" data-hint="Teaching staff with a wallet.">Faculty</option>
                                        <option value="merchant" data-hint="Runs a stall and accepts payments.">Merchant</option>
                                        <option value="parent" data-hint="Monitors a linked student.">Guardian</option>
                                        <option value="finance_staff" data-hint="Access: Dashboard, Users (view only), Transactions, Cash Management, Leases &amp; Rent, Stall Applications.">Finance Staff</option>
                                    </select>
                                    <div class="mbf-field-hint" id="addStaffRoleHint"></div>
                                </div>
                            </div>
                        </div>

                        <div class="mbf-section" data-role-fields="student" hidden>
                            <div class="mbf-section-eyebrow">Student details</div>
                            <div class="maintenance-form-grid">
                                <div>
                                    <label for="addUserStudentId">Student ID *</label>
                                    <input type="text" id="addUserStudentId" name="student_id_number" maxlength="80" required>
                                </div>
                                <div>
                                    <label for="addUserYear">Year Level *</label>
                                    <input type="number" id="addUserYear" name="year_level" min="1" max="12" value="1" required>
                                </div>
                                <div class="full">
                                    <label for="addUserCourse">Course *</label>
                                    <select id="addUserCourse" name="course_id" required>
                                        <option value="" selected disabled>Select a course</option>
                                        <?php foreach ($addUserCourses as $course): ?>
                                        <option value="<?= (int) $course['courseID'] ?>"><?= $esc($course['course_code'] . ' — ' . $course['course_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mbf-section" data-role-fields="merchant" hidden>
                            <div class="mbf-section-eyebrow">Business</div>
                            <div class="maintenance-form-grid">
                                <div>
                                    <label for="addUserBusiness">Business Name *</label>
                                    <input type="text" id="addUserBusiness" name="business_name" maxlength="255" required>
                                </div>
                                <div>
                                    <label for="addUserSex">Proprietor's Sex *</label>
                                    <select id="addUserSex" name="sex" required>
                                        <option value="" selected disabled>Select</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                    </select>
                                </div>
                                <div class="full">
                                    <label for="addUserStall">Stall</label>
                                    <select id="addUserStall" name="stall_id">
                                        <option value="">No stall yet</option>
                                        <?php foreach ($addUserStalls as $stall): ?>
                                        <option value="<?= $esc($stall['stall_id']) ?>"><?= $esc($stall['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mbf-section" data-role-fields="faculty" hidden>
                            <div class="mbf-section-eyebrow">Faculty details</div>
                            <div class="maintenance-form-grid">
                                <div>
                                    <label for="addUserEmployeeId">Employee ID *</label>
                                    <input type="text" id="addUserEmployeeId" name="employee_id" maxlength="80" required>
                                </div>
                                <div>
                                    <label for="addUserDepartment">Department *</label>
                                    <input type="text" id="addUserDepartment" name="department" maxlength="100" list="facultyDepartments" autocomplete="off" required>
                                </div>
                            </div>
                        </div>

                        <div class="mbf-section" data-role-fields="parent" hidden>
                            <div class="mbf-section-eyebrow">Child</div>
                            <div class="maintenance-form-grid">
                                <div class="full">
                                    <label for="addUserLinkStudent">Child's Student ID</label>
                                    <input type="text" id="addUserLinkStudent" name="link_student_id" maxlength="80" placeholder="Optional — links this guardian to the student">
                                </div>
                            </div>
                        </div>

                        <div class="mbf-section">
                            <div class="mbf-section-eyebrow">Personal details</div>
                            <div class="maintenance-form-grid">
                                <div>
                                    <label for="addStaffFirst">First Name *</label>
                                    <input type="text" id="addStaffFirst" name="first_name" maxlength="60" required>
                                </div>
                                <div>
                                    <label for="addStaffMiddle">Middle Name</label>
                                    <input type="text" id="addStaffMiddle" name="middle_name" maxlength="60">
                                </div>
                                <div>
                                    <label for="addStaffLast">Last Name *</label>
                                    <input type="text" id="addStaffLast" name="last_name" maxlength="60" required>
                                </div>
                                <div>
                                    <label for="addStaffSuffix">Suffix</label>
                                    <input type="text" id="addStaffSuffix" name="suffix" maxlength="20" placeholder="e.g. Jr., Sr., III">
                                </div>
                            </div>
                        </div>

                        <div class="mbf-section">
                            <div class="mbf-section-eyebrow">Contact</div>
                            <div class="maintenance-form-grid">
                                <div>
                                    <label for="addStaffEmail">Email *</label>
                                    <input type="email" id="addStaffEmail" name="email" maxlength="255" required>
                                </div>
                                <div>
                                    <label for="addStaffContact">Phone</label>
                                    <input type="tel" id="addStaffContact" name="contact_number" maxlength="15" inputmode="numeric" placeholder="e.g. 09171234567">
                                </div>
                            </div>
                        </div>

                        <div class="mbf-section">
                            <div class="mbf-section-eyebrow">Login credentials</div>
                            <div class="maintenance-form-grid">
                                <div class="full">
                                    <label for="addStaffPassword">Temporary Password *</label>
                                    <div class="mbf-input-group">
                                        <input type="password" id="addStaffPassword" name="password" minlength="8" autocomplete="new-password" required>
                                        <button type="button" class="maintenance-btn muted" id="addStaffPwToggle" aria-pressed="false" aria-controls="addStaffPassword">Show</button>
                                        <button type="button" class="maintenance-btn muted" id="addStaffPwGenerate">Generate</button>
                                    </div>
                                </div>
                            </div>
                            <div class="mbf-credentials-note mt-2">
                                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                <span>At least 8 characters. It's emailed to the user, who signs in with the email above and sets a new password on first login.</span>
                            </div>
                        </div>

                        <div id="addStaffMsg" role="status"></div>
                        <div class="maintenance-btn-row mt-2">
                            <button type="button" class="maintenance-btn muted" data-bs-dismiss="modal">Cancel</button>
                            <button class="maintenance-btn primary" type="submit" id="addStaffSubmit">Create User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Suspend Account Modal -->
    <div class="modal fade" id="suspendModal" tabindex="-1" aria-labelledby="suspendTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content gp-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="">
                            <i class="fa-solid fa-user-clock is-warning"></i>Suspend Account
                        </h5>
                        <p class="gp-modal-sub"><strong id="suspendUserName" style="color:var(--gp-ink)"></strong></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="suspendForm" class="merchant-bypass-form" autocomplete="off" novalidate>
                        <input type="hidden" name="action" value="suspend">
                        <input type="hidden" name="user_id" id="suspendUserId">
                        <input type="hidden" name="csrf_token" value="<?= $esc(gjc_csrf_token()) ?>">

                        <p style="font-size:13px;margin-bottom:6px">While suspended, they are:</p>
                        <ul style="font-size:13px;color:var(--gp-muted);padding-left:18px;margin-bottom:18px">
                            <li>signed out and blocked from logging in</li>
                            <li>unable to use their wallet or receive money</li>
                            <li>for a merchant owner: their stall stops selling and staff are locked out</li>
                        </ul>

                        <div class="mb-3">
                            <label for="suspendDuration">Duration</label>
                            <select name="duration" id="suspendDuration">
                                <option value="3">3 days</option>
                                <option value="7" selected>7 days</option>
                                <option value="30">30 days</option>
                                <option value="indefinite">Indefinite, until finance lifts it</option>
                            </select>
                        </div>

                        <div>
                            <label for="suspendReason">Reason *</label>
                            <textarea name="reason" id="suspendReason" rows="3" maxlength="255" required
                                data-error="Enter a reason for the suspension."></textarea>
                            <div class="mbf-field-hint">Shown to the user and recorded in the audit log.</div>
                        </div>

                        <div id="suspendMsg"></div>
                        <div class="maintenance-btn-row mt-3">
                            <button type="button" class="maintenance-btn muted" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="maintenance-btn warning" id="suspendSubmitBtn">Suspend Account</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Ban Account Modal -->
    <div class="modal fade" id="banModal" tabindex="-1" aria-labelledby="banTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content gp-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="">
                            <i class="fa-solid fa-ban is-danger"></i>Ban Account
                        </h5>
                        <p class="gp-modal-sub"><strong id="banUserName" style="color:var(--gp-ink)"></strong></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="banForm" class="merchant-bypass-form" autocomplete="off" novalidate>
                        <input type="hidden" name="action" value="ban">
                        <input type="hidden" name="user_id" id="banUserId">
                        <input type="hidden" name="csrf_token" value="<?= $esc(gjc_csrf_token()) ?>">

                        <p style="font-size:13px;margin-bottom:6px">A ban is <strong>permanent</strong>. It never expires; only a finance admin can lift it. Once banned, they are:</p>
                        <ul style="font-size:13px;color:var(--gp-muted);padding-left:18px;margin-bottom:18px">
                            <li>signed out and blocked from logging in</li>
                            <li>unable to use their wallet or receive money</li>
                            <li>for a merchant owner: their stall stops selling and staff are locked out</li>
                        </ul>

                        <!-- Shown only when the ban replaces a live suspension, so the
                             admin knows this escalates rather than starts a lockout. -->
                        <div id="banEscalationNote" class="maintenance-alert warning d-none" style="margin-top:0">
                            This account is currently suspended. Banning it replaces that suspension.
                        </div>

                        <div class="mb-3">
                            <label for="banReason">Reason *</label>
                            <textarea name="reason" id="banReason" rows="3" maxlength="255" required
                                data-error="Enter a reason for the ban."></textarea>
                            <div class="mbf-field-hint">Shown to the user and recorded in the audit log.</div>
                        </div>

                        <div>
                            <label for="banConfirm">Type BAN to confirm *</label>
                            <!-- Case-insensitive, matching the endpoint's strcasecmp check. -->
                            <input type="text" name="confirm" id="banConfirm" pattern="\s*[Bb][Aa][Nn]\s*"
                                data-error="Type BAN to confirm." autocomplete="off" spellcheck="false" required>
                        </div>

                        <div id="banMsg"></div>
                        <div class="maintenance-btn-row mt-3">
                            <button type="button" class="maintenance-btn muted" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="maintenance-btn danger" id="banSubmitBtn">Ban Account</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
    <?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=2"></script>

    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }

    // The filter-row search box drives the DataTable search.
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

    // ── Suspend / ban / lift (delegated; DataTables re-renders rows) ──
    const USERS_API = "<?= ADMIN_URL ?>/api/users.php";

    const resetModal = document.getElementById('resetPasswordModal');
    const resetForm = document.getElementById('resetPasswordForm');
    const resetSubmit = document.getElementById('resetPasswordSubmit');
    let resetPending = false;
    resetModal.addEventListener('hide.bs.modal', function (e) {
        if (resetPending) e.preventDefault();
    });
    function clearResetForm() {
        resetForm.reset();
        resetForm.classList.remove('d-none');
        resetSubmit.classList.remove('d-none');
        clearFieldErrors(resetForm);
        document.getElementById('resetPasswordMsg').textContent = '';
        document.getElementById('resetPasswordClose').textContent = 'Cancel';
    }
    resetModal.addEventListener('hidden.bs.modal', clearResetForm);
    resetModal.addEventListener('shown.bs.modal', () => document.getElementById('resetPasswordConfirm').focus());
    resetForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (resetPending) return;
        if (!formIsValid(resetForm)) return;
        resetPending = true;
        resetSubmit.disabled = true;
        resetSubmit.textContent = 'Resetting...';
        const msg = document.getElementById('resetPasswordMsg');
        msg.className = '';
        msg.textContent = '';
        try {
            const resp = await fetch(USERS_API, {method: 'POST', body: new FormData(resetForm), cache: 'no-store'});
            const data = await resp.json();
            msg.textContent = data.message;
            msg.className = 'maintenance-alert ' + (data.success ? 'success' : 'error');
            if (data.success) {
                resetForm.classList.add('d-none');
                resetSubmit.classList.add('d-none');
                document.getElementById('resetPasswordClose').textContent = 'Done';
            }
        } catch (err) {
            msg.className = 'maintenance-alert error';
            msg.textContent = 'Could not confirm the reset. Ask them to check their email before trying again.';
        } finally {
            resetPending = false;
            resetSubmit.disabled = false;
            resetSubmit.textContent = 'Reset Password';
        }
    });

    document.getElementById("usersTable").addEventListener("click", function (e) {
        const resetBtn = e.target.closest('.js-reset-password');
        if (resetBtn) {
            clearResetForm();
            document.getElementById('resetPasswordUserId').value = resetBtn.dataset.userId;
            document.getElementById('resetPasswordName').textContent = resetBtn.dataset.userName;
            document.getElementById('resetPasswordStudentId').textContent = resetBtn.dataset.studentId;
            bootstrap.Modal.getOrCreateInstance(resetModal).show();
            return;
        }
        const facultyBtn = e.target.closest('.js-edit-faculty');
        if (facultyBtn) {
            const form = document.getElementById('editFacultyForm');
            clearFieldErrors(form);
            document.getElementById('editFacultyMsg').innerHTML = '';
            document.getElementById('editFacultySubmit').disabled = false;
            document.getElementById('editFacultyUserId').value = facultyBtn.dataset.userId;
            document.getElementById('editFacultyName').textContent = facultyBtn.dataset.userName;
            document.getElementById('editFacultyEmployeeId').value = facultyBtn.dataset.employeeId;
            document.getElementById('editFacultyDepartment').value = facultyBtn.dataset.department;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('editFacultyModal')).show();
            return;
        }
        const suspendBtn = e.target.closest(".js-suspend");
        if (suspendBtn) {
            document.getElementById("suspendUserId").value = suspendBtn.dataset.userId;
            document.getElementById("suspendUserName").textContent = suspendBtn.dataset.userName;
            document.getElementById("suspendReason").value = "";
            document.getElementById("suspendMsg").innerHTML = "";
            clearFieldErrors(document.getElementById("suspendForm"));
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
            clearFieldErrors(document.getElementById("banForm"));
            document.getElementById("banEscalationNote")
                .classList.toggle("d-none", banBtn.dataset.suspended !== "1");
            bootstrap.Modal.getOrCreateInstance(document.getElementById("banModal")).show();
            return;
        }

        const liftBanBtn = e.target.closest(".js-lift-ban");
        if (liftBanBtn) {
            liftLockout(liftBanBtn, "lift_ban", "Lift this ban?");
            return;
        }

        const exitBtn = e.target.closest(".js-faculty-exit");
        if (exitBtn) {
            facultyExit(exitBtn);
            return;
        }
        const liftBtn = e.target.closest(".js-lift-suspension");
        if (liftBtn) {
            liftLockout(liftBtn, "lift_suspension", "Lift this suspension?");
        }
    });

    // Show only the selected role's fields; disabled fields are skipped by validation and FormData.
    document.getElementById("addStaffForm")?.addEventListener("change", function (e) {
        if (e.target.name !== "role") return;
        document.getElementById("addStaffRoleHint").textContent = e.target.selectedOptions[0].dataset.hint || "";
        this.querySelectorAll("[data-role-fields]").forEach((section) => {
            const on = section.dataset.roleFields === e.target.value;
            section.hidden = !on;
            section.querySelectorAll("input, select").forEach((field) => { field.disabled = !on; });
        });
    });
    document.querySelectorAll("#addStaffForm [data-role-fields] input, #addStaffForm [data-role-fields] select")
        .forEach((field) => { field.disabled = true; });

    const addPw = document.getElementById("addStaffPassword");
    const addPwToggle = document.getElementById("addStaffPwToggle");
    function showAddPw(show) {
        addPw.type = show ? "text" : "password";
        addPwToggle.textContent = show ? "Hide" : "Show";
        addPwToggle.setAttribute("aria-pressed", String(show));
    }
    addPwToggle?.addEventListener("click", () => showAddPw(addPw.type === "password"));
    document.getElementById("addStaffPwGenerate")?.addEventListener("click", () => {
        const chars = "ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789"; // no look-alikes (0/O, 1/l/I)
        const bytes = crypto.getRandomValues(new Uint32Array(12));
        addPw.value = Array.from(bytes, (n) => chars[n % chars.length]).join("");
        showAddPw(true);
        markFieldError(addPw);
    });

    // Show validation messages under each field instead of a popup bubble (used by Add User and Reset Password).
    function markFieldError(field) {
        const box = field.closest(".mbf-input-group") || field;
        let err = box.nextElementSibling;
        if (!err?.classList.contains("mbf-field-error")) {
            err = document.createElement("div");
            err.className = "mbf-field-error";
            err.id = "fieldErr_" + field.name;
            box.after(err);
        }
        const bad = !field.validity.valid;
        err.textContent = bad ? (field.dataset.error || field.validationMessage) : "";
        box.classList.toggle("is-invalid", bad);
        field.setAttribute("aria-invalid", String(bad));
        const described = field.getAttribute("aria-describedby") || "";
        if (!described.includes(err.id)) field.setAttribute("aria-describedby", (described + " " + err.id).trim());
    }
    function clearFieldErrors(form) {
        form.querySelectorAll(".mbf-field-error").forEach((err) => err.remove());
        form.querySelectorAll(".is-invalid").forEach((el) => el.classList.remove("is-invalid"));
        form.querySelectorAll("[aria-invalid]").forEach((f) => f.removeAttribute("aria-invalid"));
    }
    // Marks every invalid field and focuses the first; returns false if the form can't be sent yet.
    function formIsValid(form) {
        if (form.checkValidity()) return true;
        form.querySelectorAll("input, select, textarea").forEach((f) => { if (f.willValidate) markFieldError(f); });
        form.querySelector("input:invalid, select:invalid, textarea:invalid")?.focus();
        return false;
    }
    // Re-check a flagged field as it's corrected, in any of the modal forms.
    document.addEventListener("input", (e) => {
        if (e.target.getAttribute?.("aria-invalid") === "true") markFieldError(e.target);
    });

    document.getElementById("editFacultyForm").addEventListener("submit", async function (e) {
        e.preventDefault();
        const btn = document.getElementById("editFacultySubmit");
        const msg = document.getElementById("editFacultyMsg");
        if (!formIsValid(this)) return;
        btn.disabled = true;
        btn.textContent = "Saving...";
        try {
            const resp = await fetch(USERS_API, { method: "POST", body: new FormData(this), cache: "no-store" });
            const data = await resp.json();
            msg.innerHTML = '<div class="maintenance-alert"></div>';
            msg.firstChild.classList.add(data.success ? "success" : "error");
            msg.firstChild.textContent = data.message;
            if (data.success) {
                btn.textContent = "Save";
                setTimeout(() => location.reload(), 900);
                return;
            }
        } catch (err) {
            msg.innerHTML = '<div class="maintenance-alert error">Could not reach the server. Try again.</div>';
        }
        btn.disabled = false;
        btn.textContent = "Save";
    });

    document.getElementById("addStaffForm")?.addEventListener("submit", async function (e) {
        e.preventDefault();
        const btn = document.getElementById("addStaffSubmit");
        const msg = document.getElementById("addStaffMsg");
        if (!formIsValid(this)) return;
        btn.disabled = true;
        btn.textContent = "Creating...";
        try {
            const resp = await fetch(USERS_API, { method: "POST", body: new FormData(this), cache: "no-store" });
            const data = await resp.json();
            msg.innerHTML = '<div class="maintenance-alert"></div>';
            msg.firstChild.classList.add(data.success ? "success" : "error");
            msg.firstChild.textContent = data.message;
            if (data.success) {
                setTimeout(() => location.reload(), 2500);
                return;
            }
        } catch (err) {
            msg.innerHTML = '<div class="maintenance-alert error">Could not reach the server. Try again.</div>';
        }
        btn.disabled = false;
        btn.textContent = "Create User";
    });

    document.getElementById("suspendForm").addEventListener("submit", async function (e) {
        e.preventDefault();
        const btn = document.getElementById("suspendSubmitBtn");
        const msg = document.getElementById("suspendMsg");
        if (!formIsValid(this)) return;
        btn.disabled = true;
        btn.textContent = "Suspending...";
        try {
            const resp = await fetch(USERS_API, { method: "POST", body: new FormData(this) });
            const data = await resp.json();
            if (data.success) {
                location.reload();
                return;
            }
            msg.innerHTML = '<div class="maintenance-alert error"></div>';
            msg.firstChild.textContent = data.message;
        } catch (err) {
            msg.innerHTML = '<div class="maintenance-alert error">Could not reach the server. Try again.</div>';
        }
        btn.disabled = false;
        btn.textContent = "Suspend Account";
    });

    document.getElementById("banForm").addEventListener("submit", async function (e) {
        e.preventDefault();
        const btn = document.getElementById("banSubmitBtn");
        const msg = document.getElementById("banMsg");

        // Client-side check; the endpoint enforces the BAN confirmation too.
        if (!formIsValid(this)) return;

        btn.disabled = true;
        btn.textContent = "Banning...";
        try {
            const resp = await fetch(USERS_API, { method: "POST", body: new FormData(this) });
            const data = await resp.json();
            if (data.success) {
                location.reload();
                return;
            }
            msg.innerHTML = '<div class="maintenance-alert error"></div>';
            msg.firstChild.textContent = data.message;
        } catch (err) {
            msg.innerHTML = '<div class="maintenance-alert error">Could not reach the server. Try again.</div>';
        }
        btn.disabled = false;
        btn.textContent = "Ban Account";
    });

    // Shared by lift suspension and lift ban.
    async function facultyExit(btn) {
        const offboard = btn.dataset.action === 'offboard_faculty';
        const ok = await gpConfirm(offboard ? {
            title: 'Offboard this faculty member?',
            body: 'Use this when they have left the school. Their wallet closes like a graduate\'s: no purchases, top-ups or incoming transfers.',
            detail: [['Account', btn.dataset.userName]],
            note: 'They can still sign in to send their remaining balance to someone, or Finance pays it out. You can reinstate them later.',
            confirmLabel: 'Offboard',
            danger: true,
            typeToConfirm: 'OFFBOARD',
        } : {
            title: 'Reinstate this faculty member?',
            body: 'Their wallet reopens for purchases, top-ups and transfers immediately.',
            detail: [['Account', btn.dataset.userName]],
            confirmLabel: 'Reinstate',
        });
        if (!ok) return;
        if (!gpBusy(btn, offboard ? 'Offboarding…' : 'Reinstating…')) return;
        const data = await gpFetchJson(USERS_API, { action: btn.dataset.action, user_id: btn.dataset.userId });
        if (data.success) {
            gpToast(data.message);
            setTimeout(() => location.reload(), 900);
            return;
        }
        gpToast(data.message || 'The account could not be updated.', false);
        gpBusyDone(btn);
    }

    async function liftLockout(btn, action, title) {
        const ok = await gpConfirm({
            title: title,
            body: 'They will be able to sign in and transact again immediately.',
            detail: [['Account', btn.dataset.userName]],
            confirmLabel: 'Lift now',
        });
        if (!ok) return;

        if (!gpBusy(btn, 'Lifting…')) return;

        const data = await gpFetchJson(USERS_API, {
            action: action,
            user_id: btn.dataset.userId,
        });

        if (data.success) {
            gpToast(action === 'lift_ban' ? 'Ban lifted.' : 'Suspension lifted.');
            setTimeout(() => location.reload(), 900);
            return;
        }
        gpToast(data.message || 'The account could not be updated.', false);
        gpBusyDone(btn);
    }
    </script>

</body>

</html>
