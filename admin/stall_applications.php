<?php
// ============================================================
//  admin/stall_applications.php
//  ONE-STOP stall application management.
//
//  Submit -> meeting auto-scheduled at submission -> single in-person meeting
//  (verify docs + sign contract + pay) -> Awarded.
//
//  Two visible statuses: Pending for Verification and Awarded. Rejected,
//  Cancelled, and Expired (no-show past the meeting date) are kept as
//  records (daily log / history) but are internal.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../connection/config.php";
require_once __DIR__ . "/../connection/pdo.php";
require_once __DIR__ . "/../connection/app.php";
require_once __DIR__ . "/../connection/StallManager.php";
require_once __DIR__ . "/../connection/audit_logger.php";
require_once __DIR__ . "/../connection/mailer.php";

gjc_require_role(["finance"]);
gjc_ensure_stall_application_workflow_schema($db);
gjc_ensure_meeting_scheduling_schema($db);
$currentUser = gjc_current_user($db);
$currentPage = "stall_applications";
$adminId = gjc_user_id();

// No-show sweep: any pending_verification application whose meeting date has
// fully passed becomes 'expired' before we pull the list below.
gjc_expire_overdue_stall_applications($db, $adminId);

// Awarded applications become active tenants, so they no longer belong in the
// applications list — only pending and the internal rejected/cancelled/expired history remain.
$apps = $db
    ->query(
        "SELECT sa.*, s.label AS stall_label, s.monthly_rate AS stall_rate
       FROM " . gjc_stall_application_source($db, 'sa') . "
       LEFT JOIN stalls s ON s.stall_id = sa.stall_id
      WHERE sa.status IN ('pending_verification','rejected','cancelled','expired')
      ORDER BY sa.created_at DESC",
    )
    ->fetchAll(PDO::FETCH_ASSOC);

// Awarded applications leave the list above; keep just a running total for the KPI.
$awardedCount = (int) $db
    ->query("SELECT COUNT(*) FROM " . gjc_stall_application_source($db) . " WHERE status = 'awarded'")
    ->fetchColumn();

// Vacant stalls available for assignment at award.
$stallMgr = new StallManager($db);
$vacantStalls = array_values(
    array_filter($stallMgr->allStalls(), fn($s) => $s["status"] === "vacant"),
);

// Today's meetings — the appointment log the admin works from, time-ordered.
$today = date("Y-m-d");
$todaySchedule = array_values(
    array_filter(
        $apps,
        fn($a) => $a["meetup_scheduled_at"] &&
            substr($a["meetup_scheduled_at"], 0, 10) === $today,
    ),
);
usort(
    $todaySchedule,
    fn($a, $b) => strcmp(
        (string) $a["meetup_scheduled_at"],
        (string) $b["meetup_scheduled_at"],
    ),
);

$statusCounts = [
    "pending_verification" => 0,
    "awarded" => 0,
    "rejected" => 0,
    "cancelled" => 0,
    "expired" => 0,
];
foreach ($apps as $a) {
    if (isset($statusCounts[$a["status"]])) {
        $statusCounts[$a["status"]]++;
    }
}

$STATUS_META = [
    "pending_verification" => [
        "label" => "Pending for Verification",
        "badge" => "warning",
        "icon" => "fa-hourglass-half",
    ],
    "awarded" => [
        "label" => "Awarded",
        "badge" => "success",
        "icon" => "fa-circle-check",
    ],
    "rejected" => [
        "label" => "Rejected",
        "badge" => "danger",
        "icon" => "fa-circle-xmark",
    ],
    "cancelled" => [
        "label" => "Cancelled",
        "badge" => "secondary",
        "icon" => "fa-ban",
    ],
    "expired" => [
        "label" => "Expired",
        "badge" => "secondary",
        "icon" => "fa-user-clock",
    ],
];

// Gold "New" pill for applications no admin has opened yet; the first open
// stamps first_viewed_at (via the mark_viewed API action) and removes it.
function sa_new_badge(array $app): string
{
    if (
        $app["status"] !== "pending_verification" ||
        !empty($app["first_viewed_at"])
    ) {
        return "";
    }
    return ' <span class="sa-new-badge" data-new-badge="' .
        (int) $app["id"] .
        '">New</span>';
}

