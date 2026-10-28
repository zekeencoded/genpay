<?php

// Transaction labels, rows and the admin transaction list.
// Loaded by connection/app.php; defines functions and constants only.

function gjc_transaction_type_options(): array
{
    return [
        "" => "All Types",
        "payment" => "Payment",
        "topup" => "Top-up",
        "encashment" => "Encashment",
        "cap_increase" => "Cap Increase",
        "refund" => "Refund",
        "allowance" => "Allowance",
        "fee_waiver_claim" => "Fee Waiver Claim",
    ];
}

function gjc_transaction_status_options(): array
{
    return [
        "" => "All Status",
        "completed" => "Completed",
        "approved" => "Approved",
        "released" => "Released",
        "pending" => "Pending",
        "processing" => "Processing",
        "failed" => "Failed",
        "rejected" => "Rejected",
        "reversed" => "Reversed",
    ];
}

function gjc_transaction_type_label(string $type): string
{
    $labels = [
        "cash_in" => "Top-up",
        "payment" => "Payment",
        "merchant_settle" => "Encashment",
        "cap_increase" => "Cap Increase",
        "refund" => "Refund",
        "topup" => "Top-up",
        "encashment" => "Encashment",
        "allowance" => "Allowance",
        "fee_waiver_claim" => "Fee Waiver Claim",
    ];

    return $labels[$type] ?? ucwords(str_replace("_", " ", $type));
}

function gjc_transaction_type_slug(string $type): string
{
    $map = [
        "cash_in" => "topup",
        "merchant_settle" => "encashment",
    ];

    return $map[$type] ?? strtolower($type);
}

// Icon / colour-slug / direction metadata for a student-facing transaction
// row. Shared by student/dashboard.php and student/api/wallet_summary.php so
// the server-rendered list and the AJAX-refreshed list can't drift apart.
function gjc_student_txn_meta(string $type): array
{
    $slugs = [
        "cash_in" => "topup",
        "topup" => "topup",
        "top_up" => "topup",
        "refund" => "topup",
        "payment" => "payment",
        "p2p_transfer" => "transfer",
        "allowance" => "allowance",
        "fee_waiver_claim" => "fee_waiver",
    ];
    $icons = [
        "topup" => "fa-circle-plus",
        "payment" => "fa-bag-shopping",
        "transfer" => "fa-paper-plane",
        "allowance" => "fa-hand-holding-dollar",
        "fee_waiver" => "fa-hand-holding-dollar",
        "other" => "fa-receipt",
    ];
    $slug = $slugs[$type] ?? "other";

    return [
        "slug" => $slug,
        "icon" => $icons[$slug],
        "incoming" => in_array($slug, ["topup", "allowance", "fee_waiver"], true),
        "label" => gjc_transaction_type_label($type),
    ];
}

function gjc_transaction_status_label(string $status): string
{
    return ucwords(str_replace("_", " ", $status));
}

function gjc_transaction_status_slug(string $status): string
{
    return strtolower(str_replace([" ", "_"], "-", $status));
}

function gjc_transaction_is_pending(string $status): bool
{
    return in_array(strtolower($status), ["pending", "processing"], true);
}

function gjc_transaction_is_success(string $status): bool
{
    return in_array(
        strtolower($status),
        ["completed", "approved", "released"],
        true,
    );
}

/**
 * Line items behind a paid order, keyed by the transaction's reference_no.
 *
 * Receipt rows are authoritative for paid items. Relational order rows are
 * the fallback for an order-linked payment; historical JSON is never read.
 *
 * Returns a normalised list of ['name', 'qty', 'price', 'line_total'], or []
 * for a transaction with no order behind it (top-ups, transfers, encashments).
 */
function gjc_transaction_line_items(PDO $db, string $referenceNo): array
{
    $referenceNo = trim($referenceNo);
    if ($referenceNo === "") {
        return [];
    }

    gjc_ensure_order_items_schema($db);
    $stmt = $db->prepare('SELECT i.item_name, i.quantity, i.unit_price, i.subtotal, i.line_no, t.amount AS recorded_amount
        FROM transaction_items i JOIN transactions t ON t.id = i.transaction_id
        WHERE t.reference_no = ? ORDER BY i.line_no, i.id');
    $stmt->execute([$referenceNo]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['line_no'] === null || (int) $row['line_no'] !== count($items) + 1
            || abs((float) $row['subtotal'] - round((int) $row['quantity'] * (float) $row['unit_price'], 2)) > 0.000001) {
            throw new RuntimeException('Receipt-item backfill is incomplete.');
        }
        $items[] = ['name' => $row['item_name'], 'qty' => (int) $row['quantity'],
                    'price' => (float) $row['unit_price'], 'line_total' => (float) $row['subtotal']];
    }
    if ($items) {
        $total = array_sum(array_column($items, 'line_total'));
        if (abs($total - (float) $row['recorded_amount']) > 0.000001) {
            throw new RuntimeException('Official receipt items do not match the recorded payment amount.');
        }
        return $items;
    }
    foreach (['cart' => 'cart_orders', 'qr' => 'merchant_qr_orders'] as $source => $table) {
        $stmt = $db->prepare("SELECT id FROM `{$table}` WHERE paid_ref = ? ORDER BY id LIMIT 1");
        $stmt->execute([$referenceNo]);
        $orderId = $stmt->fetchColumn();
        if ($orderId !== false) {
            foreach (gjc_order_item_lines($db, $source, (int) $orderId) as $line) {
                $items[] = ['name' => $line['name'], 'qty' => $line['qty'], 'price' => $line['price'],
                            'line_total' => round($line['qty'] * $line['price'], 2)];
            }
            break;
        }
    }

    return $items;
}

