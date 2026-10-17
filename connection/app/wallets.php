<?php

// Student and merchant wallets, wallet user stats, P2P transfers and cap logs.
// Loaded by connection/app.php; defines functions and constants only.

/**
 * The users.userID behind a wallet row — 'student' reads student_wallets.user_id
 * directly, 'parent' hops parent_wallets -> parents.user_id. Credit paths that
 * only ever hold a wallet id use this to name the receiving account for
 * gjc_funds_in_block_reason(). Returns 0 when it can't be resolved, which
 * callers must treat as "don't block" rather than "blocked".
 */
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
            // New wallets inherit the configured default spend cap (0 = none).
            // Parents can still override it per student afterwards.
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
             WHERE u.roleID = 1
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

/**
 * Lists every wallet user (students + merchants) with name, type, current
 * balance, and last-activity date. Powers the Total Wallet Users drill-in
 * table on the economy page. Mirrors the 30-day "active" rule used by
 * gjc_wallet_user_stats() / gjc_merchant_wallet_user_stats().
 */
function gjc_wallet_users_list(PDO $db): array
{
    // Definitions mirror the economy pool cards: a "student wallet user" is any
    // student-role user (wallet row optional), a "merchant wallet user" is any
    // merchant_wallets row. This keeps the combined card count == the two pool
    // cards == this table's row count.
    $sql = "
        SELECT
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), '(unknown)') AS name,
            'Student' AS type,
            COALESCE(sw.balance, 0) AS balance,
            (SELECT MAX(t.created_at) FROM transactions t
              WHERE t.student_wallet_id = sw.id) AS last_txn
        FROM users u
        LEFT JOIN student_wallets sw ON sw.user_id = u.userID
        WHERE u.roleID = 1
        UNION ALL
        SELECT
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), '(unknown)') AS name,
            'Merchant' AS type,
            mw.balance AS balance,
            (SELECT MAX(t.created_at) FROM transactions t
              WHERE t.merchant_wallet_id = mw.id) AS last_txn
        FROM merchant_wallets mw
        LEFT JOIN users u ON u.userID = mw.user_id
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

/** Defines normalized cap logs for fresh installs; preserves existing tables. */
function gjc_ensure_cap_increase_log_schema(PDO $db): void
{
    if (gjc_table_exists($db, 'cap_increase_log')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Cap log schema creation must run before a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `cap_increase_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `super_admin_id` int unsigned NOT NULL COMMENT 'FK -> users.id -- must be super-admin role',
  `old_cap` decimal(15,2) NOT NULL,
  `new_cap` decimal(15,2) NOT NULL,
  `reason` text COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Mandatory justification for audit compliance',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source` enum('super_admin_mint','tuition_credit','fee_waiver_claim') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `source_ref_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    gjc_table_columns($db, 'cap_increase_log', true);
}

/** Preserve cap-log compatibility writes until amount_added is contracted. */
function gjc_cap_log_has_legacy_amount_added(PDO $db): bool
{
    if (!gjc_table_exists($db, 'cap_increase_log')) {
        throw new RuntimeException('The cap increase log table is missing.');
    }
    return in_array('amount_added', gjc_table_columns($db, 'cap_increase_log', true), true);
}

/** Defines new P2P tables with wallet IDs; existing tables are preserved. */
function gjc_ensure_p2p_transfers_schema(PDO $db): void
{
    if (gjc_table_exists($db, 'p2p_transfers')) {
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS `p2p_transfers` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference_no`      VARCHAR(40) NOT NULL COMMENT 'The ledger row: sender, amount, status, time',
    `to_wallet_id`      INT UNSIGNED NOT NULL COMMENT 'FK -> student_wallets.id (recipient)',
    `message`           VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reference_no` (`reference_no`),
    KEY `idx_p2p_to` (`to_wallet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Peer-to-peer token transfers between students (atomic, transactionally safe)'"
    );
}

/**
 * Keep P2P compatibility writes until the copied user IDs are contracted.
 * This checks metadata only; it does not change the schema.
 */
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

/**
 * True while p2p_transfers still stores the copies of its ledger row
 * (from_wallet_id, amount, status, created_at) that the 3NF contract drops.
 */
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
