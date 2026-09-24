<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);
gjc_ensure_school_year_schema($db);
gjc_ensure_enrollment_schema($db);

$currentUser = gjc_current_user($db);
$isSuperAdmin = gjc_sub_role() === 'super_admin';

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

// Active-year standing. Enrollment is explicit now, so these are counts of
// real student_enrollments rows — not "everyone who hasn't graduated".
$enrolledCount = 0;
$notEnrolledCount = 0;
$graduatedCount = 0;
if ($activeYear) {
    $countStmt = $db->prepare(
        "SELECT
            SUM(status = 'enrolled')  AS enrolled,
            SUM(status = 'graduated') AS graduated,
            SUM(status IN ('not_enrolled','on_leave','dropped','transferred')) AS not_enrolled
           FROM student_enrollments WHERE school_year_id = ?"
    );
    $countStmt->execute([(int) $activeYear['id']]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $enrolledCount    = (int) ($counts['enrolled'] ?? 0);
    $graduatedCount   = (int) ($counts['graduated'] ?? 0);
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

// Year levels for the bulk-graduate control, from both the per-year enrollment
// rows and the legacy student_info column that is still in place.
$yrLevels = $db->query(
    "SELECT DISTINCT yr_lvl FROM (
        SELECT yr_lvl FROM student_enrollments WHERE yr_lvl IS NOT NULL AND yr_lvl <> ''
        UNION
        SELECT yr_lvl FROM student_info WHERE yr_lvl IS NOT NULL AND yr_lvl <> ''
     ) AS levels ORDER BY yr_lvl ASC"
)->fetchAll(PDO::FETCH_COLUMN);

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
        .sy-result-list { margin-top: 12px; font-size: 13px; }
        .sy-result-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid var(--gjc-soft-2); }
        .sy-lookup-card { background: var(--gp-row-hover); border-radius: 12px; padding: 12px 16px; margin-top: 10px; font-size: 13px; display: none; }
        .sy-lookup-card strong { font-size: 14px; }

        /* ── Roster review ──────────────────────────────────────────────── */
        .sy-buckets { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin: 4px 0 18px; }
        .sy-bucket { background: var(--gp-row-hover); border: 1px solid var(--gp-line); border-radius: 14px; padding: 14px 16px; }
        .sy-bucket span { display: block; font-size: 11px; font-weight: 700; color: var(--gp-muted); text-transform: uppercase; letter-spacing: .4px; }
        .sy-bucket strong { display: block; font-size: 24px; font-weight: 800; color: #111; margin-top: 4px; }
        .sy-bucket small { display: block; font-size: 12px; color: var(--gp-muted); margin-top: 2px; }
        .sy-bucket.is-warn { background: var(--gp-warning-bg); border-color: var(--gp-warning); }
        .sy-bucket.is-warn span, .sy-bucket.is-warn small { color: var(--gp-warning); }
        .sy-details { margin-bottom: 16px; }
        .sy-details > summary { cursor: pointer; font-size: 13px; font-weight: 600; color: var(--gjc-slate); padding: 6px 0; }
        .sy-details .sy-name-list { max-height: 260px; overflow-y: auto; margin-top: 8px; font-size: 13px; }
        .sy-bulk-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 14px; background: var(--gp-row-hover); border-radius: 12px; margin-bottom: 12px; }
        .sy-bulk-bar label { font-size: 12px; font-weight: 600; color: var(--gjc-slate); margin: 0; }
        .sy-bulk-bar select { border: 1.5px solid var(--gp-line); border-radius: 10px; padding: 8px 12px; font-size: 13px; }
        .sy-nr-table select { border: 1.5px solid var(--gp-line); border-radius: 8px; padding: 6px 10px; font-size: 13px; min-width: 150px; }
        .sy-nr-table td.sy-bal { font-weight: 700; }
        .sy-nr-table td.sy-bal.has-money { color: var(--gp-warning); }
        .sy-unmatched { font-size: 13px; color: var(--gp-warning); margin-top: 10px; word-break: break-word; }
        .sy-overrides > summary { cursor: pointer; font-size: 16px; font-weight: 700; color: #111; padding: 4px 0; }
        .sy-overrides .sy-panel { box-shadow: none; border: 1px solid var(--gp-line); margin-top: 16px; }
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
                    <p>Upload the registrar roster, review who is returning, then roll the year over.</p>
                </div>
                <div class="admin-user">
                    <span><?= $e($currentUser['name'] ?? 'Admin') ?></span>
                    <div class="avatar"><i class="fa-solid fa-user-tie"></i></div>
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
                    <span>Not Enrolled</span>
                    <h2><?= $notEnrolledCount ?></h2>
                </div>
                <div class="sy-stat-card">
                    <span>Graduated</span>
                    <h2><?= $graduatedCount ?></h2>
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
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                                <th>Roster</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="syYearsBody">
                            <?php if (!$schoolYears): ?>
                            <tr><td colspan="6" class="text-center py-3">No school years yet. Create one below.</td></tr>
                            <?php else: foreach ($schoolYears as $year): ?>
                            <?php $staged = $stagedByYear[(int) $year['id']] ?? null; ?>
                            <tr>
                                <td><strong><?= $e($year['school_year_name']) ?></strong></td>
                                <td><?= $e($year['start_date'] ?: '—') ?></td>
                                <td><?= $e($year['end_date'] ?: '—') ?></td>
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
                                    <button type="button" class="sy-btn ghost" onclick="syStartRollover(<?= (int) $year['id'] ?>, '<?= $e($year['school_year_name']) ?>')">
                                        Roll Over Into This Year
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
            <section class="sy-panel">
                <h3>Start a New School Year</h3>
                <p class="sy-sub">Creates an inactive school year. Upload its roster next, then roll over into it.</p>

                <form id="syCreateForm" class="sy-form-row" onsubmit="return syCreateYear(event)">
                    <div class="sy-field">
                        <label>School Year (YYYY-YYYY)</label>
                        <input type="text" name="school_year_name" placeholder="2026-2027" pattern="\d{4}-\d{4}" required>
                    </div>
                    <div class="sy-field">
                        <label>Start Date (optional)</label>
                        <input type="date" name="start_date">
                    </div>
                    <div class="sy-field">
                        <label>End Date (optional)</label>
                        <input type="date" name="end_date">
                    </div>
                    <button type="submit" class="sy-btn primary">Create School Year</button>
                </form>
                <div id="syCreateMsg" class="sy-msg"></div>
            </section>

            <!-- 4. Upload roster -->
            <section class="sy-panel">
                <h3>Upload Roster</h3>
                <p class="sy-sub">
                    CSV from the registrar, with a <code>student_id_number</code> column plus optional
                    <code>yr_lvl</code> and <code>status</code>. Students are matched on their school-issued ID only,
                    never by name. Uploading again replaces whatever was staged for that year.
                </p>

                <?php if (!$inactiveYears): ?>
                <p class="sy-sub">Create an inactive school year first — a roster is always staged into the year you are about to open.</p>
                <?php else: ?>
                <form id="syRosterForm" class="sy-form-row" onsubmit="return syUploadRoster(event)">
                    <div class="sy-field">
                        <label>Target School Year</label>
                        <select name="school_year_id" id="syRosterYear" required>
                            <?php foreach ($inactiveYears as $year): ?>
                            <option value="<?= (int) $year['id'] ?>"><?= $e($year['school_year_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sy-field">
                        <label>Roster CSV</label>
                        <input type="file" name="roster_csv" id="syRosterFile" accept=".csv,text/csv" required>
                    </div>
                    <button type="submit" class="sy-btn primary" id="syRosterBtn">Upload Roster</button>
                </form>
                <div id="syRosterMsg" class="sy-msg"></div>
                <div id="syRosterUnmatched" class="sy-unmatched"></div>
                <?php endif; ?>
            </section>

            <!-- 5. Review changes -->
            <section class="sy-panel">
                <h3>Review Changes</h3>
                <p class="sy-sub">
                    The staged roster against this year's enrolled students. The not-returning list is the one that
                    matters — those wallets get frozen, so decide what happened to each student before rolling over.
                </p>

                <?php if (!$inactiveYears): ?>
                <p class="sy-sub">Nothing to review yet.</p>
                <?php else: ?>
                <div class="sy-form-row">
                    <div class="sy-field">
                        <label>School Year</label>
                        <select id="syDiffYear">
                            <?php foreach ($inactiveYears as $year): ?>
                            <option value="<?= (int) $year['id'] ?>" <?= isset($stagedByYear[(int) $year['id']]) ? 'data-staged="1"' : '' ?>>
                                <?= $e($year['school_year_name']) ?><?= isset($stagedByYear[(int) $year['id']]) ? ' — roster staged' : ' — no roster' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="sy-btn ghost" onclick="syLoadDiff()">Load Review</button>
                </div>
                <div id="syDiffMsg" class="sy-msg"></div>
                <div id="syDiffBody" style="display:none;margin-top:14px;">
                    <div class="sy-buckets" id="syDiffBuckets"></div>
                    <div id="syDiffUnmatched" class="sy-unmatched"></div>

                    <details class="sy-details" id="syReturningWrap">
                        <summary>Returning students (<span id="syReturningCount">0</span>)</summary>
                        <div class="sy-name-list" id="syReturningList"></div>
                    </details>

                    <details class="sy-details" id="syNewWrap">
                        <summary>New students (<span id="syNewCount">0</span>)</summary>
                        <div class="sy-name-list" id="syNewList"></div>
                    </details>

                    <h3 style="margin-top:18px;">Not Returning</h3>
                    <p class="sy-sub">
                        Enrolled this year but absent from the new roster. Record what happened to each one — balances
                        stay in their wallets either way, nothing here moves money.
                    </p>

                    <div class="sy-bulk-bar">
                        <label for="syBulkStatus">Set all to</label>
                        <select id="syBulkStatus">
                            <option value="">Choose a status…</option>
                            <option value="graduated">Graduated</option>
                            <option value="dropped">Dropped</option>
                            <option value="transferred">Transferred</option>
                            <option value="on_leave">On Leave</option>
                            <option value="not_enrolled">Not Enrolled</option>
                        </select>
                        <button type="button" class="sy-btn ghost" onclick="syApplyBulk()" title="Set every not-returning student to the status chosen on the left, in one step.">Apply to All</button>
                        <button type="button" class="sy-btn ghost" onclick="syFillAll()" title="Pre-select that status in each row below without saving, so you can adjust individuals first.">Fill Dropdowns Only</button>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle sy-nr-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Student ID</th>
                                    <th>Year Level</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="syNotReturningBody"></tbody>
                        </table>
                    </div>

                    <button type="button" class="sy-btn primary" onclick="syApplySelected()">Apply Selected Statuses</button>
                    <div id="syApplyMsg" class="sy-msg"></div>
                </div>
                <?php endif; ?>
            </section>

            <!-- 7. Manual overrides -->
            <details class="sy-overrides sy-panel">
                <summary>Manual Overrides</summary>
                <p class="sy-sub" style="margin-top:8px;">
                    One-off corrections outside the roster flow. Every change here goes through the same write path and
                    lands in the audit trail.
                </p>

                <div class="sy-panel">
                    <h3>Mark a Student Graduated</h3>
                    <p class="sy-sub">Freezes the student's wallet. If they have a balance, process a withdrawal/encashment first — this does not move money.</p>

                    <div class="sy-form-row">
                        <div class="sy-field">
                            <label>Student User ID</label>
                            <input type="number" id="syGradUserId" min="1" placeholder="e.g. 42">
                        </div>
                        <button type="button" class="sy-btn ghost" onclick="syLookupStudent()">Look Up</button>
                        <button type="button" class="sy-btn primary" id="syGradBtn" disabled onclick="syMarkGraduate()">Mark Graduated</button>
                        <?php if ($isSuperAdmin): ?>
                        <button type="button" class="sy-btn ghost" id="syUngradBtn" disabled onclick="syUnmarkGraduate()">Un-graduate</button>
                        <?php endif; ?>
                    </div>
                    <div class="sy-lookup-card" id="syLookupCard"></div>
                    <div id="syGradMsg" class="sy-msg"></div>
                    <?php if ($isSuperAdmin): ?>
                    <p class="sy-note">Un-graduating clears the graduation date, enrolls the student for the active school year, and unfreezes their wallet. Super admin only.</p>
                    <?php endif; ?>
                </div>

                <div class="sy-panel">
                    <h3>Bulk Graduate by Year Level</h3>
                    <p class="sy-sub">Marks every non-graduated student at the selected year level as graduated and freezes their wallets.</p>

                    <div class="sy-form-row">
                        <div class="sy-field">
                            <label>Year Level</label>
                            <select id="syBulkYrLvl">
                                <option value="">Select year level</option>
                                <?php foreach ($yrLevels as $yl): ?>
                                <option value="<?= $e($yl) ?>"><?= $e($yl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="button" class="sy-btn primary" onclick="syBulkGraduate()">Graduate This Year Level</button>
                    </div>
                    <div id="syBulkMsg" class="sy-msg"></div>
                    <div class="sy-result-list" id="syBulkResults"></div>
                </div>
            </details>

            <!-- 8. Backfill -->
            <?php if ($isSuperAdmin): ?>
            <section class="sy-panel">
                <h3>Backfill Legacy Transactions</h3>
                <p class="sy-sub">One-time: maps existing transactions with no school year to a year, by comparing their date against each school year's start/end date. Super admin only.</p>
                <button type="button" class="sy-btn ghost" onclick="syBackfill()">Run Backfill</button>
                <div id="syBackfillMsg" class="sy-msg"></div>
            </section>
            <?php endif; ?>

        </main>
    </div>

    <!-- 6. Rollover confirm modal -->
    <div class="modal fade" id="syRolloverModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm School Year Rollover</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="syRolloverBody" style="font-size:14px;">
                    Loading…
                </div>
                <div class="modal-footer">
                    <button type="button" class="sy-btn ghost" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="sy-btn primary" id="syRolloverConfirmBtn" disabled onclick="syConfirmRollover()">Confirm Rollover</button>
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
            start_date: form.start_date.value,
            end_date: form.end_date.value,
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

            // Jump straight into the review for the year that was just staged.
            const diffYear = document.getElementById("syDiffYear");
            if (diffYear) {
                diffYear.value = String(data.school_year_id);
                syLoadDiff();
            }
        }
        return false;
    }

    // ── Review changes ───────────────────────────────────────────────────
    let syDiff = null;

    async function syLoadDiff() {
        const yearId = document.getElementById("syDiffYear").value;
        document.getElementById("syDiffBody").style.display = "none";
        syShowMsg("syDiffMsg", "Loading review…", true);

        const data = await syPost("preview_diff", { school_year_id: yearId });
        if (!data.success) {
            syShowMsg("syDiffMsg", data.message || "Could not load the review.", false);
            return;
        }

        syDiff = data;
        syShowMsg("syDiffMsg", `Roster for ${data.school_year_name}: ${data.roster_rows} row(s).`, true);
        document.getElementById("syDiffBody").style.display = "";

        document.getElementById("syDiffBuckets").innerHTML = `
            <div class="sy-bucket">
                <span>Returning</span><strong>${data.returning.count}</strong>
                <small>Enrolled now and on the new roster</small>
            </div>
            <div class="sy-bucket">
                <span>New</span><strong>${data.new_students.count}</strong>
                <small>On the roster, no current enrollment</small>
            </div>
            <div class="sy-bucket is-warn">
                <span>Not Returning</span><strong>${data.not_returning.count}</strong>
                <small>${data.not_returning.with_balance} with a balance, ${syPeso(data.not_returning.balance_total)} total</small>
            </div>
        `;

        const unmatchedEl = document.getElementById("syDiffUnmatched");
        unmatchedEl.innerHTML = data.unmatched_count
            ? `${data.unmatched_count} roster row(s) had no matching GenPay account and are excluded: ` +
              (data.unmatched_numbers || []).map(syEsc).join(", ")
            : "";

        syRenderNameList("syReturningList", "syReturningCount", data.returning, data.row_cap);
        syRenderNameList("syNewList", "syNewCount", data.new_students, data.row_cap);
        syRenderNotReturning(data.not_returning, data.row_cap);
    }

    function syRenderNameList(listId, countId, bucket, cap) {
        document.getElementById(countId).textContent = bucket.count;
        const rows = bucket.rows || [];
        let html = rows.map(r => `
            <div class="sy-result-row">
                <span>${syEsc(r.name || "Unnamed")} (${syEsc(r.studentID || "—")})</span>
                <span>${syEsc(r.yr_lvl || "—")}</span>
            </div>
        `).join("");
        if (bucket.count > rows.length) {
            html += `<p class="sy-note">Showing the first ${cap} of ${bucket.count}.</p>`;
        }
        document.getElementById(listId).innerHTML = html || `<p class="sy-note">None.</p>`;
    }

    function syRenderNotReturning(bucket, cap) {
        const body = document.getElementById("syNotReturningBody");
        const rows = bucket.rows || [];
        if (!rows.length) {
            body.innerHTML = `<tr><td colspan="5" class="text-center py-3">Everyone currently enrolled is on the new roster.</td></tr>`;
            return;
        }

        body.innerHTML = rows.map(r => `
            <tr data-user-id="${r.student_user_id}">
                <td><strong>${syEsc(r.name || "Unnamed")}</strong></td>
                <td>${syEsc(r.studentID || "—")}</td>
                <td>${syEsc(r.yr_lvl || "—")}</td>
                <td class="sy-bal${r.balance > 0 ? " has-money" : ""}">${syPeso(r.balance)}</td>
                <td>
                    <select class="sy-nr-status">
                        <option value="">Leave for now</option>
                        <option value="graduated">Graduated</option>
                        <option value="dropped">Dropped</option>
                        <option value="transferred">Transferred</option>
                        <option value="on_leave">On Leave</option>
                        <option value="not_enrolled">Not Enrolled</option>
                    </select>
                </td>
            </tr>
        `).join("");

        if (bucket.count > rows.length) {
            body.insertAdjacentHTML("beforeend",
                `<tr><td colspan="5"><p class="sy-note">Showing the first ${cap} of ${bucket.count}. Use "Apply to All" to cover the rest.</p></td></tr>`);
        }
    }

    /** Client-side convenience: point every row dropdown at one status. */
    function syFillAll() {
        const status = document.getElementById("syBulkStatus").value;
        if (!status) {
            syShowMsg("syApplyMsg", "Choose a status first.", false);
            return;
        }
        document.querySelectorAll("#syNotReturningBody .sy-nr-status").forEach(sel => { sel.value = status; });
        syShowMsg("syApplyMsg", "Dropdowns filled. Review them, then apply.", true);
    }

    /** One status for every not-returning student, including any past the row cap. */
    async function syApplyBulk() {
        if (!syDiff) return;
        const status = document.getElementById("syBulkStatus").value;
        if (!status) {
            syShowMsg("syApplyMsg", "Choose a status first.", false);
            return;
        }
        const total = syDiff.not_returning.count;
        if (!total) {
            syShowMsg("syApplyMsg", "There is nobody in the not-returning list.", false);
            return;
        }
        if (!await gpConfirm({
                title: 'Set every not-returning student to "' + status + '"?',
                body: 'This records an enrolment status. No money moves — balances stay in their wallets.',
                detail: [['Students affected', total]],
                confirmLabel: 'Set all ' + total,
            })) return;

        // apply_all lets the server recompute the bucket, so this covers every
        // not-returning student including any past the display cap.
        const data = await syPost("apply_statuses", {
            school_year_id: syDiff.school_year_id,
            bulk_status: status,
            apply_all: "1",
        });
        syShowMsg("syApplyMsg", data.message, data.success);
        if (data.success) syLoadDiff();
    }

    async function syApplySelected() {
        if (!syDiff) return;
        const statuses = [];
        document.querySelectorAll("#syNotReturningBody tr[data-user-id]").forEach(tr => {
            const status = tr.querySelector(".sy-nr-status").value;
            if (status) {
                statuses.push({ student_user_id: Number(tr.dataset.userId), status });
            }
        });
        if (!statuses.length) {
            syShowMsg("syApplyMsg", "No statuses selected.", false);
            return;
        }
        if (!await gpConfirm({
                title: 'Record these status changes?',
                body: 'This records enrolment statuses. No money moves — balances stay in their wallets.',
                detail: [['Changes to record', statuses.length]],
                confirmLabel: 'Record changes',
            })) return;

        const data = await syPost("apply_statuses", {
            school_year_id: syDiff.school_year_id,
            statuses: JSON.stringify(statuses),
        });
        syShowMsg("syApplyMsg", data.message, data.success);
        if (data.success) syLoadDiff();
    }

    // ── Rollover ─────────────────────────────────────────────────────────
    let syRolloverModal;
    let syPendingRollover = null;

    async function syStartRollover(newYearId, newYearName) {
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
                <p><strong>No roster uploaded for ${syEsc(data.new_school_year_name)}.</strong></p>
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
                <li><strong>${data.not_returning_count}</strong> student(s) are not on the roster and will be frozen.</li>
                <li><strong>${data.frozen_with_balance}</strong> of those still hold a balance
                    (${syPeso(data.frozen_balance_total)} in total), which stays in their wallets.</li>
                ${data.unmatched_count ? `<li>${data.unmatched_count} roster row(s) have no GenPay account and are skipped.</li>` : ""}
            </ul>
            <p style="color:var(--gp-muted);font-size:13px;">No money moves. Wallet balances stay exactly as they are — this only records the snapshot.</p>
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
        btn.textContent = "Confirm Rollover";
    }

    // ── Manual overrides ─────────────────────────────────────────────────
    let syLookedUpStudent = null;

    async function syLookupStudent() {
        const userId = document.getElementById("syGradUserId").value;
        const card = document.getElementById("syLookupCard");
        const ungradBtn = document.getElementById("syUngradBtn");
        document.getElementById("syGradBtn").disabled = true;
        if (ungradBtn) ungradBtn.disabled = true;
        syLookedUpStudent = null;
        if (!userId) return;

        const data = await syPost("student_lookup", { student_user_id: userId });
        if (!data.success) {
            card.style.display = "";
            card.innerHTML = `<span style="color:var(--gp-danger,#dc2626)">${syEsc(data.message)}</span>`;
            return;
        }

        syLookedUpStudent = data.student;
        card.style.display = "";
        card.innerHTML = `
            <strong>${syEsc(data.student.name || "Unnamed")}</strong> (${syEsc(data.student.studentID || "no ID")})<br>
            Balance: ${syPeso(data.student.balance)} &middot;
            Wallet: ${syEsc(data.student.wallet_state)} &middot;
            This year: ${syEsc(data.student.enrollment_status || "no enrollment record")}<br>
            ${data.student.graduated_at ? "Graduated on " + syEsc(data.student.graduated_at) : "Not marked as graduated"}
        `;
        document.getElementById("syGradBtn").disabled = !!data.student.graduated_at;
        if (ungradBtn) {
            ungradBtn.disabled = !data.student.graduated_at && data.student.enrollment_status !== "graduated";
        }
    }

    async function syMarkGraduate() {
        if (!syLookedUpStudent) return;
        if (!await gpConfirm({
                title: 'Mark this student as graduated?',
                body: 'Their wallet is frozen, so no further spending or transfers are possible.',
                detail: [['Student', syLookedUpStudent.name || 'This student']],
                note: 'Any remaining balance stays in the wallet. Use Un-graduate to reverse this.',
                confirmLabel: 'Mark graduated',
            })) return;

        const data = await syPost("mark_graduate", { student_user_id: syLookedUpStudent.student_user_id });
        syShowMsg("syGradMsg", data.message, data.success);
        if (data.success) {
            document.getElementById("syGradBtn").disabled = true;
            syLookupStudent();
        }
    }

    async function syUnmarkGraduate() {
        if (!syLookedUpStudent) return;
        if (!await gpConfirm({
                title: 'Reverse this graduation?',
                body: 'The wallet is unfrozen and the student is enrolled for the active school year.',
                detail: [['Student', syLookedUpStudent.name || 'This student']],
                confirmLabel: 'Reverse graduation',
            })) return;

        const data = await syPost("unmark_graduate", { student_user_id: syLookedUpStudent.student_user_id });
        syShowMsg("syGradMsg", data.message, data.success);
        if (data.success) {
            syLookupStudent();
        }
    }

    async function syBulkGraduate() {
        const yrLvl = document.getElementById("syBulkYrLvl").value;
        if (!yrLvl) {
            syShowMsg("syBulkMsg", "Select a year level.", false);
            return;
        }
        if (!await gpConfirm({
                title: 'Graduate a whole year level?',
                body: 'Every non-graduated student at this year level is marked graduated and their wallets frozen.',
                detail: [['Year level', yrLvl]],
                note: 'This affects many students at once. Balances are kept, but each wallet is frozen.',
                confirmLabel: 'Graduate year ' + yrLvl,
                danger: true,
                typeToConfirm: 'GRADUATE',
            })) return;

        const data = await syPost("mark_graduate_bulk_year", { yr_lvl: yrLvl });
        syShowMsg("syBulkMsg", data.message, data.success);

        const resultsEl = document.getElementById("syBulkResults");
        resultsEl.innerHTML = "";
        (data.results || []).forEach(r => {
            const row = document.createElement("div");
            row.className = "sy-result-row";
            row.innerHTML = `<span>${syEsc(r.name || "Unnamed")} (${syEsc(r.studentID || "—")})</span><span>${syPeso(r.balance)}${r.has_balance ? " ⚠" : ""}</span>`;
            resultsEl.appendChild(row);
        });
    }

    // ── Legacy backfill ──────────────────────────────────────────────────
    async function syBackfill() {
        if (!await gpConfirm({
                title: 'Run the legacy transaction backfill?',
                body: 'This stamps historical transactions with the school year they belong to. It is designed to run once.',
                confirmLabel: 'Run backfill',
            })) return;
        const data = await syPost("backfill_legacy", {});
        syShowMsg("syBackfillMsg", data.message, data.success);
    }
    </script>
</body>
</html>
