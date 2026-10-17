<?php

// Parent accounts, parent wallets and balance alerts.
// Loaded by connection/app.php; defines functions and constants only.

// ─── Parent Account Module ────────────────────────────────────────────────────

function gjc_ensure_parent_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS parents (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id               INT NOT NULL UNIQUE,
            low_balance_threshold DECIMAL(10,2) NOT NULL DEFAULT 50.00,
            created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS parent_student_links (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id       INT UNSIGNED NOT NULL,
            student_user_id INT NOT NULL,
            linked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_link (parent_id, student_user_id),
            INDEX idx_parent  (parent_id),
            INDEX idx_student (student_user_id)
        ) ENGINE=InnoDB"
    );

    // A link waiting for the student's approval. Kept apart from
    // parent_student_links so every reader of that table sees approved links only.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS parent_link_requests (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id       INT UNSIGNED NOT NULL,
            student_user_id INT NOT NULL,
            requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_link_request (parent_id, student_user_id),
            INDEX idx_student (student_user_id)
        ) ENGINE=InnoDB"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS parent_alerts (
            id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id         INT UNSIGNED NOT NULL,
            student_wallet_id INT UNSIGNED NOT NULL,
            balance_at_alert  DECIMAL(10,2) NOT NULL,
            threshold         DECIMAL(10,2) NOT NULL,
            is_read           TINYINT(1) NOT NULL DEFAULT 0,
            created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_parent_unread (parent_id, is_read)
        ) ENGINE=InnoDB"
    );

    // Add spending-control columns to student_wallets if absent
    if (gjc_table_exists($db, 'student_wallets')) {
        $cols = gjc_table_columns($db, 'student_wallets');
        $walletAdds = [
            'daily_spend_limit' => 'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER balance',
            'is_frozen'         => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER daily_spend_limit',
        ];
        foreach ($walletAdds as $col => $def) {
            if (!in_array($col, $cols, true)) {
                try {
                    $db->exec("ALTER TABLE student_wallets ADD COLUMN {$col} {$def}");
                } catch (\Throwable $ignored) {}
            }
        }
    }
}

function gjc_check_parent_balance_alert(PDO $db, int $walletId): void
{
    if (!gjc_table_exists($db, 'parents') || !gjc_table_exists($db, 'parent_student_links') || !gjc_table_exists($db, 'parent_alerts')) {
        return;
    }

    $wStmt = $db->prepare("SELECT user_id, balance FROM student_wallets WHERE id = ?");
    $wStmt->execute([$walletId]);
    $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);
    if (!$wallet) return;

    $pStmt = $db->prepare(
        "SELECT p.id AS parent_id, p.low_balance_threshold
           FROM parents p
           JOIN parent_student_links psl ON psl.parent_id = p.id
          WHERE psl.student_user_id = ?
            AND p.low_balance_threshold > 0
            AND p.low_balance_threshold >= ?"
    );
    $pStmt->execute([$wallet['user_id'], $wallet['balance']]);
    $parents = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($parents as $parent) {
        // Throttle: one unread alert per parent-student pair per day
        $chk = $db->prepare(
            "SELECT COUNT(*) FROM parent_alerts pa
               JOIN student_wallets sw ON sw.id = pa.student_wallet_id
              WHERE pa.parent_id = ? AND sw.user_id = ?
                AND DATE(pa.created_at) = CURDATE() AND pa.is_read = 0"
        );
        $chk->execute([$parent['parent_id'], $wallet['user_id']]);
        if ((int) $chk->fetchColumn() === 0) {
            $legacyUserIds = gjc_has_legacy_wallet_user_ids($db, 'parent_alerts');
            $db->prepare(
                "INSERT INTO parent_alerts
                    (parent_id" . ($legacyUserIds ? ", student_user_id" : "") . ", student_wallet_id, balance_at_alert, threshold)
                 VALUES (?, " . ($legacyUserIds ? "?, " : "") . "?, ?, ?)"
            )->execute([
                $parent['parent_id'],
                ...($legacyUserIds ? [$wallet['user_id']] : []),
                $walletId,
                $wallet['balance'],
                $parent['low_balance_threshold'],
            ]);
        }
    }
}

/**
 * Parent wallet: parents top up with cash (Finance or a merchant), then send
 * one-time allowance transfers to a linked student. Keyed on parents.id (not
 * users.userID directly) because the rest of the parent portal already
 * resolves identity through that table — see gjc_parent_id_for_user().
 */
function gjc_ensure_parent_wallet_schema(PDO $db): void
{
    // DDL — must run before any transaction starts (MySQL implicitly commits
    // an open transaction the instant it sees DDL).
    gjc_ensure_transaction_types($db, ['allowance']);

    $db->exec(
        "CREATE TABLE IF NOT EXISTS parent_wallets (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id  INT UNSIGNED NOT NULL UNIQUE,
            balance    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );


    if (gjc_table_exists($db, 'transactions')) {
        $cols = gjc_table_columns($db, 'transactions');
        if (!in_array('parent_wallet_id', $cols, true)) {
            try {
                $db->exec("ALTER TABLE transactions ADD COLUMN parent_wallet_id INT UNSIGNED NULL");
            } catch (\Throwable $ignored) {}
        }
        $hasIndex = (bool) $db->query(
            "SHOW INDEX FROM transactions WHERE Key_name = 'idx_txn_parent_wallet'"
        )->fetchColumn();
        if (!$hasIndex) {
            try {
                $db->exec("ALTER TABLE transactions ADD INDEX idx_txn_parent_wallet (parent_wallet_id, id)");
            } catch (\Throwable $ignored) {}
        }
    }
}

/** Get-or-create the parents row for a logged-in parent user, return its id. */
function gjc_parent_id_for_user(PDO $db, int $userId): int
{
    if (!$userId) {
        return 0;
    }

    $stmt = $db->prepare("SELECT id FROM parents WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $db->prepare("INSERT IGNORE INTO parents (user_id) VALUES (?)")->execute([$userId]);
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return $row ? (int) $row['id'] : 0;
}

/** Get-or-create a parent_wallets row by parents.id — mirrors gjc_student_wallet(). */
function gjc_parent_wallet(PDO $db, int $parentId): array
{
    if ($parentId && gjc_table_exists($db, 'parent_wallets')) {
        $stmt = $db->prepare("SELECT * FROM parent_wallets WHERE parent_id = ? LIMIT 1");
        $stmt->execute([$parentId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$wallet) {
            $db->prepare(
                "INSERT IGNORE INTO parent_wallets (parent_id, balance) VALUES (?, 0)"
            )->execute([$parentId]);
            $stmt->execute([$parentId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($wallet) {
            return [
                "id" => (int) $wallet["id"],
                "balance" => (float) $wallet["balance"],
                "source" => "parent_wallets",
            ];
        }
    }

    return ["id" => 0, "balance" => 0.0, "source" => "none"];
}
