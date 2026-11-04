<?php

// Parent accounts, parent wallets and balance alerts.

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

    // Pending links awaiting student approval (kept out of parent_student_links).
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

/**
 * Mirror the bell's read state onto the dashboard's Low Balance Alerts.
 * $notificationId null = "Mark all read" (clears every alert); otherwise only a
 * low_balance notification counts, and it clears that student's alerts up to its time.
 */
function gjc_parent_alerts_mark_read_from_bell(PDO $db, int $parentUserId, ?int $notificationId): void
{
    if (!gjc_table_exists($db, 'parents') || !gjc_table_exists($db, 'parent_alerts')) {
        return;
    }
    $pStmt = $db->prepare("SELECT id FROM parents WHERE user_id = ?");
    $pStmt->execute([$parentUserId]);
    $parentId = (int) $pStmt->fetchColumn();
    if ($parentId <= 0) {
        return;
    }

    if ($notificationId === null) {
        $db->prepare("UPDATE parent_alerts SET is_read = 1 WHERE parent_id = ? AND is_read = 0")->execute([$parentId]);
        return;
    }

    $nStmt = $db->prepare("SELECT type, link, created_at FROM notifications WHERE id = ? AND user_id = ?");
    $nStmt->execute([$notificationId, $parentUserId]);
    $notification = $nStmt->fetch(PDO::FETCH_ASSOC);
    if (!$notification || $notification['type'] !== 'low_balance') {
        return;
    }
    // The link is built in gjc_check_parent_balance_alert(): .../controls.php?uid=<student user id>.
    parse_str((string) parse_url((string) $notification['link'], PHP_URL_QUERY), $query);
    $studentUserId = (int) ($query['uid'] ?? 0);
    if ($studentUserId <= 0) {
        return;
    }
    $db->prepare(
        "UPDATE parent_alerts pa
           JOIN student_wallets sw ON sw.id = pa.student_wallet_id
            SET pa.is_read = 1
          WHERE pa.parent_id = ? AND sw.user_id = ? AND pa.is_read = 0 AND pa.created_at <= ?"
    )->execute([$parentId, $studentUserId, $notification['created_at']]);
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
        "SELECT p.id AS parent_id, p.user_id AS parent_user_id, p.low_balance_threshold
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
            // Also surface it in the parent's notification bell. Best-effort: callers run
            // this after committing a payment, so it must never throw.
            try {
                gjc_notify(
                    $db,
                    (int) $parent['parent_user_id'],
                    'low_balance',
                    'Low Balance Alert',
                    sprintf(
                        "%s's balance dropped to %s (your alert threshold is %s).",
                        gjc_user_label($db, (int) $wallet['user_id']),
                        gjc_money_plain((float) $wallet['balance']),
                        gjc_money_plain((float) $parent['low_balance_threshold'])
                    ),
                    'triangle-exclamation',
                    PARENT_URL . '/controls.php?uid=' . (int) $wallet['user_id']
                );
            } catch (\Throwable $notifyError) {
                error_log('[parent low balance notify] ' . $notifyError->getMessage());
            }
        }
    }
}

/** Parent wallet schema, keyed on parents.id. */
function gjc_ensure_parent_wallet_schema(PDO $db): void
{
    // DDL: run before any transaction.
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

/**
 * Guardian ID: GRD{year the account was created}-{parents.id}, e.g. GRD2026-0001.
 * Derived, so every guardian has one with nothing to store or backfill.
 */
function gjc_guardian_id(int $parentId, ?string $createdAt): string
{
    return gjc_account_id('GRD', $parentId, $createdAt);
}

/** Typed Student/Employee IDs can't start with GRD: the send box would read them as guardians. */
function gjc_id_reserved_for_guardian(string $id): bool
{
    return (bool) preg_match('/^GRD\d/i', trim($id));
}

/**
 * One ID box for every top-up recipient. GRD2026-0001 is a guardian; anything else is a
 * Student or Employee ID. Returns kind 'student' (wallet_id) or 'parent' (parent_id).
 */
function gjc_find_topup_recipient(PDO $db, string $id): array
{
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('Enter a Student, Employee or Guardian ID.');
    }
    if (preg_match('/^GRD(\d{4})-?(\d+)$/i', $id, $m)) {
        $stmt = $db->prepare(
            "SELECT p.id, u.first_name, u.last_name, COALESCE(u.created_at, p.created_at) AS created_at
               FROM parents p JOIN users u ON u.userID = p.user_id
              WHERE p.id = ? LIMIT 1"
        );
        $stmt->execute([(int) $m[2]]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        // The year must match too, so a mistyped year can't land on another guardian.
        if (!$p || strcasecmp(gjc_guardian_id((int) $p['id'], $p['created_at']), 'GRD' . $m[1] . '-' . sprintf('%04d', (int) $m[2])) !== 0) {
            throw new RuntimeException('No guardian has that ID. Check it and try again.');
        }
        return [
            'kind' => 'parent', 'label' => 'Guardian', 'parent_id' => (int) $p['id'],
            'name' => trim($p['first_name'] . ' ' . $p['last_name']), 'id' => gjc_guardian_id((int) $p['id'], $p['created_at']),
        ];
    }
    $stmt = $db->prepare(
        "SELECT u.userID, u.roleID, u.first_name, u.last_name, si.studentID, sw.id AS wallet_id
           FROM users u
           JOIN student_info si ON si.userID = u.userID
           LEFT JOIN student_wallets sw ON sw.user_id = u.userID
          WHERE si.studentID = ? AND u.roleID IN (1, " . GJC_FACULTY_ROLE_ID . ")
          LIMIT 1"
    );
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) {
        throw new RuntimeException('No student, faculty or guardian has that ID. Check it and try again.');
    }
    if (!$s['wallet_id']) {
        throw new RuntimeException('This account does not have a wallet yet.');
    }
    return [
        'kind' => 'student', 'label' => (int) $s['roleID'] === GJC_FACULTY_ROLE_ID ? 'Faculty' : 'Student',
        'wallet_id' => (int) $s['wallet_id'], 'user_id' => (int) $s['userID'],
        'name' => trim($s['first_name'] . ' ' . $s['last_name']), 'id' => $s['studentID'],
    ];
}
