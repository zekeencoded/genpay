<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);
gjc_ensure_school_year_schema($db);

$currentUser = gjc_current_user($db);
$isSuperAdmin = gjc_sub_role() === 'super_admin';

$schoolYears = $db->query(
    "SELECT id, school_year_name, start_date, end_date, is_active, created_at
       FROM school_years
      ORDER BY school_year_name DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$activeYear = null;
foreach ($schoolYears as $year) {
    if ((int) $year['is_active'] === 1) {
        $activeYear = $year;
        break;
    }
}

$walletCount = (int) $db->query("SELECT COUNT(*) FROM student_wallets")->fetchColumn();
$graduateCount = (int) $db->query(
    "SELECT COUNT(*) FROM student_wallets sw
       JOIN student_info si ON si.userID = sw.user_id
      WHERE si.graduated_at IS NOT NULL"
)->fetchColumn();

$yrLevels = $db->query(
    "SELECT DISTINCT yr_lvl FROM student_info WHERE yr_lvl IS NOT NULL AND yr_lvl <> '' ORDER BY yr_lvl ASC"
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
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=35">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=21">
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
                    <p>Manage school year cycles, roll over balances, and lock graduate accounts.</p>
                </div>
                <div class="admin-user">
                    <span><?= $e($currentUser['name'] ?? 'Admin') ?></span>
                    <div class="avatar"><i class="fa-solid fa-user-tie"></i></div>
                </div>
            </header>

            <section class="sy-stats">
                <div class="sy-stat-card">
                    <span>Active School Year</span>
                    <h2><?= $activeYear ? $e($activeYear['school_year_name']) : 'None Active' ?></h2>
                </div>
                <div class="sy-stat-card">
                    <span>Student Wallets</span>
                    <h2><?= $walletCount ?></h2>
                </div>
                <div class="sy-stat-card">
                    <span>Graduated Students</span>
                    <h2><?= $graduateCount ?></h2>
                </div>
            </section>

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
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="syYearsBody">
                            <?php if (!$schoolYears): ?>
                            <tr><td colspan="5" class="text-center py-3">No school years yet. Create one below.</td></tr>
                            <?php else: foreach ($schoolYears as $year): ?>
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

            <section class="sy-panel">
                <h3>Start a New School Year</h3>
                <p class="sy-sub">Creates an inactive school year. Use "Roll Over Into This Year" above to activate it.</p>

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

            <section class="sy-panel">
                <h3>Mark a Student Graduated</h3>
                <p class="sy-sub">Freezes the student's wallet. If they have a balance, process a withdrawal/encashment first — this does not move money.</p>

                <div class="sy-form-row">
                    <div class="sy-field">
                        <label>Student User ID</label>
                        <input type="number" id="syGradUserId" min="1" placeholder="e.g. 42">
                    </div>
                    <button type="button" class="sy-btn ghost" onclick="syLookupStudent()">Look Up</button>
                    <button type="button" class="sy-btn primary" id="syGradBtn" disabled onclick="syMarkGraduate()">Mark Graduated</button>
                </div>
                <div class="sy-lookup-card" id="syLookupCard"></div>
                <div id="syGradMsg" class="sy-msg"></div>
            </section>

            <section class="sy-panel">
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
            </section>

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

    <!-- Rollover confirm modal -->
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
    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }

    const SY_API = "<?= ADMIN_URL ?>/api/school_years.php";

    async function syPost(action, params) {
        const form = new FormData();
        form.append("action", action);
        Object.entries(params || {}).forEach(([k, v]) => form.append(k, v));
        const res = await fetch(SY_API, { method: "POST", body: form });
        return res.json();
    }

    function syShowMsg(elId, message, ok) {
        const el = document.getElementById(elId);
        el.textContent = message;
        el.className = "sy-msg " + (ok ? "ok" : "err");
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

        syPendingRollover = data;
        document.getElementById("syRolloverBody").innerHTML = `
            <p><strong>${data.old_school_year_name || "No active year"}</strong> &rarr; <strong>${data.new_school_year_name}</strong></p>
            <ul style="padding-left:18px;margin:0 0 8px;">
                <li>${data.wallet_count} student wallet(s) will have their ending balance snapshotted.</li>
                <li>${data.non_graduate_count} student(s) will start the new year with their current balance.</li>
                <li>${data.graduate_count} graduated student(s) are excluded from the new year.</li>
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
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || "Rollover failed.");
            btn.disabled = false;
            btn.textContent = "Confirm Rollover";
        }
    }

    // ── Mark graduate (single) ──────────────────────────────────────────
    let syLookedUpStudent = null;

    async function syLookupStudent() {
        const userId = document.getElementById("syGradUserId").value;
        const card = document.getElementById("syLookupCard");
        document.getElementById("syGradBtn").disabled = true;
        syLookedUpStudent = null;
        if (!userId) return;

        const data = await syPost("student_lookup", { student_user_id: userId });
        if (!data.success) {
            card.style.display = "";
            card.innerHTML = `<span style="color:var(--gp-danger,#dc2626)">${data.message}</span>`;
            return;
        }

        syLookedUpStudent = data.student;
        card.style.display = "";
        card.innerHTML = `
            <strong>${data.student.name || "Unnamed"}</strong> (${data.student.studentID || "no ID"})<br>
            Balance: ₱${Number(data.student.balance).toFixed(2)} &middot;
            ${data.student.graduated_at ? "Already graduated on " + data.student.graduated_at : "Not yet graduated"}
        `;
        document.getElementById("syGradBtn").disabled = !!data.student.graduated_at;
    }

    async function syMarkGraduate() {
        if (!syLookedUpStudent) return;
        if (!confirm(`Mark ${syLookedUpStudent.name || "this student"} as graduated and freeze their wallet?`)) return;

        const data = await syPost("mark_graduate", { student_user_id: syLookedUpStudent.student_user_id });
        syShowMsg("syGradMsg", data.message, data.success);
        if (data.success) {
            document.getElementById("syGradBtn").disabled = true;
        }
    }

    // ── Bulk graduate by year level ─────────────────────────────────────
    async function syBulkGraduate() {
        const yrLvl = document.getElementById("syBulkYrLvl").value;
        if (!yrLvl) {
            syShowMsg("syBulkMsg", "Select a year level.", false);
            return;
        }
        if (!confirm(`Mark ALL non-graduated students at year level ${yrLvl} as graduated?`)) return;

        const data = await syPost("mark_graduate_bulk_year", { yr_lvl: yrLvl });
        syShowMsg("syBulkMsg", data.message, data.success);

        const resultsEl = document.getElementById("syBulkResults");
        resultsEl.innerHTML = "";
        (data.results || []).forEach(r => {
            const row = document.createElement("div");
            row.className = "sy-result-row";
            row.innerHTML = `<span>${r.name || "Unnamed"} (${r.studentID || "—"})</span><span>₱${Number(r.balance).toFixed(2)}${r.has_balance ? " ⚠" : ""}</span>`;
            resultsEl.appendChild(row);
        });
    }

    // ── Legacy backfill ──────────────────────────────────────────────────
    async function syBackfill() {
        if (!confirm("Run the one-time legacy transaction backfill?")) return;
        const data = await syPost("backfill_legacy", {});
        syShowMsg("syBackfillMsg", data.message, data.success);
    }
    </script>
</body>
</html>
