<?php

// Account suspensions, bans and lockouts.

/** Lockout metadata; users.status is the enforcement authority. */
function gjc_ensure_user_suspensions_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('Suspension state schema requires users.');
    }
    $type = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    if (!$type || strtolower($type['Type']) !== 'int') {
        throw new RuntimeException('Suspension state FKs require signed INT users.userID.');
    }
    if (gjc_table_exists($db, 'user_suspensions')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Suspension state schema creation must precede a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_suspensions (
    user_id INT NOT NULL,
    suspended_until DATETIME NULL,
    suspension_reason VARCHAR(255) NULL,
    suspended_at DATETIME NULL,
    suspended_by INT NULL,
    suspension_prev_status VARCHAR(20) NULL,
    suspension_froze_wallet TINYINT(1) NOT NULL DEFAULT 0,
    lockout_kind VARCHAR(12) NULL,
    PRIMARY KEY (user_id),
    KEY idx_user_suspension_actor (suspended_by),
    CONSTRAINT fk_user_suspension_user FOREIGN KEY (user_id)
        REFERENCES users(userID) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_user_suspension_actor FOREIGN KEY (suspended_by)
        REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    gjc_table_columns($db, 'user_suspensions', true);
}

/**
 * Self-healer for Suspend/Ban: widens users.status and ensures user_suspensions.
 * Separate from the automatic merchant strike suspension (restricted_suspended_until).
 */
function gjc_ensure_account_suspension_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        return;
    }

    if ($db->inTransaction()) {
        throw new RuntimeException('Suspension schema preparation must precede a transaction.');
    }

    try {
        $column = $db->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($column['Type'] ?? '');
        $missing = array_values(array_filter(
            ['Suspended', 'Banned'],
            static fn(string $label): bool => strpos($type, "'{$label}'") === false
        ));
        if ($column && $missing) {
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $labelMatches);
            $labels = $labelMatches[1] ?? [];
            if ($labels) {
                $labels = array_merge($labels, $missing);
                $enumSql = implode(', ', array_map(
                    static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
                    $labels
                ));
                $db->exec("ALTER TABLE users MODIFY status ENUM({$enumSql}) NOT NULL DEFAULT 'Active'");
            }
        }
    } catch (\Throwable $ignored) {}

    gjc_ensure_user_suspensions_schema($db);
}

/**
 * Live suspension or ban on an account, or null. Expired suspensions are lifted here.
 * $inheritStallOwner applies the stall owner's lockout to staff.
 */
function gjc_account_suspension(PDO $db, int $userId, bool $inheritStallOwner = false): ?array
{
    if ($userId <= 0 || !gjc_table_exists($db, 'users')) {
        return null;
    }
    $cols = gjc_table_columns($db, 'users');
    // Accept either the legacy metadata or the expanded normalized table.
    if (!in_array('suspended_until', $cols, true) && !gjc_table_exists($db, 'user_suspensions')) {
        return null;
    }
    $idCol = gjc_column($db, 'users', ['id', 'userID']);
    if (!$idCol) {
        return null;
    }

    // Own suspension first; owner's is only a fallback.
    $own = gjc_read_account_suspension($db, $idCol, $userId);
    if ($own !== null) {
        return $own;
    }

    if ($inheritStallOwner && in_array('merchant_owner_id', $cols, true)) {
        $stmt = $db->prepare("SELECT merchant_owner_id FROM users WHERE {$idCol} = ? LIMIT 1");
        $stmt->execute([$userId]);
        $ownerId = (int) $stmt->fetchColumn();
        if ($ownerId > 0 && $ownerId !== $userId) {
            return gjc_read_account_suspension($db, $idCol, $ownerId);
        }
    }

    return null;
}

