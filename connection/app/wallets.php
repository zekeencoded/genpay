<?php

// Student and merchant wallets, wallet user stats, P2P transfers and cap logs.

/** userID that owns a wallet ('student' or 'parent'), or 0 if unknown (treat as don't block). */
function gjc_wallet_owner_user_id(PDO $db, string $kind, int $walletId): int
{
    if ($walletId <= 0) {
        return 0;
    }
    try {
        if ($kind === 'student' && gjc_table_exists($db, 'student_wallets')) {
            $stmt = $db->prepare("SELECT user_id FROM student_wallets WHERE id = ? LIMIT 1");
            $stmt->execute([$walletId]);
            return (int) $stmt->fetchColumn();
        }
        if ($kind === 'parent' && gjc_table_exists($db, 'parent_wallets') && gjc_table_exists($db, 'parents')) {
            $stmt = $db->prepare(
                "SELECT p.user_id
                   FROM parent_wallets pw
                   JOIN parents p ON p.id = pw.parent_id
                  WHERE pw.id = ? LIMIT 1"
            );
            $stmt->execute([$walletId]);
            return (int) $stmt->fetchColumn();
        }
    } catch (\Throwable $ignored) {}

    return 0;
}

function gjc_student_wallet(PDO $db, int $userId): array
{
    if ($userId && gjc_table_exists($db, "student_wallets")) {
        $stmt = $db->prepare(
            "SELECT * FROM student_wallets WHERE user_id = ? LIMIT 1",
        );
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wallet) {
            // New wallets get the default spend cap (0 = none).
            $defaultCap = gjc_setting($db, 'wallet_default_spend_cap');
            try {
                $db->prepare(
                    "INSERT IGNORE INTO student_wallets (user_id, balance, daily_spend_limit)
                     VALUES (?, 0, ?)",
                )->execute([$userId, $defaultCap]);
            } catch (Throwable) {
                // Older schema without daily_spend_limit — fall back.
                $db->prepare(
                    "INSERT IGNORE INTO student_wallets (user_id, balance) VALUES (?, 0)",
                )->execute([$userId]);
            }
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($wallet) {
            return [
                "id" => (int) $wallet["id"],
                "balance" => (float) $wallet["balance"],
                "source" => "student_wallets",
            ];
        }
    }

    return ["id" => 0, "balance" => 0.0, "source" => "none"];
}

function gjc_merchant_wallet(PDO $db, int $userId): array
{
    if ($userId && gjc_table_exists($db, "merchant_wallets")) {
        $stmt = $db->prepare(
            "SELECT * FROM merchant_wallets WHERE user_id = ? LIMIT 1",
        );
        $stmt->execute([$userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wallet) {
            $db->prepare(
                "INSERT IGNORE INTO merchant_wallets (user_id, balance) VALUES (?, 0)",
            )->execute([$userId]);
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($wallet) {
            return [
                "id" => (int) $wallet["id"],
                "balance" => (float) $wallet["balance"],
                "source" => "merchant_wallets",
            ];
        }
    }

    return ["id" => 0, "balance" => 0.0, "source" => "none"];
}

function gjc_wallet_user_stats(PDO $db): array
{
    $days = 30;
    $row  = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN last_txn >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS active_count
         FROM (
             SELECT u.userID, MAX(t.created_at) AS last_txn
             FROM users u
             LEFT JOIN student_wallets sw ON sw.user_id = u.userID
             LEFT JOIN transactions t ON t.student_wallet_id = sw.id
             WHERE u.roleID IN (1, " . GJC_FACULTY_ROLE_ID . ")
             GROUP BY u.userID
         ) sub"
    );
    $row->execute([$days]);
    $data           = $row->fetch(PDO::FETCH_ASSOC);
    $total          = (int) ($data['total']        ?? 0);
    $active         = (int) ($data['active_count'] ?? 0);
    return [
        'total'    => $total,
        'active'   => $active,
        'inactive' => $total - $active,
        'days'     => $days,
    ];
}

function gjc_merchant_wallet_user_stats(PDO $db): array
{
    $days = 30;
    $row  = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN last_txn >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS active_count
         FROM (
             SELECT mw.id, MAX(t.created_at) AS last_txn
             FROM merchant_wallets mw
             LEFT JOIN transactions t ON t.merchant_wallet_id = mw.id
             GROUP BY mw.id
         ) sub"
    );
    $row->execute([$days]);
    $data   = $row->fetch(PDO::FETCH_ASSOC);
    $total  = (int) ($data['total']        ?? 0);
    $active = (int) ($data['active_count'] ?? 0);
    return [
        'total'    => $total,
        'active'   => $active,
        'inactive' => $total - $active,
        'days'     => $days,
    ];
}