function gjc_student_wallet_owner_label(PDO $db, int $walletId): string
{
    static $cache = [];
    if (!$walletId) {
        return "Student Wallet";
    }
    if (isset($cache[$walletId])) {
        return $cache[$walletId];
    }

    $stmt = $db->prepare(
        "SELECT user_id FROM student_wallets WHERE id = ? LIMIT 1",
    );
    $stmt->execute([$walletId]);
    $userId = (int) $stmt->fetchColumn();

    return $cache[$walletId] = $userId
        ? gjc_user_label($db, $userId)
        : "Student Wallet #" . $walletId;
}

function gjc_merchant_wallet_owner_label(PDO $db, int $walletId): string
{
    static $cache = [];
    if (!$walletId) {
        return "Merchant Wallet";
    }
    if (isset($cache[$walletId])) {
        return $cache[$walletId];
    }

    $stmt = $db->prepare(
        "SELECT user_id FROM merchant_wallets WHERE id = ? LIMIT 1",
    );
    $stmt->execute([$walletId]);
    $userId = (int) $stmt->fetchColumn();

    return $cache[$walletId] = $userId
        ? gjc_user_label($db, $userId)
        : "Merchant Wallet #" . $walletId;
}

function gjc_transaction_sender_receiver(PDO $db, array $row): array
{
    $type = (string) ($row["type"] ?? "");

    switch ($type) {
        case "cash_in":
            return [
                "sender" => "Cashier Vault",
                "receiver" => gjc_student_wallet_owner_label(
                    $db,
                    (int) ($row["student_wallet_id"] ?? 0),
                ),
            ];

        case "payment":
            return [
                "sender" => gjc_student_wallet_owner_label(
                    $db,
                    (int) ($row["student_wallet_id"] ?? 0),
                ),
                "receiver" => gjc_merchant_wallet_owner_label(
                    $db,
                    (int) ($row["merchant_wallet_id"] ?? 0),
                ),
            ];

        case "merchant_settle":
            return [
                "sender" => gjc_merchant_wallet_owner_label(
                    $db,
                    (int) ($row["merchant_wallet_id"] ?? 0),
                ),
                "receiver" => "Cashier Vault",
            ];

        case "cap_increase":
            return [
                "sender" => gjc_user_label(
                    $db,
                    (int) ($row["initiated_by"] ?? 0),
                ),
                "receiver" => "Cashier Vault",
            ];

        default:
            return [
                "sender" => "System",
                "receiver" => "System",
            ];
    }
}

function gjc_build_transaction_row(PDO $db, array $base): array
{
    $source = (string) ($base["source"] ?? "ledger");
    $type = (string) ($base["type"] ?? "");
    $status = strtolower((string) ($base["status"] ?? "completed"));
    $createdAt = (string) ($base["created_at"] ?? date("Y-m-d H:i:s"));
    $party = [
        "sender" => $base["sender"] ?? "System",
        "receiver" => $base["receiver"] ?? "System",
    ];

    if ($source === "ledger") {
        $party = gjc_transaction_sender_receiver($db, $base);
    }

    return [
        "source" => $source,
        "id" => (int) ($base["id"] ?? 0),
        "ref" => (string) ($base["ref"] ?? ""),
        "type" => $type,
        "type_label" => gjc_transaction_type_label($type),
        "type_slug" => gjc_transaction_type_slug($type),
        "amount" => (float) ($base["amount"] ?? 0),
        "sender" => (string) $party["sender"],
        "receiver" => (string) $party["receiver"],
        "status" => $status,
        "status_label" => gjc_transaction_status_label($status),
        "status_slug" => gjc_transaction_status_slug($status),
        "created_at" => $createdAt,
        "time_label" => date("M d, Y h:i A", strtotime($createdAt)),
        "notes" => (string) ($base["notes"] ?? ""),
        "meta" => $base,
    ];
}