/** Status and suspension facts in one read, with legacy fallback. */
function gjc_account_lockout_row(PDO $db, string $idCol, int $userId, bool $forUpdate = false): ?array
{
    if (!in_array($idCol, ['id', 'userID'], true)) {
        throw new InvalidArgumentException('Invalid account identifier column.');
    }
    if ($forUpdate && !$db->inTransaction()) {
        throw new RuntimeException('Lockout row locking requires a transaction.');
    }
    $fields = ['suspended_until', 'suspension_reason', 'suspended_at', 'suspended_by',
        'suspension_prev_status', 'suspension_froze_wallet', 'lockout_kind'];
    $columns = gjc_table_columns($db, 'users');
    $hasLegacy = in_array('suspended_until', $columns, true);
    $hasNormalized = gjc_table_exists($db, 'user_suspensions')
        && !array_diff(array_merge(['user_id'], $fields), gjc_table_columns($db, 'user_suspensions'));
    if (!$hasLegacy && !$hasNormalized) {
        return null;
    }

    $select = ['u.status'];
    foreach ($fields as $field) {
        if ($hasLegacy) {
            $value = in_array($field, $columns, true) ? "u.{$field}"
                : ($field === 'suspension_froze_wallet' ? '0' : 'NULL');
            $select[] = "{$value} AS legacy_{$field}";
        }
        if ($hasNormalized) {
            $select[] = "s.{$field} AS normalized_{$field}";
        }
    }
    $join = $hasNormalized ? " LEFT JOIN user_suspensions s ON s.user_id = u.{$idCol}" : '';
    $stmt = $db->prepare("SELECT " . implode(', ', $select)
        . " FROM users u{$join} WHERE u.{$idCol} = ? LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) {
        return null;
    }

    $legacy = $normalized = ['status' => $record['status']];
    foreach ($fields as $field) {
        if ($hasLegacy) {
            $legacy[$field] = $record["legacy_{$field}"];
        }
        if ($hasNormalized) {
            $normalized[$field] = $field === 'suspension_froze_wallet'
                ? ($record["normalized_{$field}"] ?? 0) : $record["normalized_{$field}"];
        }
    }
    if (!$hasNormalized) {
        return $legacy;
    }
    if ($hasLegacy) {
        foreach ($fields as $field) {
            if (!in_array($field, $columns, true)) {
                continue;
            }
            $left = $legacy[$field];
            $right = $normalized[$field];
            $equal = $field === 'suspension_froze_wallet' ? (int) $left === (int) $right
                : (($left === null || $right === null) ? $left === $right : (string) $left === (string) $right);
            if (!$equal) {
                return $legacy;
            }
        }
    }
    return $normalized;
}

/** Hydrates suspension facts for a gated display/API row without running expiry. */
function gjc_with_user_suspension(PDO $db, array $row, int $userId): array
{
    if (!$row || $userId <= 0) {
        return $row;
    }
    $idCol = gjc_column($db, 'users', ['id', 'userID']);
    if (!$idCol) {
        return $row;
    }
    $state = gjc_account_lockout_row($db, $idCol, $userId);
    return $state === null ? $row : array_merge($row, $state);
}

