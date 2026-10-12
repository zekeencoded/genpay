<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);
gjc_ensure_school_year_schema($db);
gjc_ensure_enrollment_schema($db);

$currentUser = gjc_current_user($db);

$schoolYears = $db->query(
    "SELECT id, school_year_name, start_date, end_date, is_active, created_at
       FROM school_years
      ORDER BY school_year_name DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$activeYear = null;
$inactiveYears = [];
foreach ($schoolYears as $year) {
    if ((int) $year['is_active'] === 1) {
        $activeYear = $year;
    } else {
        $inactiveYears[] = $year;
    }
}

// Missing enrollment records count as not enrolled for the active year.
$enrolledCount = 0;
$notEnrolledCount = 0;
if ($activeYear) {
    $countStmt = $db->prepare(
        "SELECT SUM(se.status = 'enrolled') AS enrolled,
                SUM(se.status IS NULL OR se.status <> 'enrolled') AS not_enrolled
         FROM student_info si JOIN users u ON u.userID = si.userID AND u.roleID = 1
         LEFT JOIN student_enrollments se ON se.student_user_id = si.userID AND se.school_year_id = ?"
    );
    $countStmt->execute([(int) $activeYear['id']]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $enrolledCount = (int) ($counts['enrolled'] ?? 0);
    $notEnrolledCount = (int) ($counts['not_enrolled'] ?? 0);
}

// Rosters already staged, keyed by the year they were uploaded for. Uploading
// again replaces the year's staging, so there is at most one batch per year.
$stagedByYear = [];
foreach ($db->query(
    "SELECT school_year_id, COUNT(*) AS row_count,
            SUM(match_status = 'matched') AS matched_count,
            MAX(batch_id) AS batch_id, MAX(uploaded_at) AS uploaded_at
       FROM enrollment_roster_staging
      GROUP BY school_year_id"
)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stagedByYear[(int) $row['school_year_id']] = $row;
}

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$currentPage = 'school_years';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>School Years | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
    <style>
        .sy-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .sy-stat-card { background: #fff; border-radius: 16px; padding: 18px 20px; box-shadow: var(--gjc-shadow-sm, 0 1px 4px rgba(0,0,0,.06)); }
        .sy-stat-card span { font-size: 12px; color: var(--gp-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
        .sy-stat-card h2 { font-size: 26px; font-weight: 800; color: #111; margin: 6px 0 0; }
        .sy-panel { background: #fff; border-radius: 16px; padding: 22px 24px; box-shadow: var(--gjc-shadow-sm, 0 1px 4px rgba(0,0,0,.06)); margin-bottom: 20px; }
        .sy-panel h3 { font-size: 16px; font-weight: 700; color: #111; margin: 0 0 4px; }
        .sy-panel p.sy-sub { font-size: 13px; color: var(--gp-muted); margin: 0 0 16px; }
        .sy-badge { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 50px; }
        .sy-badge.active { background: var(--gp-success-bg, #ecfdf3); color: var(--gp-success, #16a34a); }
        .sy-badge.inactive { background: var(--gjc-soft-2); color: var(--gp-muted); }
        .sy-badge.staged { background: var(--gp-warning-bg); color: var(--gp-warning); }
        [hidden] { display: none !important; }
        .sy-form-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .sy-field { display: flex; flex-direction: column; gap: 4px; }
        .sy-field label { font-size: 12px; font-weight: 600; color: var(--gjc-slate); }
        .sy-field input, .sy-field select { border: 1.5px solid var(--gp-line); border-radius: 10px; padding: 9px 12px; font-size: 14px; min-width: 180px; }
        .sy-btn { border: none; border-radius: 10px; padding: 10px 20px; font-weight: 600; font-size: 14px; cursor: pointer; }
        .sy-btn.primary { background: var(--gp-success, #16a34a); color: #fff; }
        .sy-btn.ghost { background: var(--gjc-soft-2); color: var(--gjc-slate); }
        .sy-btn:disabled { opacity: .6; cursor: not-allowed; }
        .sy-msg { font-size: 13px; margin-top: 10px; min-height: 18px; }
        .sy-msg.ok { color: var(--gp-success, #16a34a); }
        .sy-msg.err { color: var(--gp-danger, #dc2626); }
        .sy-lookup-card { background: var(--gp-row-hover); border-radius: 12px; padding: 12px 16px; margin-top: 10px; font-size: 13px; display: none; }
        .sy-unmatched { font-size: 13px; color: var(--gp-warning); margin-top: 10px; word-break: break-word; }
        .sy-add-year > summary { cursor: pointer; font-size: 14px; font-weight: 700; }
        .sy-add-year[open] > summary { margin-bottom: 12px; }
        @media (max-width: 600px) {
            .sy-panel { padding: 18px; }
            .sy-field { width: 100%; }
            .sy-field input, .sy-field select { min-width: 0; width: 100%; }
        }
        .sy-note { font-size: 12px; color: var(--gp-muted); margin-top: 10px; }
    </style>
</head>
<body class="gp-theme">
    <div class="admin-layout">
        <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

        <main class="admin-main">
            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div>
                    <h1>School Years</h1>
                    <p>Manage enrollment and wallet access by school year.</p>
                </div>
                <div class="admin-user">
                    <span><?= $e($currentUser['name'] ?? 'Admin') ?></span>
                    <?php require __DIR__ . '/../includes/partials/avatar_admin.php'; ?>
                </div>
            </header>

            <!-- 1. Active year summary -->
            <section class="sy-stats">
                <div class="sy-stat-card">
                    <span>Active School Year</span>
                    <h2><?= $activeYear ? $e($activeYear['school_year_name']) : 'None Active' ?></h2>
                </div>
                <div class="sy-stat-card">
                    <span>Enrolled</span>
                    <h2><?= $enrolledCount ?></h2>
                </div>
                <div class="sy-stat-card">
                    <span>Not Enrolled / Exited</span>
                    <h2><?= $notEnrolledCount ?></h2>
                </div>
            </section>

            <!-- 2. School years table -->
            <section class="sy-panel">
                <h3>School Years</h3>
                <p class="sy-sub">All school years on record. Only one can be active at a time.</p>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>School Year</th>
                                <th>Status</th>
                                <th>Roster</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="syYearsBody">
                            <?php if (!$schoolYears): ?>
                            <tr><td colspan="4" class="text-center py-3">No school years yet. Add one below.</td></tr>
                            <?php else: foreach ($schoolYears as $year): ?>
                            <?php $staged = $stagedByYear[(int) $year['id']] ?? null; ?>
                            <tr>
                                <td><strong><?= $e($year['school_year_name']) ?></strong></td>
                                <td>
                                    <span class="sy-badge <?= $year['is_active'] ? 'active' : 'inactive' ?>">
                                        <?= $year['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($year['is_active']): ?>
                                    <span style="font-size:12px;color:var(--gp-subtle)">—</span>
                                    <?php elseif ($staged): ?>
                                    <span class="sy-badge staged"><?= (int) $staged['row_count'] ?> staged</span>
                                    <?php else: ?>
                                    <span style="font-size:12px;color:var(--gp-subtle)">No roster</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$year['is_active']): ?>
                                    <button type="button" class="sy-btn ghost" onclick="syStartRollover(<?= (int) $year['id'] ?>)">
                                        Activate Year
                                    </button>
                                    <?php else: ?>
                                    <span style="font-size:12px;color:var(--gp-subtle)">Currently active</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- 3. Start a new school year -->
            <details class="sy-panel sy-add-year">
                <summary>Add School Year</summary>
                <p class="sy-sub">Add the year, upload its roster, then activate it.</p>

                <form id="syCreateForm" class="sy-form-row" onsubmit="return syCreateYear(event)">
                    <div class="sy-field">
                        <label for="syYearName">School Year (YYYY-YYYY)</label>
                        <input type="text" id="syYearName" name="school_year_name" placeholder="2026-2027" pattern="\d{4}-\d{4}" required>
                    </div>
                    <button type="submit" class="sy-btn primary">Create School Year</button>
                </form>
                <div id="syCreateMsg" class="sy-msg"></div>
            </details>

            <!-- 4. Upload roster -->
            <section class="sy-panel">
                <h3>Upload Roster</h3>
                <p class="sy-sub">
                    Upload a CSV with <code>student_id_number</code>. Optional columns: <code>yr_lvl</code> and
                    <code>status</code> (defaults to enrolled). Students missing from the roster become Not Enrolled.
                    Enrolled wallets can transact; all other statuses freeze spending, transfers, and cash-in.
                    Remaining balances can still be withdrawn at the Finance office.
                </p>

                <?php if (!$inactiveYears): ?>
                <p class="sy-sub">Create an inactive school year first — a roster is always staged into the year you are about to open.</p>
                <?php else: ?>
                <form id="syRosterForm" class="sy-form-row" onsubmit="return syUploadRoster(event)">
                    <div class="sy-field">
                        <label for="syRosterYear">School Year</label>
                        <select name="school_year_id" id="syRosterYear" required>
                            <?php foreach ($inactiveYears as $year): ?>
                            <option value="<?= (int) $year['id'] ?>"><?= $e($year['school_year_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sy-field">
                        <label for="syRosterFile">Roster CSV</label>
                        <input type="file" name="roster_csv" id="syRosterFile" accept=".csv,text/csv" required>
                    </div>
                    <button type="submit" class="sy-btn primary" id="syRosterBtn">Upload Roster</button>
                </form>
                <div id="syRosterMsg" class="sy-msg"></div>
                <div id="syRosterUnmatched" class="sy-unmatched"></div>
                <?php endif; ?>
            </section>

            <section class="sy-panel">
                <h3>Student Status</h3>
                <p class="sy-sub">Update a student during the active school year. Enrolled allows wallet use; other statuses freeze it. Balances remain available for withdrawal through Finance.</p>
                <?php if (!$activeYear): ?>
                <p class="sy-sub">Activate a school year first.</p>
                <?php else: ?>
                <form class="sy-form-row" onsubmit="syLookupStudent(event); return false;">
                    <div class="sy-field">
                        <label for="syStudentNumber">Student ID</label>
                        <input type="text" id="syStudentNumber" placeholder="School-issued student ID" required oninput="syClearStudent()">
                    </div>
                    <button type="submit" class="sy-btn ghost">Find Student</button>
                </form>
                <div class="sy-lookup-card" id="syLookupCard"></div>
                <div id="syStatusControls" class="sy-form-row" hidden style="margin-top:12px;">
                    <div class="sy-field">
                        <label for="syStudentStatus">Enrollment Status</label>
                        <select id="syStudentStatus">
                            <option value="enrolled">Enrolled</option>
                            <option value="not_enrolled">Not Enrolled</option>
                            <option value="transferred">Transferred</option>
                            <option value="dropped">Dropped</option>
                            <option value="graduated">Graduated</option>
                        </select>
                    </div>
                    <button type="button" id="sySaveStatus" class="sy-btn primary" onclick="sySaveStudentStatus()">Save Status</button>
                </div>
                <div id="syStatusMsg" class="sy-msg" role="status"></div>
                <?php endif; ?>
            </section>

        </main>
    </div>

    <!-- 6. Rollover confirm modal -->
    <div class="modal fade" id="syRolloverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header">
                    <h5 class="modal-title">Activate School Year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="syRolloverBody" style="font-size:14px;">
                    Loading…
                </div>
                <div class="modal-footer">
                    <button type="button" class="sy-btn ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="sy-btn primary" id="syRolloverConfirmBtn" disabled onclick="syConfirmRollover()">Activate Year</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }

    // Keep the .php — the extension-stripping redirect in .htaccess is GET-only,
    // so a POST to the extensionless URL would lose its body.
    const SY_API = "<?= ADMIN_URL ?>/api/school_years.php";

    async function syPost(action, params) {
        const form = new FormData();
        form.append("action", action);
        Object.entries(params || {}).forEach(([k, v]) => form.append(k, v));
        return gpFetchJson(SY_API, form);
    }

    function syShowMsg(elId, message, ok) {
        const el = document.getElementById(elId);
        el.textContent = message;
        el.className = "sy-msg " + (ok ? "ok" : "err");
    }

    function syEsc(value) {
        const div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    }

    function syPeso(value) {
        return gpMoney(value);
    }

    // ── Create school year ──────────────────────────────────────────────
    async function syCreateYear(event) {
        event.preventDefault();
        const form = document.getElementById("syCreateForm");
        const data = await syPost("create_year", {
            school_year_name: form.school_year_name.value.trim(),
        });
        syShowMsg("syCreateMsg", data.message, data.success);
        if (data.success) {
            setTimeout(() => window.location.reload(), 800);
        }
        return false;
    }

    // ── Upload roster ────────────────────────────────────────────────────
    async function syUploadRoster(event) {
        event.preventDefault();
        const btn = document.getElementById("syRosterBtn");
        const fileInput = document.getElementById("syRosterFile");
        if (!fileInput.files.length) {
            syShowMsg("syRosterMsg", "Choose a CSV file first.", false);
            return false;
        }

        const form = new FormData();
        form.append("action", "upload_roster");
        form.append("school_year_id", document.getElementById("syRosterYear").value);
        form.append("roster_csv", fileInput.files[0]);

        btn.disabled = true;
        btn.textContent = "Uploading…";
        let data;
        try {
            const res = await fetch(SY_API, { method: "POST", body: form });
            data = await res.json();
        } finally {
            btn.disabled = false;
            btn.textContent = "Upload Roster";
        }

        syShowMsg("syRosterMsg", data.message, data.success);

        const unmatchedEl = document.getElementById("syRosterUnmatched");
        unmatchedEl.innerHTML = "";
        if (data.success) {
            const notes = [];
            if (data.duplicate_count) {
                notes.push(`${data.duplicate_count} duplicate student number(s) in the file were skipped.`);
            }
            if ((data.rejected_rows || []).length) {
                notes.push(`${data.rejected_rows.length} row(s) rejected: ` +
                    data.rejected_rows.map(r => `row ${r.row} — ${syEsc(r.reason)}`).join("; "));
            }
            if ((data.unmatched_numbers || []).length) {
                notes.push("No GenPay account for these student numbers, so they were not staged as matched: " +
                    data.unmatched_numbers.map(syEsc).join(", "));
            }
            unmatchedEl.innerHTML = notes.join("<br>");

            await syStartRollover(data.school_year_id);
        }
        return false;
    }

    // ── Rollover ─────────────────────────────────────────────────────────
    let syRolloverModal;
    let syPendingRollover = null;

    async function syStartRollover(newYearId) {
        syPendingRollover = null;
        document.getElementById("syRolloverConfirmBtn").disabled = true;
        document.getElementById("syRolloverBody").textContent = "Loading…";
        syRolloverModal = syRolloverModal || new bootstrap.Modal(document.getElementById("syRolloverModal"));
        syRolloverModal.show();

        const data = await syPost("preview_rollover", { new_school_year_id: newYearId });
        if (!data.success) {
            document.getElementById("syRolloverBody").textContent = data.message || "Could not load rollover preview.";
            return;
        }

        if (!data.has_roster) {
            document.getElementById("syRolloverBody").innerHTML = `
                <p><strong>No matching roster students for ${syEsc(data.new_school_year_name)}.</strong></p>
                <p style="color:var(--gp-muted);font-size:13px;">
                    Rolling over without one would leave every student unenrolled and frozen. Upload the registrar
                    roster and review the changes first.
                </p>
            `;
            return;
        }

        syPendingRollover = data;
        document.getElementById("syRolloverBody").innerHTML = `
            <p>Closing <strong>${syEsc(data.old_school_year_name || "No active year")}</strong> &rarr;
               activating <strong>${syEsc(data.new_school_year_name)}</strong></p>
            <ul style="padding-left:18px;margin:0 0 8px;">
                <li><strong>${data.enrolled_count}</strong> student(s) enrolled for the new year
                    (${data.returning_count} returning, ${data.new_count} new).</li>
                <li><strong>${data.frozen_count}</strong> student wallet(s) will be frozen (${data.not_returning_count} missing from the roster).</li>
                <li><strong>${data.frozen_with_balance}</strong> of those still hold a balance
                    (${syPeso(data.frozen_balance_total)} in total), which stays available for withdrawal at Finance.</li>
                ${data.unmatched_count ? `<li>${data.unmatched_count} roster row(s) have no GenPay account and are skipped.</li>` : ""}
            </ul>
            <p style="color:var(--gp-muted);font-size:13px;">Wallet balances are kept. Students absent from the roster become Not Enrolled; withdrawals at the Finance office remain available.</p>
        `;
        document.getElementById("syRolloverConfirmBtn").disabled = false;
    }

    async function syConfirmRollover() {
        if (!syPendingRollover) return;
        const btn = document.getElementById("syRolloverConfirmBtn");
        btn.disabled = true;
        btn.textContent = "Rolling over…";

        const data = await syPost("rollover", {
            old_school_year_id: syPendingRollover.old_school_year_id || 0,
            new_school_year_id: syPendingRollover.new_school_year_id,
        });

        if (data.success) {
            gpToast(data.message || 'School year rolled over.');
            setTimeout(() => window.location.reload(), 1200);
            return;
        }
        gpToast(data.message || 'The rollover did not complete.', false);
        btn.disabled = false;
        btn.textContent = "Activate Year";
    }

    let syLookedUpStudent = null;
    let syLookupVersion = 0;
    function syClearStudent() {
        syLookupVersion++;
        syLookedUpStudent = null;
        document.getElementById("syStatusControls").hidden = true;
        document.getElementById("syLookupCard").style.display = "none";
        syShowMsg("syStatusMsg", "", true);
    }
    async function syLookupStudent(event) {
        if (event) event.preventDefault();
        syClearStudent();
        const version = syLookupVersion;
        const data = await syPost("student_lookup", {
            student_id_number: document.getElementById("syStudentNumber").value.trim(),
        });
        if (version !== syLookupVersion) return;
        if (!data.success) {
            syShowMsg("syStatusMsg", data.message, false);
            return;
        }
        syLookedUpStudent = data.student;
        const card = document.getElementById("syLookupCard");
        card.style.display = "block";
        const labels = {enrolled: "Enrolled", not_enrolled: "Not Enrolled", transferred: "Transferred", dropped: "Dropped", graduated: "Graduated"};
        card.innerHTML = `<strong>${syEsc(data.student.name)}</strong> (${syEsc(data.student.studentID)})<br>
            Balance: ${syPeso(data.student.balance)} &middot; Status: ${syEsc(labels[data.student.enrollment_status] || "Not Enrolled")} &middot;
            Wallet: ${data.student.wallet_state === "active" && !data.student.is_frozen ? "Active" : "Frozen"}`;
        document.getElementById("syStudentStatus").value = labels[data.student.enrollment_status] ? data.student.enrollment_status : "not_enrolled";
        document.getElementById("syStatusControls").hidden = false;
    }
    async function sySaveStudentStatus() {
        if (!syLookedUpStudent) return;
        const student = syLookedUpStudent;
        const select = document.getElementById("syStudentStatus");
        const status = select.value;
        if (!await gpConfirm({
            title: "Update student status?",
            body: status === "enrolled" ? "Enrollment allows wallet use, subject to any parent or account restrictions." : "Spending, transfers, and cash-in will stop. The remaining balance can still be withdrawn through Finance.",
            detail: [["Student", student.name], ["Status", select.options[select.selectedIndex].text]],
            confirmLabel: "Save status",
        })) return;
        const btn = document.getElementById("sySaveStatus");
        btn.disabled = true;
        try {
            const data = await syPost("set_student_status", {
                student_user_id: student.student_user_id,
                school_year_id: <?= (int) ($activeYear['id'] ?? 0) ?>,
                status,
            });
            if (data.success) {
                await syLookupStudent();
                setTimeout(() => window.location.reload(), 1000);
            }
            syShowMsg("syStatusMsg", data.message, data.success);
        } finally { btn.disabled = false; }
    }
    </script>
</body>
</html>
