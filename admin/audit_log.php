<?php
require_once __DIR__ . "/../connection/config.php";
require_once __DIR__ . "/../connection/pdo.php";
require_once __DIR__ . "/../connection/app.php";
require_once __DIR__ . "/../connection/audit_logger.php";

// The audit trail records what every other administrator did, and its payloads
// carry student and parent names and email addresses. "Any finance account" is
// too wide a door for that — a cashier had the same view as the super-admin.
gjc_require_role(["finance"]);
if (gjc_sub_role() !== "super_admin") {
    http_response_code(403);
    $currentUser = gjc_current_user($db);
    require __DIR__ . "/../includes/partials/audit_forbidden.php";
    exit;
}
gjc_ensure_audit_table($db);
$currentUser = gjc_current_user($db);

[$auditHealthOk, $auditHealthMessage] = gjc_audit_health($db);

$roles = ["", "Finance", "Student", "Merchant", "Vendor/Staff", "Parent", "System", "Anonymous", "Unknown"];

// Built from the one list in audit_logger.php, legacy labels included so the
// rows they already wrote stay reachable. This used to be a third hardcoded
// copy that drifted: ENROLLMENT_STATUS_SET, ROSTER_UPLOADED, ROSTER_APPLIED and
// TUITION_CREDIT all existed in the table but could not be selected here.
$actions = array_merge([""], gjc_audit_action_types(true));

$outcomes = ["", "success", "failure", "denied", "unconfirmed"];

$userRole      = (string) ($_GET["user_role"] ?? "");
$actionType    = (string) ($_GET["action_type"] ?? "");
$outcome       = (string) ($_GET["outcome"] ?? "");
$actorQuery    = trim((string) ($_GET["actor"] ?? ""));
$targetQuery   = trim((string) ($_GET["target"] ?? ""));
$correlationId = trim((string) ($_GET["correlation_id"] ?? ""));
$dateFrom      = trim((string) ($_GET["date_from"] ?? ""));
$dateTo        = trim((string) ($_GET["date_to"] ?? ""));
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