/** Selects due suspension IDs using the shared state reader and database time. */
function gjc_due_account_suspension_ids(PDO $db, string $idCol): array
{
    if (!in_array($idCol, ['id', 'userID'], true)) {
        throw new InvalidArgumentException('Invalid account identifier column.');
    }
    $candidates = $db->query(
        "SELECT {$idCol} AS user_id, NOW() AS checked_at FROM users WHERE status = 'Suspended'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $due = [];
    foreach ($candidates as $candidate) {
        $userId = (int) $candidate['user_id'];
        $state = gjc_account_lockout_row($db, $idCol, $userId);
        // MySQL DATETIME and NOW() share the same canonical sortable format.
        if ($state !== null && $state['status'] === 'Suspended'
            && $state['suspended_until'] !== null
            && strcmp((string) $state['suspended_until'], (string) $candidate['checked_at']) <= 0) {
            $due[] = $userId;
        }
    }
    return $due;
}

/** One account's suspension or ban, with lazy expiry. Bans go through here so every check blocks them too. */
function gjc_read_account_suspension(PDO $db, string $idCol, int $userId): ?array
{
    $row = gjc_account_lockout_row($db, $idCol, $userId);
    $status = $row ? (string) $row['status'] : '';
    if (!$row || !in_array($status, ['Suspended', 'Banned'], true)) {
        return null;
    }

    $kind = $status === 'Banned' ? 'ban' : 'suspension';

    // Bans never expire.
    $until = $row['suspended_until'] !== null ? (string) $row['suspended_until'] : null;
    if ($kind === 'suspension' && $until !== null && strtotime($until) <= time()) {
        gjc_lift_account_suspension($db, $userId, null, 'auto_expired');
        return null;
    }
    if ($kind === 'ban') {
        $until = null;
    }

    return [
        'user_id' => $userId,
        'kind'    => $kind,
        'until'   => $until,
        'reason'  => (string) ($row['suspension_reason'] ?? ''),
        'at'      => $row['suspended_at'] !== null ? (string) $row['suspended_at'] : null,
        'by'      => (int) ($row['suspended_by'] ?? 0),
    ];
}

/** Suspend an account until $until (null = indefinite). False if missing, suspended or banned. */
function gjc_suspend_account(PDO $db, int $userId, ?string $until, string $reason, int $suspendedBy): bool
{
    return gjc_claim_account_lockout($db, $userId, 'Suspended', $until, $reason, $suspendedBy);
}

/** Ban an account permanently. A suspended account can be escalated to a ban. False if missing or banned. */
function gjc_ban_account(PDO $db, int $userId, string $reason, int $bannedBy): bool
{
    return gjc_claim_account_lockout($db, $userId, 'Banned', null, $reason, $bannedBy);
}

/** Loads the engine without allowing its PDO bootstrap to replace the caller's connection. */
function gjc_account_lockout_engine(PDO $connection): CirculationEngine
{
    require_once __DIR__ . '/../CirculationEngine.php';
    return new CirculationEngine($connection);
}

/** Returns the physical legacy layout; a partial removal is never accepted. */
function gjc_has_legacy_user_suspension(PDO $db): bool
{
    $fields = ['suspended_until', 'suspension_reason', 'suspended_at', 'suspended_by',
        'suspension_prev_status', 'suspension_froze_wallet', 'lockout_kind'];
    $count = count(array_intersect($fields, gjc_table_columns($db, 'users')));
    if ($count !== 0 && $count !== count($fields)) {
        throw new RuntimeException('Suspension legacy columns must be all present or all absent.');
    }
    return $count === count($fields);
}

/** Writes current normalized facts inside the already-locked account transaction. */
function gjc_store_user_suspension(
    PDO $db, int $userId, ?string $until, ?string $reason, ?int $actorId,
    ?string $previousStatus, int $frozeWallet, ?string $kind, bool $stampNow
): void {
    if (!$db->inTransaction()) {
        throw new RuntimeException('Suspension storage requires its locked user transaction.');
    }
    $at = $stampNow ? 'NOW()' : 'NULL';
    $db->prepare(
        "INSERT INTO user_suspensions
            (user_id, suspended_until, suspension_reason, suspended_at, suspended_by,
             suspension_prev_status, suspension_froze_wallet, lockout_kind)
         VALUES (?, ?, ?, {$at}, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            suspended_until = VALUES(suspended_until),
            suspension_reason = VALUES(suspension_reason),
            suspended_at = VALUES(suspended_at),
            suspended_by = VALUES(suspended_by),
            suspension_prev_status = VALUES(suspension_prev_status),
            suspension_froze_wallet = VALUES(suspension_froze_wallet),
            lockout_kind = VALUES(lockout_kind)"
    )->execute([$userId, $until, $reason, $actorId, $previousStatus, $frozeWallet, $kind]);
}

/** Run a lockout write without committing the caller's transaction. No DDL inside a transaction. */
function gjc_account_lockout_write(PDO $db, callable $write): bool
{
    if (!$db->inTransaction()) {
        gjc_ensure_account_suspension_schema($db);
    }
    $fields = ['suspended_until', 'suspension_reason', 'suspended_at',
        'suspended_by', 'suspension_prev_status', 'suspension_froze_wallet', 'lockout_kind'];
    gjc_has_legacy_user_suspension($db);
    if (!gjc_table_exists($db, 'user_suspensions')
        || array_diff(array_merge(['user_id'], $fields), gjc_table_columns($db, 'user_suspensions'))) {
        throw new RuntimeException('The suspension migration schema is not ready.');
    }

    $ownsTransaction = !$db->inTransaction();
    $savepoint = 'gjc_lockout_' . bin2hex(random_bytes(8));
    if ($ownsTransaction) {
        $db->beginTransaction();
    } else {
        $db->exec("SAVEPOINT {$savepoint}");
    }
    try {
        $changed = $write();
        if ($ownsTransaction) {
            $db->commit();
        } else {
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        }
        return $changed;
    } catch (\Throwable $error) {
        if ($db->inTransaction()) {
            if ($ownsTransaction) {
                $db->rollBack();
            } else {
                $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $db->exec("RELEASE SAVEPOINT {$savepoint}");
            }
        }
        throw $error;
    }
}

/** Copies the persisted legacy facts exactly, including the recorded kind snapshot. */
function gjc_sync_user_suspension(PDO $db, string $idCol, int $userId): void
{
    if (!$db->inTransaction() || !in_array($idCol, ['id', 'userID'], true)) {
        throw new RuntimeException('Suspension synchronization requires its locked user transaction.');
    }
    if (!gjc_has_legacy_user_suspension($db)) {
        return;
    }
    $db->prepare(
        "INSERT INTO user_suspensions
            (user_id, suspended_until, suspension_reason, suspended_at, suspended_by,
             suspension_prev_status, suspension_froze_wallet, lockout_kind)
         SELECT {$idCol}, suspended_until, suspension_reason, suspended_at, suspended_by,
                suspension_prev_status, suspension_froze_wallet, lockout_kind
           FROM users WHERE {$idCol} = ?
         ON DUPLICATE KEY UPDATE
            suspended_until = VALUES(suspended_until),
            suspension_reason = VALUES(suspension_reason),
            suspended_at = VALUES(suspended_at),
            suspended_by = VALUES(suspended_by),
            suspension_prev_status = VALUES(suspension_prev_status),
            suspension_froze_wallet = VALUES(suspension_froze_wallet),
            lockout_kind = VALUES(lockout_kind)"
    )->execute([$userId]);
}

/**
 * Shared write for Suspend and Ban: claims users.status atomically, saves the
 * previous status, and freezes the wallet only if it wasn't already frozen.
 */
function gjc_claim_account_lockout(PDO $db, int $userId, string $target, ?string $until, string $reason, int $actorId): bool
{
    return gjc_account_lockout_write($db, function () use ($db, $userId, $target, $until, $reason, $actorId): bool {
        $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';
        $hasLegacy = gjc_has_legacy_user_suspension($db);
        $hasKind = $hasLegacy;

        $row = gjc_account_lockout_row($db, $idCol, $userId, true);
        if (!$row) {
            return false;
        }

        // When escalating, keep the pre-suspension status to restore to.
        $prev = (string) $row['status'];
        $frozeWallet = 0;
        if (in_array($prev, ['Suspended', 'Banned'], true)) {
            $carried = trim((string) ($row['suspension_prev_status'] ?? ''));
            $prev = ($carried !== '' && !in_array($carried, ['Suspended', 'Banned'], true)) ? $carried : 'Active';
            // Carry over the wallet-freeze flag so the lift can still unfreeze it.
            $frozeWallet = (int) ($row['suspension_froze_wallet'] ?? 0);
        }

        $blocked = $target === 'Banned' ? ["'Banned'"] : ["'Suspended'", "'Banned'"];
        $kindSet = $hasKind ? ", lockout_kind = " . ($target === 'Banned' ? "'ban'" : "'suspension'") : '';

        if ($hasLegacy) {
            $claim = $db->prepare(
                "UPDATE users
                    SET status = ?, suspended_until = ?, suspension_reason = ?,
                        suspended_at = NOW(), suspended_by = ?, suspension_prev_status = ?,
                        suspension_froze_wallet = ?{$kindSet}
                  WHERE {$idCol} = ? AND status NOT IN (" . implode(', ', $blocked) . ")"
            );
            $claim->execute([$target, $until, mb_substr($reason, 0, 255), $actorId, $prev, $frozeWallet, $userId]);
        } else {
            $claim = $db->prepare(
                "UPDATE users SET status = ? WHERE {$idCol} = ? AND status NOT IN (" . implode(', ', $blocked) . ")"
            );
            $claim->execute([$target, $userId]);
        }
        if ($claim->rowCount() === 0) {
            return false;
        }

        if (!$hasLegacy) {
            gjc_store_user_suspension($db, $userId, $until, mb_substr($reason, 0, 255),
                $actorId, $prev, $frozeWallet, $target === 'Banned' ? 'ban' : 'suspension', true);
        }

        if (gjc_table_exists($db, 'student_wallets')) {
            try {
                if (gjc_account_lockout_engine($db)->freezeStudentWalletForLockout($userId)) {
                    $freezeTable = $hasLegacy ? 'users' : 'user_suspensions';
                    $freezeId = $hasLegacy ? $idCol : 'user_id';
                    $db->prepare("UPDATE {$freezeTable} SET suspension_froze_wallet = 1 WHERE {$freezeId} = ?")->execute([$userId]);
                }
            } catch (\Throwable $ignored) {}
        }

        gjc_sync_user_suspension($db, $idCol, $userId);
        return true;
    });
}

/** Lift a suspension and restore the previous status; unfreeze the wallet only if we froze it. */
function gjc_lift_account_suspension(PDO $db, int $userId, ?int $liftedBy, string $event = 'suspension_lifted'): bool
{
    return gjc_clear_account_lockout($db, $userId, 'Suspended', $liftedBy, $event);
}

/** Lift a ban. Only reachable from the explicit lift_ban action. */
function gjc_lift_account_ban(PDO $db, int $userId, ?int $liftedBy): bool
{
    return gjc_clear_account_lockout($db, $userId, 'Banned', $liftedBy, 'ban_lifted');
}

/** Shared write for lifting either lockout; $fromStatus guards against a race. */
function gjc_clear_account_lockout(PDO $db, int $userId, string $fromStatus, ?int $liftedBy, string $event): bool
{
    $row = null;
    $restored = '';
    $changed = gjc_account_lockout_write($db, function () use ($db, $userId, $fromStatus, &$row, &$restored): bool {
        $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';
        $hasLegacy = gjc_has_legacy_user_suspension($db);

        $row = gjc_account_lockout_row($db, $idCol, $userId, true);
        if (!$row || (string) $row['status'] !== $fromStatus) {
            return false;
        }

        $restored = (string) ($row['suspension_prev_status'] ?? '');
        if ($restored === '' || in_array($restored, ['Suspended', 'Banned'], true)) {
            $restored = 'Active';
        }

        // Test-and-set so concurrent lifts don't double-unfreeze or double-log.
        $kindSet = in_array('lockout_kind', gjc_table_columns($db, 'users'), true)
            ? ', lockout_kind = NULL'
            : '';
        if ($hasLegacy) {
            $claim = $db->prepare(
                "UPDATE users
                    SET status = ?, suspended_until = NULL, suspension_reason = NULL,
                        suspended_at = NULL, suspended_by = NULL,
                        suspension_prev_status = NULL, suspension_froze_wallet = 0{$kindSet}
                  WHERE {$idCol} = ? AND status = ?"
            );
            $claim->execute([$restored, $userId, $fromStatus]);
        } else {
            $claim = $db->prepare("UPDATE users SET status = ? WHERE {$idCol} = ? AND status = ?");
            $claim->execute([$restored, $userId, $fromStatus]);
        }
        if ($claim->rowCount() === 0) {
            return false;
        }

        if (!$hasLegacy) {
            gjc_store_user_suspension($db, $userId, null, null, null, null, 0, null, false);
        }

        if ((int) ($row['suspension_froze_wallet'] ?? 0) === 1 && gjc_table_exists($db, 'student_wallets')) {
            try {
                gjc_account_lockout_engine($db)->unfreezeStudentWalletForLockout($userId);
            } catch (\Throwable $ignored) {}
        }

        gjc_sync_user_suspension($db, $idCol, $userId);
        return true;
    });
    if (!$changed) {
        return false;
    }
    $isBan = $fromStatus === 'Banned';

    if ($isBan) {
        $noticeTitle = 'Account ban lifted';
        $noticeBody  = 'A GenPay finance admin lifted the ban on your account. You can sign in and use your account again.';
    } elseif ($event === 'auto_expired') {
        $noticeTitle = 'Account suspension lifted';
        $noticeBody  = 'Your suspension period has ended. You can sign in and use your GenPay account again.';
    } else {
        $noticeTitle = 'Account suspension lifted';
        $noticeBody  = 'A GenPay finance admin lifted your account suspension. You can sign in and use your account again.';
    }
    gjc_notify($db, $userId, 'compliance', $noticeTitle, $noticeBody, 'circle-check');

    if (function_exists('logAudit')) {
        logAudit(
            $db,
            $liftedBy > 0 ? $liftedBy : $userId,
            $liftedBy > 0 ? gjc_current_role() : 'finance',
            'USER_ACCOUNT',
            'users',
            [
                'userID' => $userId,
                'status' => $fromStatus,
                'suspended_until' => $row['suspended_until'],
                'suspension_reason' => $row['suspension_reason'],
            ],
            [
                'event' => $event,
                'userID' => $userId,
                'status' => $restored,
                'lifted_by' => $liftedBy,
                'wallet_unfrozen' => (int) ($row['suspension_froze_wallet'] ?? 0) === 1,
            ],
            null,
            ['target_id' => $userId, 'reason' => (string) ($row['suspension_reason'] ?? '')]
        );
    }

    return true;
}

/** Lift all expired timed suspensions (for list views). */
function gjc_expire_due_suspensions(PDO $db): int
{
    gjc_ensure_account_suspension_schema($db);
    $idCol = gjc_column($db, 'users', ['id', 'userID']) ?: 'userID';

    try {
        $due = gjc_due_account_suspension_ids($db, $idCol);
    } catch (\Throwable $ignored) {
        return 0;
    }

    $lifted = 0;
    foreach ($due as $id) {
        if (gjc_lift_account_suspension($db, (int) $id, null, 'auto_expired')) {
            $lifted++;
        }
    }
    return $lifted;
}

/** Standard lockout message shown wherever a locked account is turned away. */
function gjc_suspension_notice(array $suspension, string $subject = 'This account'): string
{
    $until = $suspension['until'] ?? null;
    $reason = trim((string) ($suspension['reason'] ?? ''));
    $isBan = ($suspension['kind'] ?? 'suspension') === 'ban';

    if ($isBan) {
        $notice = $subject . ' has been permanently banned from GenPay.';
    } else {
        $notice = $until
            ? $subject . ' is suspended until ' . date('M d, Y g:i A', strtotime((string) $until)) . '.'
            : $subject . ' is suspended indefinitely.';
    }
    if ($reason !== '') {
        $notice .= ' Reason: ' . $reason;
        if (!str_ends_with($notice, '.')) {
            $notice .= '.';
        }
    }
    if ($isBan) {
        $notice .= ' Contact the GenPay finance office if you believe this is a mistake.';
    } elseif ($until === null) {
        $notice .= ' Contact the GenPay finance office to have it reviewed.';
    }

    return $notice;
}

/** True if the stall can't sell: strike suspension or owner suspension. */
function gjc_merchant_sales_blocked(PDO $db, int $merchantUserId): bool
{
    return gjc_merchant_suspended_until($db, $merchantUserId) !== null
        || gjc_account_suspension($db, $merchantUserId, true) !== null;
}

/** Why money can't be credited to this user right now, or null. */
function gjc_funds_in_block_reason(PDO $db, int $userId): ?string
{
    $suspension = gjc_account_suspension($db, $userId);
    if ($suspension === null) {
        $student = $db->prepare('SELECT sw.is_frozen FROM student_wallets sw JOIN users u ON u.userID = sw.user_id WHERE sw.user_id = ? AND u.roleID = 1');
        $student->execute([$userId]);
        $frozen = $student->fetchColumn();
        if ($frozen !== false && ((int) $frozen === 1 || gjc_student_wallet_state($db, $userId) !== 'active')) {
            return 'This student wallet is frozen. Cash-in and incoming transfers are unavailable; the balance stays safe in the wallet.';
        }
        return null;
    }
    return gjc_suspension_notice($suspension, gjc_user_label($db, $userId) . "'s account")
        . (($suspension['kind'] ?? 'suspension') === 'ban'
            ? ' Funds cannot be credited to it.'
            : ' Funds cannot be credited to it until the suspension is lifted.');
}
