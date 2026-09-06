<?php
require_once __DIR__ . "/../connection/config.php";
require_once __DIR__ . "/../connection/pdo.php";
require_once __DIR__ . "/../connection/app.php";
require_once __DIR__ . "/../connection/audit_logger.php";

gjc_require_role(["finance"]);
gjc_ensure_audit_table($db);
$currentUser = gjc_current_user($db);

$roles = ["", "Finance", "Student", "Merchant", "Vendor/Staff"];
$actions = [
    "",
    "LOGIN",
    "LOGOUT",
    "PASSWORD_CHANGE",
    "TRANSACTION",
    "MENU_MUTATION",
    "STALL_UPDATE",
    "USER_IMPORT",
    "MERCHANT_CREATE",
    "USER_ACCOUNT",
    "MERCHANT_ONBOARDING",
    "PRODUCT_RESTRICTION",
    "LOGIN_FAILED",
    "FEE_WAIVER_STATUS_CHANGE",
    "SCHOOL_YEAR_CREATED",
    "SCHOOL_YEAR_ROLLOVER",
    "STUDENT_GRADUATED",
    "SY_TXN_BACKFILL",
    "SYSTEM_SETTINGS_CHANGE",
    "MINT_PIN_SET",
];

$userRole = (string) ($_GET["user_role"] ?? "");
$actionType = (string) ($_GET["action_type"] ?? "");
$dateFrom = trim((string) ($_GET["date_from"] ?? ""));
$dateTo = trim((string) ($_GET["date_to"] ?? ""));
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

if (!in_array($userRole, $roles, true)) {
    $userRole = "";
}
if (!in_array($actionType, $actions, true)) {
    $actionType = "";
}

$where = [];
$params = [];

if ($userRole !== "") {
    $where[] = "sat.user_role = ?";
    $params[] = $userRole;
}
if ($actionType !== "") {
    $where[] = "sat.action_type = ?";
    $params[] = $actionType;
}
if ($dateFrom !== "") {
    $where[] = "DATE(sat.timestamp) >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== "") {
    $where[] = "DATE(sat.timestamp) <= ?";
    $params[] = $dateTo;
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM systemic_audit_trail sat {$whereSql}",
);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "SELECT sat.*,
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS actor_name,
               u.email AS actor_email
          FROM systemic_audit_trail sat
          LEFT JOIN users u ON u.userID = sat.user_id
          {$whereSql}
         ORDER BY sat.timestamp DESC, sat.log_id DESC
         LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$transactionRefs = [];
foreach ($logs as $log) {
    if (($log["action_type"] ?? "") !== "TRANSACTION") {
        continue;
    }

    $payload = json_decode((string) ($log["new_value"] ?? ""), true);
    if (is_array($payload) && !empty($payload["reference_no"])) {
        $transactionRefs[] = (string) $payload["reference_no"];
    }
}
$transactionRefs = array_values(array_unique($transactionRefs));
$transactionDetailsByRef = [];
$p2pDetailsByRef = [];