function gjc_fetch_admin_transactions(
    PDO $db,
    array $filters = [],
    int $limit = 100,
    ?int $sqlCap = null,
): array {
    gjc_ensure_operational_tables($db);

    // How many rows each source SELECT may return. null keeps the historical
    // 500/300/300, which is what every screen wants - they show at most 150.
    // 0 means no cap: the CSV export is a reconciliation document and a
    // silently short one is worse than a slow one. $limit alone could not
    // express this, because it only ever controlled the array_slice below.
    $ledgerCap = $sqlCap === null ? 500 : $sqlCap;
    $requestCap = $sqlCap === null ? 300 : $sqlCap;
    $ledgerLimit = $ledgerCap > 0 ? ' LIMIT ' . (int) $ledgerCap : '';
    $requestLimit = $requestCap > 0 ? ' LIMIT ' . (int) $requestCap : '';

    $rows = [];

    if (gjc_table_exists($db, "transactions")) {
        $stmt = $db->query(
            "SELECT id, reference_no, transaction_type, initiated_by, student_wallet_id, merchant_wallet_id,
                    amount, status, notes, created_at, vault_before, vault_after,
                    total_in_circulation
               FROM transactions
              ORDER BY created_at DESC, id DESC" . $ledgerLimit,
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = gjc_build_transaction_row($db, [
                "source" => "ledger",
                "id" => $row["id"],
                "ref" => $row["reference_no"],
                "type" => $row["transaction_type"],
                "amount" => $row["amount"],
                "status" => $row["status"],
                "notes" => $row["notes"],
                "created_at" => $row["created_at"],
                "initiated_by" => $row["initiated_by"],
                "student_wallet_id" => $row["student_wallet_id"],
                "merchant_wallet_id" => $row["merchant_wallet_id"],
                "vault_before" => $row["vault_before"],
                "vault_after" => $row["vault_after"],
                "total_in_circulation" => $row["total_in_circulation"],
            ]);
        }
    }

    if (gjc_table_exists($db, "topup_requests")) {
        $stmt = $db->query(
            "SELECT id, reference_no,
                    (SELECT sw.user_id FROM student_wallets sw WHERE sw.id = topup_requests.student_wallet_id) AS user_id,
                    student_wallet_id, amount, payment_method, status,
                    approved_by, approved_at, rejected_by, rejected_at, created_at
               FROM topup_requests
              WHERE status <> 'approved' OR reference_no IS NULL OR reference_no = ''
              ORDER BY created_at DESC, id DESC" . $requestLimit,
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = gjc_build_transaction_row($db, [
                "source" => "topup_request",
                "id" => $row["id"],
                "ref" =>
                    $row["reference_no"] ?:
                    "TOPUP-REQ-" .
                        str_pad((string) $row["id"], 5, "0", STR_PAD_LEFT),
                "type" => "topup",
                "amount" => $row["amount"],
                "status" => $row["status"],
                "notes" => "Payment method: " . $row["payment_method"],
                "created_at" => $row["created_at"],
                "sender" => gjc_user_label($db, (int) $row["user_id"]),
                "receiver" => "Cashier Review",
                "payment_method" => $row["payment_method"],
                "user_id" => $row["user_id"],
                "student_wallet_id" => $row["student_wallet_id"],
            ]);
        }
    }

    if (gjc_table_exists($db, "encashment_requests")) {
        $stmt = $db->query(
            "SELECT id, reference_no,
                    (SELECT mw.user_id FROM merchant_wallets mw WHERE mw.id = encashment_requests.merchant_wallet_id) AS user_id,
                    merchant_wallet_id, amount, method, status,
                    released_by, released_at, rejected_by, rejected_at, created_at
               FROM encashment_requests
              WHERE status <> 'released' OR reference_no IS NULL OR reference_no = ''
              ORDER BY created_at DESC, id DESC" . $requestLimit,
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = gjc_build_transaction_row($db, [
                "source" => "encashment_request",
                "id" => $row["id"],
                "ref" =>
                    $row["reference_no"] ?:
                    "ENCASH-REQ-" .
                        str_pad((string) $row["id"], 5, "0", STR_PAD_LEFT),
                "type" => "encashment",
                "amount" => $row["amount"],
                "status" => $row["status"],
                "notes" => "Method: " . $row["method"],
                "created_at" => $row["created_at"],
                "sender" => gjc_user_label($db, (int) $row["user_id"]),
                "receiver" => "Cashier Release",
                "method" => $row["method"],
                "user_id" => $row["user_id"],
                "merchant_wallet_id" => $row["merchant_wallet_id"],
            ]);
        }
    }

    $search = trim((string) ($filters["search"] ?? ""));
    $typeFilter = strtolower(trim((string) ($filters["type"] ?? "")));
    $statusFilter = strtolower(trim((string) ($filters["status"] ?? "")));

    $rows = array_values(
        array_filter($rows, function (array $row) use (
            $search,
            $typeFilter,
            $statusFilter,
        ): bool {
            if ($typeFilter !== "" && $row["type_slug"] !== $typeFilter) {
                return false;
            }

            if ($statusFilter !== "" && $row["status"] !== $statusFilter) {
                return false;
            }

            if ($search === "") {
                return true;
            }

            $needle = strtolower($search);
            $haystacks = [
                $row["ref"],
                $row["type_label"],
                $row["sender"],
                $row["receiver"],
                $row["status_label"],
                number_format((float) $row["amount"], 2, ".", ""),
                $row["notes"],
            ];

            foreach ($haystacks as $value) {
                if (strpos(strtolower((string) $value), $needle) !== false) {
                    return true;
                }
            }

            return false;
        }),
    );

    usort($rows, function (array $a, array $b): int {
        $timeCompare = strcmp($b["created_at"], $a["created_at"]);
        if ($timeCompare !== 0) {
            return $timeCompare;
        }
        return $b["id"] <=> $a["id"];
    });

    if ($limit > 0) {
        $rows = array_slice($rows, 0, $limit);
    }

    return $rows;
}