function sa_meeting_label(?string $dt): string
{
    if (!$dt) {
        return '<span class="text-muted">&mdash;</span>';
    }
    $ts = strtotime($dt);
    return '<span class="fw-semibold">' .
        date("M j, Y", $ts) .
        '</span><br><span class="small text-muted">' .
        date("g:i A", $ts) .
        "</span>";
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
    <title>Stall Applications | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/stall_applications.css?v=11">
    <style>
    /* KPI cards — thin large numbers, left-aligned flex row */
    .sa-kpi { border:1px solid #dddfd8; border-radius:12px; padding:16px 20px; background:#fff; display:flex; flex-direction:column; gap:8px; min-width:200px; }
    .sa-kpi .l { display:flex; align-items:center; gap:6px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--gp-muted); }
    .sa-kpi .n { font-size:36px; font-weight:300; line-height:1; color:#1a1a1a; }

    /* Card shell for schedule + table */
    .sa-card { border:1px solid #dddfd8; border-radius:12px; background:#fff; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.03); }
    .today-card { border:1px solid #dddfd8; border-radius:12px; background:#fff; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.03); }
    .today-head { padding:15px 20px; border-bottom:1px solid #f1f0ea; display:flex; justify-content:space-between; align-items:center; }
    .today-item { display:grid; grid-template-columns:90px 1fr auto; align-items:center; gap:16px; padding:14px 20px; border-bottom:1px solid #f5f5f0; cursor:pointer; transition:.12s; }
    .today-item:last-child { border-bottom:0; }
    .today-item:hover { background:#f6f8f4; }
    .today-time { font-weight:700; font-size:13px; color:#1e5c3a; }
    .today-who { min-width:0; }
    .today-who .b { font-weight:600; font-size:14px; color:#1a1a1a; }
    .today-who .p { font-size:12px; color:var(--gp-subtle); margin-top:2px; }
    .sa-sched-right { display:flex; align-items:center; gap:12px; }

    /* "New" pill — newly submitted, not yet opened; cleared on first open */
    .sa-new-badge { display:inline-block; margin-left:8px; padding:2px 9px; border-radius:999px; background:#fff5cc; border:1px solid #f0a500; color:#7a5a00; font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; vertical-align:middle; }

    /* Inline "Open" affordance */
    .sa-open { font-size:13px; font-weight:600; color:#27764b; cursor:pointer; white-space:nowrap; }
    .sa-open:hover { text-decoration:underline; }

    /* Toolbar: pill search + filter pills */
    .sa-search { display:flex; align-items:center; gap:8px; background:#f5f5f0; border:1px solid #e0e2db; border-radius:8px; padding:7px 12px; flex:1; max-width:300px; }
    .sa-search input { border:none; background:transparent; font-size:13px; color:var(--gjc-slate); outline:none; width:100%; font-family:inherit; }
    .sa-search input::placeholder { color:var(--gp-subtle); }
    .sa-search .fa-magnifying-glass { color:var(--gp-subtle); font-size:13px; }
    .sa-filters { display:flex; gap:4px; flex-wrap:wrap; }
    .filter-btn { padding:7px 14px; border-radius:8px; border:1px solid #e0e2db; background:#fff; color:var(--gjc-slate); font-size:12px; font-weight:500; cursor:pointer; line-height:1.2; }
    .filter-btn:hover { border-color:#1e5c3a; color:#1e5c3a; }
    .filter-btn.active { background:#1e5c3a; color:#fff; border-color:#1e5c3a; }

    /* Applications table */
    /* .sa-table base styling now comes from the canonical table system in theme.css. */
    .sa-table .biz { font-size:14px; font-weight:600; color:#1a1a1a; }
    .sa-table .prop { font-size:13px; color:var(--gjc-slate); }
    .sa-table .phone { font-size:13px; color:#555; }
    .sa-table .email { font-size:11px; color:var(--gp-subtle); margin-top:3px; }
    .sa-table .submitted { font-size:13px; color:var(--gp-muted); }

    /* DataTables footer (info + pagination) */
    .sa-dt-foot { font-size:13px; }
    .sa-dt-foot .dataTables_info { color:var(--gp-muted); padding:0; }
    .sa-dt-foot .dataTables_paginate { margin:0; }
    .sa-dt-foot .pagination { margin:0; }
    .sa-dt-foot .page-link { color:#1e5c3a; font-size:13px; }
    .sa-dt-foot .page-item.active .page-link { background:#1e5c3a; border-color:#1e5c3a; color:#fff; }
    .sa-dt-foot .page-item.disabled .page-link { color:#c0c4bd; }

    .sa-doc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:8px; }
    .sa-doc { position:relative; border:1px solid var(--gp-line); border-radius:8px; overflow:hidden; background:#fff; cursor:pointer; transition:border-color .12s, box-shadow .12s; }
    .sa-doc:hover { border-color:#27764b; box-shadow:0 2px 10px rgba(14, 99, 50,.14); }
    .sa-doc .cap { display:flex; justify-content:space-between; align-items:center; gap:6px; padding:5px 8px; font-size:11px; font-weight:700; border-bottom:1px solid var(--gjc-soft-2); }
    .sa-doc .cap span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .sa-doc .cap .sa-doc-pop { color:inherit; }
    .sa-doc .sa-doc-thumb { width:100%; height:110px; border:0; display:block; background:#1e1e1e; pointer-events:none; }
    .sa-doc .sa-doc-hint { position:absolute; right:6px; bottom:6px; background:rgba(14, 99, 50,.82); color:#fff; font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; opacity:0; transition:opacity .12s; pointer-events:none; }
    .sa-doc:hover .sa-doc-hint { opacity:1; }

    /* Full-size embedded document viewer (opens on click — no new tab) */
    .doc-viewer { position:fixed; inset:0; z-index:1085; background:rgba(15,23,20,.88); display:flex; align-items:center; justify-content:center; padding:28px; }
    .doc-viewer[hidden] { display:none; }
    .doc-viewer-stage { width:min(1100px,96vw); height:min(90vh,920px); }
    .doc-viewer-stage iframe { width:100%; height:100%; border:0; border-radius:8px; background:#fff; box-shadow:0 12px 44px rgba(0,0,0,.45); }
    .doc-viewer-cap { position:absolute; top:22px; left:28px; color:#fff; font-weight:700; font-size:14px; }
    .doc-viewer-close { position:absolute; top:15px; right:22px; width:42px; height:42px; border-radius:50%; border:0; background:rgba(255,255,255,.16); color:#fff; font-size:24px; line-height:1; cursor:pointer; }
    .doc-viewer-close:hover { background:rgba(255,255,255,.3); }
    .sa-section { border:1px solid var(--gp-line); border-radius:14px; padding:16px; }
    .sa-section h6 { font-weight:800; margin-bottom:12px; }
    .sa-done-pill { font-size:11px; font-weight:800; color:#166534; background:var(--gp-success-bg); border-radius:999px; padding:3px 10px; }

    /* What has to be true before a stall can be awarded. Previously the user
       found this out by clicking Award and being refused, one condition at a
       time, with the requirements written nowhere on the page. */
    .sa-checklist { list-style:none; margin:0; padding:0; display:grid; gap:6px; }
    .sa-checklist li { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--gp-muted); }
    .sa-checklist li i { font-size:11px; color:var(--gp-subtle); }
    .sa-checklist li.is-done { color:var(--gp-success); }
    .sa-checklist li.is-done i { color:var(--gp-success); }
    .sa-checklist li.is-blocked { color:var(--gp-danger); }
    .sa-checklist li.is-blocked i { color:var(--gp-danger); }
    .filter-btn.active { background:#1e5c3a; color:#fff; border-color:#1e5c3a; }

    /* Inline accordion (replaces the old workspace modal) */
    .sa-open .sa-caret { transition:transform .18s ease; margin-left:5px; font-size:11px; }
    .sa-table tbody tr.app-expanded .sa-caret { transform:rotate(180deg); }
    .sa-table tbody tr.app-expanded > td { background:#eef4ea !important; }
    .sa-table tbody tr.app-detail-row > td,
    .sa-table tbody tr.app-detail-row:hover > td { background:#fbfcf9; padding:0; border-bottom:1px solid #eeeee8; }
    .app-detail { padding:20px 22px; }
    .app-detail-head { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin-bottom:16px; }
    .app-detail-title { font-size:16px; font-weight:800; color:#1a1a1a; }
    </style>
</head>
<body class="gp-theme">
<div class="admin-layout">
    <?php require_once __DIR__ . "/../includes/partials/sidebar_admin.php"; ?>

    <main class="admin-main">
        <header class="topbar">
            <button class="menu-btn" aria-label="Toggle navigation" onclick="document.getElementById('sidebar').classList.toggle('collapsed')"><i class="fa-solid fa-bars"></i></button>
            <div>
                <h1>Stall Applications</h1>
                <p>One-stop verification: documents, contract, and payment in a single meeting.</p>
            </div>
            <div class="admin-user">
                <span><?= gjc_e($currentUser["name"]) ?></span>
                <?php require __DIR__ . '/../includes/partials/avatar_admin.php'; ?>
            </div>
        </header>

        <!-- Two visible KPIs -->
        <section class="d-flex flex-wrap gap-3 mb-4">
            <div class="sa-kpi">
                <div class="l"><i class="fa-solid fa-hourglass-half text-warning"></i> Pending Verification</div>
                <div class="n"><?= $statusCounts[
                    "pending_verification"
                ] ?></div>
            </div>
            <div class="sa-kpi">
                <div class="l"><i class="fa-solid fa-circle-check text-success"></i> Awarded (Active Tenants)</div>
                <div class="n"><?= $awardedCount ?></div>
            </div>
        </section>

        <!-- Today's schedule -->
        <section class="today-card mb-4">
            <div class="today-head">
                <div>
                    <h5 class="mb-0 fw-bold"><i class="fa-solid fa-calendar-day text-success me-2"></i>Today's Schedule</h5>
                    <div class="small text-muted"><?= date(
                        "l, F j, Y",
                    ) ?> &middot; meetings in time order</div>
                </div>
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><?= count(
                    $todaySchedule,
                ) ?> meeting<?= count($todaySchedule) === 1 ? "" : "s" ?></span>
            </div>
            <?php if (empty($todaySchedule)): ?>
            <div class="p-4 text-center text-muted">No meetings scheduled for today.</div>
            <?php else: ?>
                <?php foreach ($todaySchedule as $a):
                    $m = $STATUS_META[$a["status"]]; ?>
                <div class="today-item" onclick="openApp(<?= (int) $a[
                    "id"
                ] ?>)">
                    <div class="today-time"><?= date(
                        "g:i A",
                        strtotime($a["meetup_scheduled_at"]),
                    ) ?></div>
                    <div class="today-who">
                        <div class="b"><?= gjc_e(
                            $a["business_name"],
                        ) ?><?= sa_new_badge($a) ?></div>
                        <div class="p"><?= gjc_e(
                            $a["proprietor_name"],
                        ) ?> &middot; <?= gjc_e($a["contact_number"]) ?></div>
                    </div>
                    <div class="sa-sched-right">
                        <span class="badge bg-<?= $m[
                            "badge"
                        ] ?>"><i class="fa-solid <?= $m[
    "icon"
] ?> me-1"></i><?= $m["label"] ?></span>
                        <span class="sa-open">Open</span>
                    </div>
                </div>
                <?php
                endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- Applications card: toolbar + table -->
        <div class="sa-card">
            <!-- Toolbar: search + status filter -->
            <div class="d-flex flex-wrap gap-2 align-items-center" style="padding:13px 20px; border-bottom:1px solid #f1f0ea;">
                <div class="sa-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" id="appSearch" placeholder="Search business, proprietor, or email&hellip;">
                </div>
                <div class="sa-filters ms-auto" id="statusFilter">
                    <button type="button" class="filter-btn active" data-status="all">All (<?= count(
                        $apps,
                    ) ?>)</button>
                    <button type="button" class="filter-btn" data-status="pending_verification">Pending (<?= $statusCounts[
                        "pending_verification"
                    ] ?>)</button>
                    <button type="button" class="filter-btn" data-status="awarded">Awarded (<?= $statusCounts[
                        "awarded"
                    ] ?>)</button>
                    <button type="button" class="filter-btn" data-status="rejected">Rejected (<?= $statusCounts[
                        "rejected"
                    ] ?>)</button>
                    <button type="button" class="filter-btn" data-status="expired">Expired (<?= $statusCounts[
                        "expired"
                    ] ?>)</button>
                </div>
            </div>
            <div class="table-responsive">
                <table id="appsTable" class="table align-middle mb-0 sa-table">
                    <thead>
                        <tr>
                            <th>Business Name</th>
                            <th>Full Name</th>
                            <th>Contact</th>
                            <th>Meeting Schedule</th>
                            <th>Status</th>
                            <th>Date Submitted</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="appTableBody">
                        <?php if (empty($apps)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No applications yet.</td></tr>
                        <?php else:foreach ($apps as $app):
                                $m = $STATUS_META[$app["status"]]; ?>
                        <tr class="app-row" data-app-id="<?= (int) $app[
                            "id"
                        ] ?>"
                            data-status="<?= $app["status"] ?>"
                            data-search="<?= htmlspecialchars(
                                strtolower(
                                    $app["business_name"] .
                                        " " .
                                        $app["proprietor_name"] .
                                        " " .
                                        $app["email"],
                                ),
                            ) ?>"
                            style="cursor:pointer" onclick="expandApp(<?= (int) $app[
                                "id"
                            ] ?>, this)">
                            <td><span class="biz"><?= gjc_e(
                                $app["business_name"],
                            ) ?></span><?= sa_new_badge($app) ?></td>
                            <td><span class="prop"><?= gjc_e(
                                $app["proprietor_name"],
                            ) ?></span></td>
                            <td>
                                <div class="phone"><?= gjc_e(
                                    $app["contact_number"],
                                ) ?></div>
                                <div class="email"><?= gjc_e(
                                    $app["email"],
                                ) ?></div>
                            </td>
                            <td class="sa-meeting"><?= sa_meeting_label(
                                $app["meetup_scheduled_at"],
                            ) ?></td>
                            <td><span class="badge bg-<?= $m[
                                "badge"
                            ] ?> status-badge"><i class="fa-solid <?= $m[
     "icon"
 ] ?> me-1"></i><?= $m["label"] ?></span></td>
                            <td data-order="<?= gjc_e(
                                $app["created_at"],
                            ) ?>"><span class="submitted"><?= date(
    "M j, Y",
    strtotime($app["created_at"]),
) ?></span></td>
                            <td class="text-end"><span class="sa-open">Details <i class="fa-solid fa-chevron-down sa-caret"></i></span></td>
                        </tr>
                        <?php
                            endforeach;endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Reject reason modal -->
<div class="modal fade" id="reasonModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="reasonTitle">Reason</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small" id="reasonHelp"></p>
                <textarea id="reasonText" class="form-control" rows="3" placeholder="Enter the reason..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
                <button type="button" class="btn btn-danger" id="reasonConfirm">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Award confirmation modal -->
<div class="modal fade" id="awardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="awardTitle">Award Stall</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2" id="awardConfirmText"></p>
                <p class="text-danger small mb-0"><strong>This cannot be undone.</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
                <button type="button" class="btn btn-success" id="awardConfirm">Award Stall</button>
            </div>
        </div>
    </div>
</div>

<!-- Reschedule meeting modal -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reschedule Meeting</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small" id="resCurrent"></p>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small mb-1" for="resDate">New Date</label>
                        <input type="date" class="form-control form-control-sm" id="resDate">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1" for="resTime">New Time</label>
                        <select class="form-select form-select-sm" id="resTime" disabled>
                            <option value="">Pick a date first&hellip;</option>
                        </select>
                    </div>
                </div>
                <div class="small mt-2" id="resHint"></div>
                <div class="small text-muted mt-3"><i class="fa-solid fa-envelope me-1"></i>The applicant will automatically receive an email with the new schedule.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back</button>
                <button type="button" class="btn btn-success" id="resConfirm" disabled><i class="fa-solid fa-calendar-pen me-1"></i>Reschedule</button>
            </div>
        </div>
    </div>
</div>

<!-- Full-size embedded document viewer -->
<div id="docViewer" class="doc-viewer" hidden>
    <div class="doc-viewer-cap" id="docViewerCap"></div>
    <button type="button" class="doc-viewer-close" aria-label="Close">&times;</button>
    <div class="doc-viewer-stage" id="docViewerStage"></div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastWrap"></div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . "/../includes/partials/datatables_assets.php"; ?>
<?php require __DIR__ . "/../includes/partials/feedback_assets.php"; ?>
<script>
const APPS = <?= json_encode(
    array_values($apps),
    JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_INVALID_UTF8_SUBSTITUTE,
) ?>;
const VACANT_STALLS = <?= json_encode(
    $vacantStalls,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
) ?>;
const STATUS_META = <?= json_encode($STATUS_META) ?>;
const DOC_URL = '<?= ADMIN_URL ?>/doc?f=';
const API_URL = '<?= ADMIN_URL ?>/api/stall_applications';

let dt = null;   // DataTables instance (null if assets are blocked — static fallback)
let reasonModal, awardModal, reasonAppId = null;
let awardAppId = null, awardStallId = null;
let rescheduleModal, resAppId = null;
document.addEventListener('DOMContentLoaded', () => {
    reasonModal = new bootstrap.Modal(document.getElementById('reasonModal'));
    awardModal = new bootstrap.Modal(document.getElementById('awardModal'));
    rescheduleModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));

    // Click a submitted-document thumbnail (or a .sa-doc-link, e.g. the signed
    // contract) → open it full-size in the embedded viewer.
    document.addEventListener('click', (e) => {
        if (e.target.closest('.sa-doc-pop')) return;         // the ↗ icon still opens a new tab
        const link = e.target.closest('.sa-doc-link');
        if (link) { e.preventDefault(); openDoc(link.dataset.docUrl, link.dataset.docLabel); return; }
        const cell = e.target.closest('.sa-doc');
        if (cell) { e.preventDefault(); openDoc(cell.dataset.docUrl, cell.dataset.docLabel); }
    });
    const viewer = document.getElementById('docViewer');
    viewer.addEventListener('click', (e) => {
        if (e.target === viewer || e.target.closest('.doc-viewer-close')) closeDoc();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !viewer.hidden) closeDoc(); });
});

// Embedded full-size document viewer — fits the whole image/PDF, never opens a new tab.
function openDoc(url, label) {
    if (!url) return;
    document.getElementById('docViewerStage').innerHTML =
        `<iframe src="${url}" title="${esc(label || 'Document')}"></iframe>`;
    document.getElementById('docViewerCap').textContent = label || '';
    document.getElementById('docViewer').hidden = false;
    document.body.style.overflow = 'hidden';
}
function closeDoc() {
    document.getElementById('docViewer').hidden = true;
    document.getElementById('docViewerStage').innerHTML = '';   // stop the iframe loading
    document.getElementById('docViewerCap').textContent = '';
    document.body.style.overflow = '';
}

function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function peso(v) { return '₱' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 }); }
function findApp(id) { return APPS.find(a => a.id == id); }
function fmtDateTime(v) {
    if (!v) return '—';
    const d = new Date(String(v).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? v : d.toLocaleString('en-PH', { year:'numeric', month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
}
function fmtDate(v) {
    if (!v) return '—';
    const d = new Date(String(v).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
}
function toast(msg, ok = true) {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${ok ? 'success' : 'danger'} border-0`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${esc(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 4500 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}
function post(data, isForm = false) {
    const body = isForm ? data : (() => { const fd = new FormData(); Object.entries(data).forEach(([k, v]) => fd.append(k, v)); return fd; })();
    return fetch(API_URL, { method: 'POST', body }).then(async r => {
        const t = await r.text();
        try { return JSON.parse(t); } catch (e) { console.error('Bad API response:', t); return { success: false, message: 'The server returned an invalid response.' }; }
    });
}

// ── Document grid (side-by-side preview) ──
function docGrid(app) {
    const docs = {
        'Applicant Photo': app.profile_picture,
        'Business Permit': app.business_permit,
        'Sanitary Permit': app.sanitary_permit,
        'GJC Requirements': app.gjc_requirements,
        'Clearance': app.clearance,
    };
    const cells = Object.entries(docs).map(([label, path]) => {
        if (!path || path === 'pending_path') return '';
        const url = DOC_URL + encodeURIComponent(String(path).replace(/^\//, ''));
        return `<div class="sa-doc" data-doc-url="${url}" data-doc-label="${esc(label)}" title="Click to view full size">
            <div class="cap"><span>${esc(label)}</span><a class="sa-doc-pop" href="${url}" target="_blank" rel="noopener" title="Open in new tab"><i class="fa-solid fa-up-right-from-square"></i></a></div>
            <iframe class="sa-doc-thumb" src="${url}" loading="lazy"></iframe>
            <span class="sa-doc-hint"><i class="fa-solid fa-expand me-1"></i>Enlarge</span>
        </div>`;
    }).join('');
    return `<div class="sa-doc-grid">${cells}</div>`;
}

// ── Inline accordion (expands the detail directly under the clicked row) ──
function detailHtml(app) {
    const m = STATUS_META[app.status];
    const inner = app.status === 'pending_verification' ? renderWorkspace(app) : renderSummary(app);
    return `<div class="app-detail">
        <div class="app-detail-head">
            <span class="app-detail-title">${esc(app.business_name)}</span>
            <span class="badge bg-${m.badge}"><i class="fa-solid ${m.icon} me-1"></i>${m.label}</span>
        </div>
        ${inner}
    </div>`;
}

// Collapse every open panel — enforces the one-at-a-time accordion behaviour.
function collapseAllDetails() {
    if (dt) {
        dt.rows().every(function () {
            if (this.child.isShown()) this.child.hide();
            this.node().classList.remove('app-expanded');
        });
    } else {
        document.querySelectorAll('#appTableBody tr.app-detail-row').forEach(r => r.remove());
        document.querySelectorAll('#appTableBody tr.app-expanded').forEach(r => r.classList.remove('app-expanded'));
    }
}

// Toggle (row click) or force-open (Today's Schedule) the panel for one application.
function expandApp(id, tr, toggle = true) {
    const app = findApp(id);
    if (!app || !tr) return;

    if (dt) {
        const row = dt.row(tr);
        const wasOpen = row.child.isShown();
        collapseAllDetails();
        if (toggle && wasOpen) return;                       // clicking an open row closes it
        row.child(detailHtml(app), 'app-detail-row').show();
    } else {
        const wasOpen = tr.classList.contains('app-expanded');
        collapseAllDetails();
        if (toggle && wasOpen) return;
        const dr = document.createElement('tr');
        dr.className = 'app-detail-row';
        const td = document.createElement('td');
        td.colSpan = 7;
        td.innerHTML = detailHtml(app);
        dr.appendChild(td);
        tr.after(dr);
    }
    tr.classList.add('app-expanded');
    markViewed(app);
    if (app.status === 'pending_verification') wireWorkspace(app);
    tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// First open clears the "New" badge — persisted server-side so it stays
// cleared for every admin session afterwards.
function markViewed(app) {
    if (app.first_viewed_at) return;
    app.first_viewed_at = new Date().toISOString();
    document.querySelectorAll(`[data-new-badge="${app.id}"]`).forEach(el => el.remove());
    post({ action: 'mark_viewed', app_id: app.id });
}

// Re-render an already-open panel in place (after saving a contract/payment).
function refreshApp(id) {
    const app = findApp(id);
    const tr = document.querySelector(`#appTableBody tr.app-row[data-app-id="${id}"]`);
    if (!app || !tr) return;
    if (dt) {
        const row = dt.row(tr);
        if (!row.child.isShown()) return;
        row.child(detailHtml(app), 'app-detail-row').show();
    } else {
        const dr = tr.nextElementSibling;
        if (!dr || !dr.classList.contains('app-detail-row')) return;
        dr.querySelector('td').innerHTML = detailHtml(app);
    }
    if (app.status === 'pending_verification') wireWorkspace(app);
}

// Entry point from Today's Schedule — clear filters, page to the row, then open it.
function openApp(id) {
    if (dt) {
        statusFilter = 'all';
        jQuery('.filter-btn').removeClass('active').filter('[data-status="all"]').addClass('active');
        jQuery('#appSearch').val('');
        dt.search('');
        const node = document.querySelector(`#appTableBody tr.app-row[data-app-id="${id}"]`);
        if (node) {
            const idx = dt.row(node).index();
            dt.draw(false);
            const order = dt.rows({ order: 'applied', search: 'applied' }).indexes().toArray();
            const pos = order.indexOf(idx);
            if (pos >= 0) dt.page(Math.floor(pos / dt.page.len())).draw(false);
        } else {
            dt.draw(false);
        }
    }
    const tr = document.querySelector(`#appTableBody tr.app-row[data-app-id="${id}"]`);
    if (tr) expandApp(id, tr, false);
}

function applicantHeader(app) {
    return `<div class="d-flex flex-wrap gap-4 mb-3 pb-3 border-bottom">
        <div><div class="small text-muted">Proprietor</div><div class="fw-semibold">${esc(app.proprietor_name)}</div></div>
        <div><div class="small text-muted">Contact</div><div class="fw-semibold">${esc(app.contact_number)}</div></div>
        <div><div class="small text-muted">Email</div><div class="fw-semibold">${esc(app.email)}</div></div>
        <div><div class="small text-muted">Meeting</div><div class="fw-semibold">${fmtDateTime(app.meetup_scheduled_at)}</div>
            ${app.status === 'pending_verification' && app.meetup_scheduled_at ? `<button type="button" class="btn btn-link btn-sm p-0 text-success fw-semibold" onclick="openReschedule(${app.id})"><i class="fa-solid fa-calendar-pen me-1"></i>Reschedule</button>` : ''}</div>
        ${app.preferred_stall_id ? `<div><div class="small text-muted">Preferred Stall</div><div class="fw-semibold">${esc(app.preferred_stall_id)}</div></div>` : ''}
    </div>`;
}

// Mirrors stall_app_payment_complete() in the API.
function paymentComplete(app) {
    return parseFloat(app.deposit_amount) > 0
        && parseFloat(app.advance_amount) > 0
        && !!app.rental_start_date
        && (app.payment_schedule_day == 15 || app.payment_schedule_day == 30);
}

function renderWorkspace(app) {
    const hasContract = !!app.contract_file;
    const payDone = paymentComplete(app);
    /* The first unmet prerequisite becomes the button's label, so the reason it
       is disabled is readable without clicking it. */
    const awardBlocker = !hasContract ? 'Upload the contract first'
        : !payDone ? 'Record the payment first'
        : !VACANT_STALLS.length ? 'No vacant stalls'
        : '';
    const contractUrl = hasContract ? DOC_URL + encodeURIComponent(String(app.contract_file).replace(/^\//, '')) : '';

    const stallOpts = VACANT_STALLS.map(s =>
        `<option value="${esc(s.stall_id)}" data-rate="${s.monthly_rate}" ${s.stall_id === app.preferred_stall_id ? 'selected' : ''}>${esc(s.stall_id)} — ${esc(s.label)} (${peso(s.monthly_rate)}/mo)</option>`).join('');

    return `${applicantHeader(app)}
    <div class="row g-3">
        <div class="col-12">
            <div class="sa-section">
                <h6><i class="fa-solid fa-folder-open text-success me-2"></i>Submitted Documents <span class="small text-muted fw-normal">— compare against the originals the applicant brought</span></h6>
                ${docGrid(app)}
            </div>
        </div>

        <div class="col-lg-6">
            <div class="sa-section h-100">
                <h6><i class="fa-solid fa-file-signature text-success me-2"></i>Signed Contract ${hasContract ? '<span class="sa-done-pill ms-1">Uploaded</span>' : ''}</h6>
                ${hasContract ? `<p class="small mb-2"><a href="${contractUrl}" class="sa-doc-link" data-doc-url="${contractUrl}" data-doc-label="Signed Contract"><i class="fa-solid fa-eye me-1"></i>View uploaded contract</a></p>` : '<p class="small text-muted">Upload the scanned contract after the applicant signs it.</p>'}
                <input type="file" class="form-control form-control-sm mb-2" id="contractFile-${app.id}" accept=".pdf,.jpg,.jpeg,.png">
                <button type="button" class="btn btn-sm btn-success" id="contractBtn-${app.id}"><i class="fa-solid fa-upload me-1"></i>${hasContract ? 'Replace Contract' : 'Upload Contract'}</button>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="sa-section h-100">
                <h6><i class="fa-solid fa-money-check-dollar text-success me-2"></i>Payment ${payDone ? '<span class="sa-done-pill ms-1">Recorded</span>' : ''}</h6>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small mb-1">2-Month Deposit</label>
                        <div class="input-group input-group-sm"><span class="input-group-text">₱</span>
                            <input type="number" min="0" step="0.01" class="form-control" id="dep-${app.id}" value="${app.deposit_amount ?? ''}"></div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">1-Month Advance</label>
                        <div class="input-group input-group-sm"><span class="input-group-text">₱</span>
                            <input type="number" min="0" step="0.01" class="form-control" id="adv-${app.id}" value="${app.advance_amount ?? ''}"></div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Rental Start Date</label>
                        <input type="date" class="form-control form-control-sm" id="start-${app.id}" value="${app.rental_start_date ?? ''}">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">Recurring Payment</label>
                        <select class="form-select form-select-sm" id="sched-${app.id}">
                            <option value="">Choose…</option>
                            <option value="15" ${app.payment_schedule_day == 15 ? 'selected' : ''}>Every 15th</option>
                            <option value="30" ${app.payment_schedule_day == 30 ? 'selected' : ''}>Every 30th</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="button" class="btn btn-sm btn-success mt-1" id="payBtn-${app.id}"><i class="fa-solid fa-floppy-disk me-1"></i>Save Payment</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="sa-section">
                <h6><i class="fa-solid fa-store text-success me-2"></i>Assign Stall &amp; Finalize</h6>
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Stall <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="awardStall-${app.id}" ${VACANT_STALLS.length ? '' : 'disabled'}>
                            ${stallOpts || '<option value="">No vacant stalls available</option>'}
                        </select>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="askReason(${app.id})"><i class="fa-solid fa-circle-xmark me-1"></i>Reject</button>
                        <button type="button" class="btn btn-success btn-sm" id="awardBtn-${app.id}"${awardBlocker ? ' disabled' : ''}>
                            <i class="fa-solid fa-award me-1"></i>${awardBlocker || 'Award'}
                        </button>
                    </div>
                </div>
                <ul class="sa-checklist mt-3" id="awardChecklist-${app.id}">
                    <li class="${hasContract ? 'is-done' : ''}">
                        <i class="fa-solid ${hasContract ? 'fa-circle-check' : 'fa-circle'}"></i>
                        Signed contract uploaded
                    </li>
                    <li class="${payDone ? 'is-done' : ''}">
                        <i class="fa-solid ${payDone ? 'fa-circle-check' : 'fa-circle'}"></i>
                        Deposit, advance, start date and schedule recorded
                    </li>
                    <li class="${VACANT_STALLS.length ? '' : 'is-blocked'}">
                        <i class="fa-solid ${VACANT_STALLS.length ? 'fa-circle' : 'fa-circle-exclamation'}"></i>
                        ${VACANT_STALLS.length ? 'Pick a stall above' : 'No vacant stalls available'}
                    </li>
                </ul>
            </div>
        </div>
    </div>`;
}

function renderSummary(app) {
    const contractUrl = app.contract_file ? DOC_URL + encodeURIComponent(String(app.contract_file).replace(/^\//, '')) : '';
    let outcome = '';
    if (app.status === 'awarded') {
        outcome = `<div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i>Awarded <strong>Stall ${esc(app.stall_id)}</strong> on ${fmtDateTime(app.awarded_at)}.</div>
        <div class="row g-3">
            <div class="col-md-6"><div class="sa-section h-100"><h6>Payment</h6>
                <div class="d-flex justify-content-between"><span class="text-muted">2-Month Deposit</span><span class="fw-semibold">${peso(app.deposit_amount)}</span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">1-Month Advance</span><span class="fw-semibold">${peso(app.advance_amount)}</span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Rental Start</span><span class="fw-semibold">${fmtDate(app.rental_start_date)}</span></div>
                <div class="d-flex justify-content-between"><span class="text-muted">Recurring</span><span class="fw-semibold">Every ${esc(app.payment_schedule_day)}th</span></div>            </div></div>
            <div class="col-md-6"><div class="sa-section h-100"><h6>Contract</h6>
                ${contractUrl ? `<a href="${contractUrl}" class="btn btn-sm btn-outline-success sa-doc-link" data-doc-url="${contractUrl}" data-doc-label="Signed Contract"><i class="fa-solid fa-eye me-1"></i>View signed contract</a>` : '<span class="text-muted">No contract on file.</span>'}
                <div class="small text-muted mt-2">Merchant login: ${esc(app.email)}</div>
            </div></div>
        </div>`;
    } else if (app.status === 'rejected') {
        outcome = `<div class="alert alert-danger"><i class="fa-solid fa-circle-xmark me-1"></i>Rejected on ${fmtDateTime(app.reviewed_at)}.</div>
            <div class="sa-section"><h6>Reason</h6><p class="mb-0">${esc(app.rejection_reason || '—')}</p></div>`;
    } else if (app.status === 'cancelled') {
        outcome = `<div class="alert alert-secondary"><i class="fa-solid fa-ban me-1"></i>Cancelled on ${fmtDateTime(app.cancelled_at)}. The meeting slot was released.</div>
            <div class="sa-section"><h6>Reason</h6><p class="mb-0">${esc(app.cancel_reason || '—')}</p></div>`;
    } else if (app.status === 'expired') {
        outcome = `<div class="alert alert-secondary"><i class="fa-solid fa-user-clock me-1"></i>Expired — the applicant did not attend the verification meeting scheduled for ${fmtDateTime(app.meetup_scheduled_at)}.</div>
            <div class="sa-section"><h6>Reason</h6><p class="mb-0">${esc(app.rejection_reason || '—')}</p></div>`;
    }
    return `${applicantHeader(app)}${outcome}
        <div class="sa-section mt-3"><h6>Submitted Documents</h6>${docGrid(app)}</div>`;
}

function wireWorkspace(app) {
    const id = app.id;
    // Prefill deposit/advance from the selected stall's monthly rate when empty.
    const stallSel = document.getElementById(`awardStall-${id}`);
    const dep = document.getElementById(`dep-${id}`);
    const adv = document.getElementById(`adv-${id}`);
    function suggest() {
        const opt = stallSel?.selectedOptions?.[0];
        const rate = opt ? parseFloat(opt.dataset.rate || 0) : 0;
        if (rate > 0) {
            if (!dep.value) dep.value = (rate * 2).toFixed(2);
            if (!adv.value) adv.value = rate.toFixed(2);
        }
    }
    if (stallSel) { stallSel.addEventListener('change', suggest); suggest(); }

    document.getElementById(`contractBtn-${id}`).addEventListener('click', () => {
        const input = document.getElementById(`contractFile-${id}`);
        if (!input.files.length) { toast('Choose the signed contract file first.', false); return; }
        const fd = new FormData();
        fd.append('action', 'upload_contract');
        fd.append('app_id', id);
        fd.append('contract', input.files[0]);
        post(fd, true).then(res => {
            toast(res.message, res.success);
            if (res.success) { app.contract_file = res.contract_file; refreshApp(id); }
        });
    });

    document.getElementById(`payBtn-${id}`).addEventListener('click', () => {
        post({
            action: 'record_payment', app_id: id,
            deposit_amount: dep.value, advance_amount: adv.value,
            rental_start_date: document.getElementById(`start-${id}`).value,
            payment_schedule_day: document.getElementById(`sched-${id}`).value,
        }).then(res => {
            toast(res.message, res.success);
            if (res.success) {
                app.deposit_amount = dep.value;
                app.advance_amount = adv.value;
                app.rental_start_date = document.getElementById(`start-${id}`).value;
                app.payment_schedule_day = document.getElementById(`sched-${id}`).value;
                refreshApp(id);
            }
        });
    });

    document.getElementById(`awardBtn-${id}`).addEventListener('click', () => {
        const stallId = document.getElementById(`awardStall-${id}`).value;
        if (!stallId) { toast('Pick a stall in the dropdown before awarding.', false); return; }
        awardAppId = id; awardStallId = stallId;
        document.getElementById('awardConfirmText').innerHTML =
            `Award Stall <strong>${esc(stallId)}</strong> to <strong>${esc(app.proprietor_name)}</strong>?` +
            `<span class="d-block mt-2 text-muted">This creates their merchant account, opens the lease on the rent roll, ` +
            `marks the stall occupied, and emails their login details. It cannot be undone.</span>`;
        awardModal.show();
    });
}
document.getElementById('awardConfirm').addEventListener('click', function () {
    if (!awardAppId || !awardStallId) return;
    if (!gpBusy(this, 'Awarding…')) return;
    const btn = this;
    post({ action: 'award', app_id: awardAppId, stall_id: awardStallId }).then(res => {
        toast(res.message, res.success);
        if (res.success) { awardModal.hide(); setTimeout(() => location.reload(), 1200); return; }
        gpBusyDone(btn);
    });
});

// ── Reschedule modal — finance moves the system-assigned meeting ──
function slotLabel(t) {
    const [h, m] = t.split(':').map(Number);
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${h12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
}
function localYmd(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function openReschedule(id) {
    resAppId = id;
    const app = findApp(id);
    if (!app) return;
    document.getElementById('resCurrent').innerHTML =
        `<strong>${esc(app.business_name)}</strong> is currently scheduled for <strong>${fmtDateTime(app.meetup_scheduled_at)}</strong>. Pick the new slot below.`;
    const dateEl = document.getElementById('resDate');
    dateEl.min = localYmd(new Date());
    dateEl.value = '';
    document.getElementById('resTime').innerHTML = '<option value="">Pick a date first&hellip;</option>';
    document.getElementById('resTime').disabled = true;
    document.getElementById('resHint').innerHTML = '';
    document.getElementById('resConfirm').disabled = true;
    rescheduleModal.show();
}
function loadResSlots() {
    const date = document.getElementById('resDate').value;
    const timeEl = document.getElementById('resTime');
    const hint = document.getElementById('resHint');
    document.getElementById('resConfirm').disabled = true;
    timeEl.disabled = true;
    hint.innerHTML = '';
    if (!date) { timeEl.innerHTML = '<option value="">Pick a date first&hellip;</option>'; return; }
    timeEl.innerHTML = '<option value="">Loading&hellip;</option>';
    post({ action: 'meeting_slots', app_id: resAppId, date }).then(res => {
        if (!res.success) { timeEl.innerHTML = '<option value="">—</option>'; toast(res.message, false); return; }
        if (res.weekend || res.holiday) {
            timeEl.innerHTML = '<option value="">—</option>';
            hint.innerHTML = `<span class="text-danger"><i class="fa-solid fa-circle-exclamation me-1"></i>${res.weekend ? 'Meetings cannot be scheduled on weekends.' : `That date is a holiday (${esc(res.holiday)}).`}</span>`;
            return;
        }
        const app = findApp(resAppId);
        const current = String(app?.meetup_scheduled_at || '').slice(0, 16);
        const now = new Date();
        let free = 0;
        const opts = res.slots.map(t => {
            const isCurrent = current === `${date} ${t}`;
            const taken = res.booked.includes(t);
            const past = new Date(`${date}T${t}:00`) <= now;
            const open = !isCurrent && !taken && !past;
            if (open) free++;
            const note = isCurrent ? ' (current)' : taken ? ' — taken' : past ? ' — past' : '';
            return `<option value="${t}" ${open ? '' : 'disabled'}>${slotLabel(t)}${note}</option>`;
        }).join('');
        timeEl.innerHTML = '<option value="">Choose a time&hellip;</option>' + opts;
        timeEl.disabled = false;
        hint.innerHTML = free
            ? `<span class="text-success">${free} slot${free === 1 ? '' : 's'} available on this date.</span>`
            : '<span class="text-danger">No free slots on this date — pick another day.</span>';
    });
}
document.getElementById('resDate').addEventListener('change', loadResSlots);
document.getElementById('resTime').addEventListener('change', () => {
    document.getElementById('resConfirm').disabled = !document.getElementById('resTime').value;
});
document.getElementById('resConfirm').addEventListener('click', () => {
    const date = document.getElementById('resDate').value;
    const time = document.getElementById('resTime').value;
    if (!resAppId || !date || !time) return;
    post({ action: 'reschedule_meeting', app_id: resAppId, meeting_date: date, meeting_time: time }).then(res => {
        toast(res.message, res.success);
        if (res.success) { rescheduleModal.hide(); setTimeout(() => location.reload(), 1200); }
    });
});

// ── Reject modal ──
function askReason(id) {
    reasonAppId = id;
    document.getElementById('reasonTitle').textContent = 'Reject Application';
    document.getElementById('reasonHelp').textContent = 'The application will be terminated. The applicant must submit a brand-new application.';
    document.getElementById('reasonText').value = '';
    document.getElementById('reasonConfirm').className = 'btn btn-danger';
    reasonModal.show();
}
document.getElementById('reasonConfirm').addEventListener('click', () => {
    const reason = document.getElementById('reasonText').value.trim();
    if (!reason) { toast('A rejection reason is required.', false); return; }
    post({ action: 'reject', app_id: reasonAppId, reason }).then(res => {
        toast(res.message, res.success);
        if (res.success) { reasonModal.hide(); setTimeout(() => location.reload(), 1200); }
    });
});

// ── DataTable + custom search / status-filter wiring ──
let statusFilter = 'all';
jQuery(function ($) {
    if (!$.fn || !$.fn.DataTable) return;                 // assets blocked — leave static table
    if ($.fn.dataTable.isDataTable('#appsTable')) return;
    const $table = $('#appsTable');
    if (!$table.length) return;

    // Drop the "No applications yet." placeholder row so DataTables parses cleanly.
    $table.find('tbody > tr').filter(function () {
        const $c = $(this).children('td, th');
        return $c.length === 1 && parseInt($c.attr('colspan'), 10) > 1;
    }).remove();

    // Status-pill filter — reads data-status off each row; scoped to this table.
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        if (settings.nTable.id !== 'appsTable' || statusFilter === 'all') return true;
        const row = settings.aoData[dataIndex].nTr;
        return !!row && row.dataset.status === statusFilter;
    });

    dt = $table.DataTable({
        pageLength: 10,
        lengthChange: false,
        autoWidth: false,
        order: [[5, 'desc']],   // Date Submitted — newest first
        columnDefs: [{ targets: 6, orderable: false, searchable: false }],
        language: {
            emptyTable: 'No applications yet.',
            zeroRecords: 'No matching applications found.',
            info: 'Showing _START_&ndash;_END_ of _TOTAL_',
            infoEmpty: 'No applications',
            infoFiltered: '(filtered from _MAX_)',
            paginate: { previous: 'Prev', next: 'Next' },
        },
        dom: "t<'d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top sa-dt-foot'ip>",
    });

    // The redesigned pill search drives DataTables' search instead of its default box.
    $('#appSearch').on('input', function () { collapseAllDetails(); dt.search(this.value).draw(); });

    // Status filter pills.
    $('.filter-btn').on('click', function () {
        statusFilter = this.dataset.status;
        $('.filter-btn').removeClass('active');
        $(this).addClass('active');
        collapseAllDetails();
        dt.draw();
    });
});
</script>
</body>
</html>