if ($transactionRefs && gjc_table_exists($db, "transactions")) {
    $placeholders = implode(",", array_fill(0, count($transactionRefs), "?"));
    $txnStmt = $db->prepare(
        "SELECT t.*,
                TRIM(CONCAT(COALESCE(actor.first_name, ''), ' ', COALESCE(actor.last_name, ''))) AS initiated_by_name,
                actor.email AS initiated_by_email,
                sw.user_id AS student_user_id,
                TRIM(CONCAT(COALESCE(student.first_name, ''), ' ', COALESCE(student.last_name, ''))) AS student_name,
                student.email AS student_email,
                mw.user_id AS merchant_user_id,
                TRIM(CONCAT(COALESCE(merchant.first_name, ''), ' ', COALESCE(merchant.last_name, ''))) AS merchant_name,
                merchant.email AS merchant_email
           FROM transactions t
           LEFT JOIN users actor ON actor.userID = t.initiated_by
           LEFT JOIN student_wallets sw ON sw.id = t.student_wallet_id
           LEFT JOIN users student ON student.userID = sw.user_id
           LEFT JOIN merchant_wallets mw ON mw.id = t.merchant_wallet_id
           LEFT JOIN users merchant ON merchant.userID = mw.user_id
          WHERE t.reference_no IN ({$placeholders})",
    );
    $txnStmt->execute($transactionRefs);

    foreach ($txnStmt->fetchAll(PDO::FETCH_ASSOC) as $txn) {
        $transactionDetailsByRef[(string) $txn["reference_no"]] = [
            "ledger_id" => (int) $txn["id"],
            "reference_no" => (string) $txn["reference_no"],
            "transaction_type" => (string) $txn["transaction_type"],
            "amount" => (float) $txn["amount"],
            "status" => (string) $txn["status"],
            "created_at" => (string) $txn["created_at"],
            "created_at_label" => date(
                "M j, Y g:i A",
                strtotime((string) $txn["created_at"]),
            ),
            "initiated_by" => [
                "user_id" => (int) $txn["initiated_by"],
                "name" => trim(
                    (string) ($txn["initiated_by_name"] ?:
                    "User #" . $txn["initiated_by"]),
                ),
                "email" => (string) ($txn["initiated_by_email"] ?? ""),
            ],
            "student" => [
                "wallet_id" =>
                    $txn["student_wallet_id"] !== null
                        ? (int) $txn["student_wallet_id"]
                        : null,
                "user_id" =>
                    $txn["student_user_id"] !== null
                        ? (int) $txn["student_user_id"]
                        : null,
                "name" => trim((string) ($txn["student_name"] ?? "")),
                "email" => (string) ($txn["student_email"] ?? ""),
            ],
            "merchant" => [
                "wallet_id" =>
                    $txn["merchant_wallet_id"] !== null
                        ? (int) $txn["merchant_wallet_id"]
                        : null,
                "user_id" =>
                    $txn["merchant_user_id"] !== null
                        ? (int) $txn["merchant_user_id"]
                        : null,
                "name" => trim((string) ($txn["merchant_name"] ?? "")),
                "email" => (string) ($txn["merchant_email"] ?? ""),
            ],
            "voucher_id" =>
                $txn["voucher_id"] !== null ? (int) $txn["voucher_id"] : null,
            "vault_before" => (float) $txn["vault_before"],
            "vault_after" => (float) $txn["vault_after"],
            "total_in_circulation" => (float) $txn["total_in_circulation"],
            "notes" => (string) ($txn["notes"] ?? ""),
        ];
    }
}

if ($transactionRefs && gjc_table_exists($db, "p2p_transfers")) {
    $placeholders = implode(",", array_fill(0, count($transactionRefs), "?"));
    $p2pStmt = $db->prepare(
        "SELECT p.*,
                TRIM(CONCAT(COALESCE(from_user.first_name, ''), ' ', COALESCE(from_user.last_name, ''))) AS from_name,
                from_user.email AS from_email,
                TRIM(CONCAT(COALESCE(to_user.first_name, ''), ' ', COALESCE(to_user.last_name, ''))) AS to_name,
                to_user.email AS to_email
           FROM p2p_transfers p
           LEFT JOIN users from_user ON from_user.userID = p.from_user_id
           LEFT JOIN users to_user ON to_user.userID = p.to_user_id
          WHERE p.reference_no IN ({$placeholders})",
    );
    $p2pStmt->execute($transactionRefs);

    foreach ($p2pStmt->fetchAll(PDO::FETCH_ASSOC) as $p2p) {
        $p2pDetailsByRef[(string) $p2p["reference_no"]] = [
            "from" => [
                "wallet_id" => (int) $p2p["from_wallet_id"],
                "user_id" => (int) $p2p["from_user_id"],
                "name" => trim(
                    (string) ($p2p["from_name"] ?:
                    "User #" . $p2p["from_user_id"]),
                ),
                "email" => (string) ($p2p["from_email"] ?? ""),
            ],
            "to" => [
                "wallet_id" => (int) $p2p["to_wallet_id"],
                "user_id" => (int) $p2p["to_user_id"],
                "name" => trim(
                    (string) ($p2p["to_name"] ?: "User #" . $p2p["to_user_id"]),
                ),
                "email" => (string) ($p2p["to_email"] ?? ""),
            ],
            "message" => (string) ($p2p["message"] ?? ""),
            "status" => (string) $p2p["status"],
            "created_at" => (string) $p2p["created_at"],
            "created_at_label" => date(
                "M j, Y g:i A",
                strtotime((string) $p2p["created_at"]),
            ),
        ];
    }
}