if (!in_array($userRole, $roles, true)) {
    $userRole = "";
}
if (!in_array($actionType, $actions, true)) {
    $actionType = "";
}
if (!in_array($outcome, $outcomes, true)) {
    $outcome = "";
}
// A correlation id is always 32 hex characters; anything else is not one.
if ($correlationId !== "" && !preg_match('/^[0-9a-f]{32}$/i', $correlationId)) {
    $correlationId = "";
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
if ($outcome !== "") {
    $where[] = "sat.outcome = ?";
    $params[] = $outcome;
}
if ($correlationId !== "") {
    $where[] = "sat.correlation_id = ?";
    $params[] = $correlationId;
}
if ($actorQuery !== "") {
    // Name, email, or a bare user id.
    $where[] = "(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) LIKE ?"
        . " OR u.email LIKE ? OR sat.user_id = ?)";
    $params[] = "%" . $actorQuery . "%";
    $params[] = "%" . $actorQuery . "%";
    $params[] = ctype_digit($actorQuery) ? (int) $actorQuery : 0;
}
if ($targetQuery !== "") {
    $where[] = "(sat.target_id LIKE ? OR sat.affected_table LIKE ?)";
    $params[] = "%" . $targetQuery . "%";
    $params[] = "%" . $targetQuery . "%";
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
    "SELECT COUNT(*) FROM systemic_audit_trail sat
       LEFT JOIN users u ON u.userID = sat.user_id
     {$whereSql}",
);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "SELECT sat.*,
               UNIX_TIMESTAMP(sat.timestamp) AS ts_epoch,
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
                from_wallet.user_id AS from_user_id,
                to_wallet.user_id AS to_user_id,
                TRIM(CONCAT(COALESCE(from_user.first_name, ''), ' ', COALESCE(from_user.last_name, ''))) AS from_name,
                from_user.email AS from_email,
                TRIM(CONCAT(COALESCE(to_user.first_name, ''), ' ', COALESCE(to_user.last_name, ''))) AS to_name,
                to_user.email AS to_email
           FROM p2p_transfers p
           LEFT JOIN student_wallets from_wallet ON from_wallet.id = p.from_wallet_id
           LEFT JOIN student_wallets to_wallet ON to_wallet.id = p.to_wallet_id
           LEFT JOIN users from_user ON from_user.userID = from_wallet.user_id
           LEFT JOIN users to_user ON to_user.userID = to_wallet.user_id
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
    "outcome" => $outcome,
    "actor" => $actorQuery,
    "target" => $targetQuery,
    "correlation_id" => $correlationId,
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

    $outcome = (string) ($log["outcome"] ?? "success");
    if ($outcome !== "success") {
        $rowClass .= " audit-row-" . $outcome;
    }

    // A NULL actor is a real value now, not missing data: pre-auth attempts and
    // CLI/system events have no user to point at.
    $actorId = $log["user_id"];
    $actor = trim((string) ($log["actor_name"] ?: $log["actor_email"] ?: ""));
    if ($actor === "") {
        $actor = $actorId === null
            ? (($log["user_role"] ?? "") === "System" ? "System" : "Anonymous")
            : "User #" . $actorId;
    }

    $isLegacy = gjc_audit_is_legacy_row($log);
    $target = trim((string) ($log["target_id"] ?? ""));

    ob_start();
    ?>
    <tr class="<?= audit_e(trim($rowClass)) ?>">
        <td><?= audit_e(gjc_audit_manila(
            isset($log["ts_epoch"]) ? (int) $log["ts_epoch"] : null,
        )) ?></td>
        <td>
            <strong><?= audit_e($actor) ?></strong><br>
            <small><?= audit_e((string) ($log["actor_email"] ?? "")) ?></small>
        </td>
        <td>
            <?= audit_e($log["user_role"]) ?>
            <?php if (!empty($log["sub_role"])): ?>
            <br><small><?= audit_e($log["sub_role"]) ?></small>
            <?php endif; ?>
        </td>
        <td><span class="audit-pill <?= audit_e($log["action_type"]) ?>"><?= audit_e($log["action_type"]) ?></span></td>
        <td><span class="audit-outcome is-<?= audit_e($outcome) ?>"><?= audit_e($outcome) ?></span></td>
        <td>
            <?= audit_e(gjc_audit_table_label($log["affected_table"])) ?>
            <?php if ($target !== ""): ?>
            <br><small class="audit-target"><?= audit_e($target) ?></small>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($isLegacy): ?>
            <span class="audit-legacy" title="Written before the hash chain existed — this entry cannot be cryptographically verified.">legacy (unchained)</span>
            <?php endif; ?>
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

// "Verify chain" button. Recomputes every chained entry's hash and reports the
// first that does not add up — see gjc_audit_verify_chain().
if (($_GET["verify"] ?? "") === "1") {
    header("Content-Type: application/json");
    echo json_encode(gjc_audit_verify_chain());
    exit;
}

if (($_GET["ajax"] ?? "") === "1") {
    header("Content-Type: application/json");
    echo json_encode([
        "success" => true,
        "rows_html" => $logs
            ? implode("", array_map("audit_render_row", $logs))
            : '<tr><td colspan="7" class="text-center py-4">No audit records matched the selected filters.</td></tr>',
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-table-cards.css?v=3">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/audit_log.css?v=10">
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

            <?php if (!$auditHealthOk): ?>
            <div class="audit-banner is-danger">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>Audit logging is not healthy.</strong>
                    <span><?= audit_e($auditHealthMessage) ?></span>
                </div>
            </div>
            <?php endif; ?>

            <section class="audit-panel mb-4">
                <div class="audit-chain-bar">
                    <div>
                        <strong>Tamper check</strong>
                        <span class="audit-chain-hint">Recomputes the hash of every chained entry and reports the first that does not match.</span>
                    </div>
                    <div class="audit-chain-actions">
                        <span id="chainResult" class="audit-chain-result"></span>
                        <button type="button" class="audit-filter-btn" id="verifyChainBtn">
                            <i class="fa-solid fa-shield-halved"></i> Verify chain
                        </button>
                    </div>
                </div>
            </section>

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
                        <label>Outcome</label>
                        <select name="outcome">
                            <?php foreach ($outcomes as $outcomeOption): ?>
                            <option value="<?= audit_e($outcomeOption) ?>" <?= $outcome === $outcomeOption ? "selected" : "" ?>>
                                <?= $outcomeOption === "" ? "All outcomes" : audit_e(ucfirst($outcomeOption)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="audit-field">
                        <label>Actor</label>
                        <input type="text" name="actor" placeholder="Name, email, or user ID"
                               value="<?= audit_e($actorQuery) ?>">
                    </div>
                    <div class="audit-field">
                        <label>Target</label>
                        <input type="text" name="target" placeholder="Record ID or table"
                               value="<?= audit_e($targetQuery) ?>">
                    </div>
                    <div class="audit-field">
                        <label>Correlation ID</label>
                        <input type="text" name="correlation_id" placeholder="Groups one action's entries"
                               value="<?= audit_e($correlationId) ?>">
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
                                <th data-card="hide">Time</th>
                                <th data-card="title">Actor</th>
                                <th data-card="hide">Role</th>
                                <th>Action</th>
                                <th>Outcome</th>
                                <th data-card="hide">Table / Target</th>
                                <th data-card="hide">Details</th>
                            </tr>
                        </thead>
                        <tbody id="auditLogBody">
                            <?php if (!$logs): ?>
                            <tr><td colspan="7" class="text-center py-4">No audit records matched the selected filters.</td></tr>
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
    <script src="<?= JS_URL ?>/gjc_table_cards.js?v=1"></script>
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

    // ── Tamper check ───────────────────────────────────────────────────────────
    const verifyChainBtn = document.getElementById("verifyChainBtn");
    const chainResult = document.getElementById("chainResult");

    verifyChainBtn.addEventListener("click", async () => {
        verifyChainBtn.disabled = true;
        chainResult.className = "audit-chain-result is-checking";
        chainResult.textContent = "Checking\u2026";
        try {
            const res = await fetch(window.location.pathname + "?verify=1");
            const data = await res.json();
            if (data.ok) {
                chainResult.className = "audit-chain-result is-ok";
                chainResult.textContent = data.checked === 0
                    ? "No chained entries yet \u2014 every row predates the hash chain."
                    : "Intact \u2014 " + data.checked + " entr" + (data.checked === 1 ? "y" : "ies") +
                      " verified (#" + data.first_log_id + "\u2013#" + data.last_log_id + ").";
            } else {
                chainResult.className = "audit-chain-result is-bad";
                chainResult.textContent = (data.broken_log_id ? "Entry #" + data.broken_log_id + ": " : "") +
                    (data.reason || "Verification failed.");
            }
        } catch (error) {
            chainResult.className = "audit-chain-result is-bad";
            chainResult.textContent = "Could not run the check.";
        } finally {
            verifyChainBtn.disabled = false;
        }
    });

    lastAuditTotalRows = <?= (int) $totalRows ?>;
    setInterval(refreshAuditLog, 5000);
    </script>
</body>
</html>