function gjc_parent_wallet_user_stats(PDO $db): array
{
    $days = 30;
    $row  = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN last_txn >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END) AS active_count
         FROM (
             SELECT p.id, MAX(t.created_at) AS last_txn
             FROM parents p
             LEFT JOIN parent_wallets pw ON pw.parent_id = p.id
             LEFT JOIN transactions t ON t.parent_wallet_id = pw.id
             GROUP BY p.id
         ) sub"
    );
    $row->execute([$days]);
    $data   = $row->fetch(PDO::FETCH_ASSOC);
    $total  = (int) ($data['total']        ?? 0);
    $active = (int) ($data['active_count'] ?? 0);
    return [
        'total'    => $total,
        'active'   => $active,
        'inactive' => $total - $active,
        'days'     => $days,
    ];
}

/** All wallet users with type, balance and last activity (economy page). */
function gjc_wallet_users_list(PDO $db): array
{
    // Same definitions as the economy pool cards so counts match.
    $sql = "
        SELECT
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), '(unknown)') AS name,
            CASE WHEN u.roleID = " . GJC_FACULTY_ROLE_ID . " THEN 'Faculty' ELSE 'Student' END AS type, -- same student_wallets pool
            COALESCE(sw.balance, 0) AS balance,
            (SELECT MAX(t.created_at) FROM transactions t
              WHERE t.student_wallet_id = sw.id) AS last_txn
        FROM users u
        LEFT JOIN student_wallets sw ON sw.user_id = u.userID
        WHERE u.roleID IN (1, " . GJC_FACULTY_ROLE_ID . ")
        UNION ALL
        SELECT
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), '(unknown)') AS name,
            'Merchant' AS type,
            mw.balance AS balance,
            (SELECT MAX(t.created_at) FROM transactions t
              WHERE t.merchant_wallet_id = mw.id) AS last_txn
        FROM merchant_wallets mw
        LEFT JOIN users u ON u.userID = mw.user_id
        UNION ALL
        SELECT
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), '(unknown)') AS name,
            'Guardian' AS type,
            COALESCE(pw.balance, 0) AS balance,
            (SELECT MAX(t.created_at) FROM transactions t
              WHERE t.parent_wallet_id = pw.id) AS last_txn
        FROM parents p
        LEFT JOIN parent_wallets pw ON pw.parent_id = p.id
        LEFT JOIN users u ON u.userID = p.user_id
        ORDER BY name ASC
    ";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $cutoff = strtotime('-30 days');
    foreach ($rows as &$r) {
        $r['active'] = !empty($r['last_txn']) && strtotime($r['last_txn']) >= $cutoff;
    }
    unset($r);
    return $rows;
}



/** Preserve cap-log compatibility writes until amount_added is contracted. */
function gjc_cap_log_has_legacy_amount_added(PDO $db): bool
{
    if (!gjc_table_exists($db, 'cap_increase_log')) {
        throw new RuntimeException('The cap increase log table is missing.');
    }
    return in_array('amount_added', gjc_table_columns($db, 'cap_increase_log', true), true);
}



/** Whether p2p_transfers still has legacy user id columns. */
function gjc_p2p_has_legacy_user_ids(PDO $db): bool
{
    $columns = gjc_table_columns($db, 'p2p_transfers', true);
    $hasFrom = in_array('from_user_id', $columns, true);
    $hasTo = in_array('to_user_id', $columns, true);
    if ($hasFrom !== $hasTo) {
        throw new RuntimeException('P2P user-ID compatibility columns must be present or absent together.');
    }
    return $hasFrom;
}

/** Whether p2p_transfers still has legacy ledger copies. */
function gjc_p2p_has_legacy_ledger_copies(PDO $db): bool
{
    return in_array('amount', gjc_table_columns($db, 'p2p_transfers'), true);
}

function gjc_p2p_daily_sent(PDO $db, int $userId): float
{
    if (!gjc_table_exists($db, "p2p_transfers")) {
        return 0.0;
    }
    $stmt = $db->prepare(
        // Amount, sender, time and status live on the ledger row (3NF).
        "SELECT COALESCE(SUM(t.amount), 0) FROM p2p_transfers p
           JOIN transactions t ON t.reference_no = p.reference_no
           JOIN student_wallets sw ON sw.id = t.student_wallet_id
          WHERE sw.user_id = ? AND DATE(t.created_at) = CURDATE() AND t.status = 'completed'",
    );
    $stmt->execute([$userId]);
    return (float) $stmt->fetchColumn();
}

/** Today's spend counted against the parent's daily limit: payments + P2P sends. */
function gjc_student_spent_today(PDO $db, int $walletId): float
{
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
          WHERE student_wallet_id = ? AND transaction_type IN ('payment', 'p2p_transfer')
            AND DATE(created_at) = CURDATE() AND status = 'completed'",
    );
    $stmt->execute([$walletId]);
    return (float) $stmt->fetchColumn();
}

/** Lock a student wallet row for the transaction. Call first after beginTransaction(). */
function gjc_lock_student_wallet(PDO $db, int $walletId): void
{
    if (!$db->inTransaction()) {
        throw new LogicException('gjc_lock_student_wallet() must run inside a transaction.');
    }
    $db->prepare("SELECT id FROM student_wallets WHERE id = ? FOR UPDATE")->execute([$walletId]);
}