foreach ($logs as &$log) {
    $payload = json_decode((string) ($log["new_value"] ?? ""), true);
    $ref = is_array($payload) ? (string) ($payload["reference_no"] ?? "") : "";
    $details = $ref !== "" ? $transactionDetailsByRef[$ref] ?? [] : [];
    if (!$details && is_array($payload) && $ref !== "") {
        $details = [
            "reference_no" => $ref,
            "ledger_id" => null,
            "transaction_type" =>
                (string) ($payload["transaction_type"] ?? "transaction"),
            "amount" => isset($payload["amount"])
                ? (float) $payload["amount"]
                : 0.0,
            "status" => (string) ($payload["status"] ?? ""),
            "created_at" => (string) ($log["timestamp"] ?? ""),
            "created_at_label" => date(
                "M j, Y g:i A",
                strtotime((string) ($log["timestamp"] ?? "now")),
            ),
            "initiated_by" => [
                "user_id" => (int) ($log["user_id"] ?? 0),
                "name" => trim(
                    (string) ($log["actor_name"] ?? "" ?:
                    "User #" . ($log["user_id"] ?? 0)),
                ),
                "email" => (string) ($log["actor_email"] ?? ""),
            ],
            "student" => [
                "wallet_id" => isset($payload["student_wallet_id"])
                    ? (int) $payload["student_wallet_id"]
                    : null,
                "user_id" => isset($payload["student_user_id"])
                    ? (int) $payload["student_user_id"]
                    : null,
                "name" => "",
                "email" => "",
            ],
            "merchant" => [
                "wallet_id" => isset($payload["merchant_wallet_id"])
                    ? (int) $payload["merchant_wallet_id"]
                    : null,
                "user_id" => isset($payload["merchant_user_id"])
                    ? (int) $payload["merchant_user_id"]
                    : null,
                "name" => "",
                "email" => "",
            ],
            "voucher_id" => isset($payload["voucher_id"])
                ? (int) $payload["voucher_id"]
                : null,
            "vault_before" => isset($payload["vault_before"])
                ? (float) $payload["vault_before"]
                : null,
            "vault_after" => isset($payload["vault_after"])
                ? (float) $payload["vault_after"]
                : null,
            "total_in_circulation" => isset($payload["total_in_circulation"])
                ? (float) $payload["total_in_circulation"]
                : null,
            "notes" => (string) ($payload["notes"] ?? ""),
        ];
    }
    if ($details) {
        $details["audit_payload"] = is_array($payload) ? $payload : [];
        if (!empty($payload["items"]) && is_array($payload["items"])) {
            $details["items"] = $payload["items"];
        }
        if ($ref !== "" && isset($p2pDetailsByRef[$ref])) {
            $details["p2p"] = $p2pDetailsByRef[$ref];
            $details["sender"] = $p2pDetailsByRef[$ref]["from"];
            $details["receiver"] = $p2pDetailsByRef[$ref]["to"];
            if (
                empty($details["notes"]) &&
                !empty($p2pDetailsByRef[$ref]["message"])
            ) {
                $details["notes"] = $p2pDetailsByRef[$ref]["message"];
            }
        }
    }
    $log["transaction_details_json"] = $details
        ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : "";
}
unset($log);