/**
 * The four Transactions stat cards, counted by SQL over the whole tables.
 *
 * gjc_admin_transaction_stats() counts an array that gjc_fetch_admin_transactions()
 * has already capped, so "Total Transactions - All wallet activities" stopped
 * being true at roughly 1,100 rows. These figures do not depend on how many
 * rows a page happens to have loaded.
 *
 * The source set mirrors the fetch exactly: the ledger, plus the top-up and
 * encashment requests that have not yet become ledger rows (same WHERE clauses,
 * so a request is never counted twice). Success and pending statuses match
 * gjc_transaction_is_success() / gjc_transaction_is_pending().
 */
function gjc_admin_transaction_counts(PDO $db): array
{
    gjc_ensure_operational_tables($db);

    $success = "('completed', 'approved', 'released')";
    $pending = "('pending', 'processing')";

    $sources = [
        ['transactions', '1 = 1'],
        ['topup_requests', "status <> 'approved' OR reference_no IS NULL OR reference_no = ''"],
        ['encashment_requests', "status <> 'released' OR reference_no IS NULL OR reference_no = ''"],
    ];

    $counts = [
        'total_transactions' => 0,
        'todays_volume' => 0.0,
        'pending_transactions' => 0,
        'completed_today' => 0,
    ];

    foreach ($sources as [$table, $where]) {
        if (!gjc_table_exists($db, $table)) {
            continue;
        }
        try {
            $row = $db->query(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(CASE WHEN LOWER(status) IN {$success}
                                           AND DATE(created_at) = CURDATE()
                                          THEN amount ELSE 0 END), 0) AS volume_today,
                        SUM(CASE WHEN LOWER(status) IN {$pending} THEN 1 ELSE 0 END) AS pending,
                        SUM(CASE WHEN LOWER(status) IN {$success}
                                  AND DATE(created_at) = CURDATE()
                                 THEN 1 ELSE 0 END) AS done_today
                   FROM {$table}
                  WHERE {$where}",
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // A missing column on one source must not blank all four cards.
            continue;
        }

        $counts['total_transactions'] += (int) ($row['total'] ?? 0);
        $counts['todays_volume'] += (float) ($row['volume_today'] ?? 0);
        $counts['pending_transactions'] += (int) ($row['pending'] ?? 0);
        $counts['completed_today'] += (int) ($row['done_today'] ?? 0);
    }

    return $counts;
}

function gjc_admin_transaction_stats(array $rows): array
{
    $today = date("Y-m-d");
    $stats = [
        "total_transactions" => count($rows),
        "todays_volume" => 0.0,
        "pending_transactions" => 0,
        "completed_today" => 0,
    ];

    foreach ($rows as $row) {
        $isToday = substr((string) $row["created_at"], 0, 10) === $today;

        if (gjc_transaction_is_pending($row["status"])) {
            $stats["pending_transactions"]++;
        }

        if ($isToday && gjc_transaction_is_success($row["status"])) {
            $stats["todays_volume"] += (float) $row["amount"];
            $stats["completed_today"]++;
        }
    }

    return $stats;
}

function gjc_find_admin_transaction(
    PDO $db,
    string $source,
    ?string $ref = null,
    ?int $id = null,
): ?array {
    $transactions = gjc_fetch_admin_transactions($db, [], 0);

    foreach ($transactions as $transaction) {
        if ($transaction["source"] !== $source) {
            continue;
        }

        if ($ref !== null && $ref !== "" && $transaction["ref"] === $ref) {
            return $transaction;
        }

        if ($id !== null && $transaction["id"] === $id) {
            return $transaction;
        }
    }

    return null;
}