$queryBase = [
    "user_role" => $userRole,
    "action_type" => $actionType,
    "date_from" => $dateFrom,
    "date_to" => $dateTo,
];

function audit_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function audit_render_row(array $log): string
{
    $rowClass = $log["action_type"] === "LOGIN" ? "audit-row-login" : "";
    $rowClass = $log["action_type"] === "PASSWORD_CHANGE" ? "audit-row-password" : $rowClass;
    $rowClass = $log["action_type"] === "LOGIN_FAILED" ? "audit-row-login-failed" : $rowClass;
    $actor = trim(
        (string) ($log["actor_name"] ?:
        $log["actor_email"] ?:
        "User #" . $log["user_id"]),
    );

    ob_start();
    ?>
    <tr class="<?= $rowClass ?>">
        <td><?= audit_e(
            date(
                "M j, Y g:i A",
                strtotime((string) $log["timestamp"]),
            ),
        ) ?></td>
        <td>
            <strong><?= audit_e($actor) ?></strong><br>
            <small><?= audit_e(
                (string) ($log["actor_email"] ?? ""),
            ) ?></small>
        </td>
        <td><?= audit_e($log["user_role"]) ?></td>
        <td><span class="audit-pill <?= audit_e(
            $log["action_type"],
        ) ?>"><?= audit_e(
    $log["action_type"],
) ?></span></td>
        <td><?= audit_e($log["affected_table"]) ?></td>
        <td>
            <a class="details-btn"
                href="<?= audit_e(ADMIN_URL) ?>/view_audit.php?token=<?= urlencode(gjc_make_view_token((int) $log["log_id"], "audit")) ?>">
                <i class="fa-solid fa-eye"></i> View
            </a>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function audit_render_pagination(int $page, int $totalPages, int $totalRows, array $queryBase): string
{
    ob_start();
    ?>
    <div class="audit-pagination">
        <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?> &middot; <?= (int) $totalRows ?> records</span>
        <div class="audit-pagination-links">
            <a class="<?= $page <= 1
                ? "disabled"
                : "" ?>" href="<?= audit_e(
    ADMIN_URL,
) ?>/audit_log.php?<?= audit_e(
    http_build_query($queryBase + ["page" => max(1, $page - 1)]),
) ?>"><i class="fa-solid fa-chevron-left"></i> Previous</a>
            <a class="<?= $page >= $totalPages
                ? "disabled"
                : "" ?>" href="<?= audit_e(
    ADMIN_URL,
) ?>/audit_log.php?<?= audit_e(
    http_build_query($queryBase + ["page" => min($totalPages, $page + 1)]),
) ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

if (($_GET["ajax"] ?? "") === "1") {
    header("Content-Type: application/json");
    echo json_encode([
        "success" => true,
        "rows_html" => $logs
            ? implode("", array_map("audit_render_row", $logs))
            : '<tr><td colspan="6" class="text-center py-4">No audit records matched the selected filters.</td></tr>',
        "pagination_html" => audit_render_pagination($page, $totalPages, $totalRows, $queryBase),
        "total_rows" => $totalRows,
    ]);
    exit;
}

$currentPage = "audit_log";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <title>Audit Log | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=35">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/audit_log.css?v=8">
</head>
<body class="gp-theme">
    <div class="admin-layout">
        <?php require __DIR__ . "/../includes/partials/sidebar_admin.php"; ?>

        <main class="admin-main">
            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div>
                    <h1>Systemic Audit Trail</h1>
                    <p>Read-only activity log for authentication, wallet, menu, and stall events.</p>
                </div>
                <div class="admin-user">
                    <span><?= audit_e($currentUser['name'] ?? 'Admin') ?></span>
                    <div class="avatar"><i class="fa-solid fa-user-tie"></i></div>
                </div>
            </header>

            <section class="audit-panel mb-4">
                <form method="GET" action="<?= audit_e(
                    ADMIN_URL,
                ) ?>/audit_log.php" class="audit-filter-grid">
                    <div class="audit-field">
                        <label>User Role</label>
                        <select name="user_role">
                            <?php foreach ($roles as $role): ?>
                            <option value="<?= audit_e(
                                $role,
                            ) ?>" <?= $userRole === $role ? "selected" : "" ?>>
                                <?= $role === ""
                                    ? "All roles"
                                    : audit_e($role) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="audit-field">
                        <label>Action Type</label>
                        <select name="action_type">
                            <?php foreach ($actions as $action): ?>
                            <option value="<?= audit_e(
                                $action,
                            ) ?>" <?= $actionType === $action
    ? "selected"
    : "" ?>>
                                <?= $action === ""
                                    ? "All actions"
                                    : audit_e($action) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="audit-field">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?= audit_e(
                            $dateFrom,
                        ) ?>">
                    </div>
                    <div class="audit-field">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?= audit_e(
                            $dateTo,
                        ) ?>">
                    </div>
                    <div class="d-flex gap-2">
                        <button class="audit-filter-btn" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                        <a class="audit-reset" href="<?= audit_e(
                            ADMIN_URL,
                        ) ?>/audit_log.php"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                    </div>
                </form>
            </section>

            <section class="audit-panel">
                <div class="audit-table-wrap">
                <div class="table-responsive">
                    <table class="table audit-table align-middle">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Actor</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Table</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="auditLogBody">
                            <?php if (!$logs): ?>
                            <tr><td colspan="6" class="text-center py-4">No audit records matched the selected filters.</td></tr>
                            <?php else: foreach ($logs as $log): echo audit_render_row($log);
                            endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
                </div>

                <div id="auditPaginationWrap">
                <div class="audit-pagination">
                    <span>Page <?= (int) $page ?> of <?= (int) $totalPages ?> &middot; <?= (int) $totalRows ?> records</span>
                    <div class="audit-pagination-links">
                        <a class="<?= $page <= 1
                            ? "disabled"
                            : "" ?>" href="<?= audit_e(
    ADMIN_URL,
) ?>/audit_log.php?<?= audit_e(
    http_build_query($queryBase + ["page" => max(1, $page - 1)]),
) ?>"><i class="fa-solid fa-chevron-left"></i> Previous</a>
                        <a class="<?= $page >= $totalPages
                            ? "disabled"
                            : "" ?>" href="<?= audit_e(
    ADMIN_URL,
) ?>/audit_log.php?<?= audit_e(
    http_build_query($queryBase + ["page" => min($totalPages, $page + 1)]),
) ?>">Next <i class="fa-solid fa-chevron-right"></i></a>
                    </div>
                </div>
                </div>
            </section>
        </main>
    </div>


    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }


    // ── Live refresh: pick up new audit entries without a manual reload ─────────
    const auditLogBody = document.getElementById('auditLogBody');
    const auditPaginationWrap = document.getElementById('auditPaginationWrap');
    let lastAuditTotalRows = null;

    function auditFlashBody() {
        auditLogBody.classList.remove('queue-flash');
        void auditLogBody.offsetWidth;
        auditLogBody.classList.add('queue-flash');
    }

    async function refreshAuditLog() {
        try {
            const params = new URLSearchParams(window.location.search);
            params.set('ajax', '1');
            const res = await fetch(window.location.pathname + '?' + params.toString());
            const data = await res.json();
            if (!data.success) return;

            if (lastAuditTotalRows !== null && data.total_rows === lastAuditTotalRows) return;

            const isFirstLoad = lastAuditTotalRows === null;
            lastAuditTotalRows = data.total_rows;
            auditLogBody.innerHTML = data.rows_html;
            auditPaginationWrap.innerHTML = data.pagination_html;
            if (!isFirstLoad) auditFlashBody();
        } catch (error) {
            // Keep showing the last known rows on a transient network error.
        }
    }

    lastAuditTotalRows = <?= (int) $totalRows ?>;
    setInterval(refreshAuditLog, 5000);
    </script>
</body>
</html>
