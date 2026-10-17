<?php

// Account suspensions, bans and lockouts.
// Loaded by connection/app.php; defines functions and constants only.

/**
 * Current manual lockout metadata; users.status remains the enforcement authority.
 * lockout_kind is preserved as an approved recorded snapshot.
 */
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
 * Idempotent self-healer for finance-issued account lockouts — the Suspend and
 * Ban actions in admin/users.php. Widens users.status with the 'Suspended' and
 * 'Banned' labels, reading the labels off the live enum rather than a hardcoded
 * list so this never clobbers one a later migration added, then ensures the
 * user_suspensions row describes the lockout: when it lifts (NULL =
 * indefinite, and always NULL for a ban), why, who issued it, and the two bits
 * needed to undo it cleanly — the status it interrupted, and whether the wallet
 * freeze was ours to reverse.
 *
 * Suspension and ban share one current-state row because an account can only be in
 * one of them at a time: both are written to users.status, so claiming either
 * one excludes the other.
 *
 * Distinct from restricted_suspended_until (gjc_ensure_product_violation_schema),
 * which is the automatic merchant-only strike suspension. Both can be live on
 * the same merchant at once; gjc_merchant_sales_blocked() ORs them.
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
 * The live finance-issued suspension on an account, or null when there isn't
 * one. A timed suspension whose end has already passed is lifted right here
 * rather than by a cron job — the same lazy expiry gjc_merchant_suspended_until()
 * uses — so no caller ever sees a stale one.
 *
 * $inheritStallOwner makes a merchant staff account report its owner's
 * suspension as its own: suspending a stall owner takes the whole stall down,
 * staff logins included, exactly like a restricted-product suspension does.
 *
 * Returns ['user_id','kind','until','reason','at','by'] where 'kind' is
 * 'suspension' or 'ban', and a null 'until' means indefinite — only a finance
 * admin can lift it. A ban always has a null 'until'.
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

    // Own suspension first. Inheriting must be a fallback, never a redirect:
    // reading the owner's row instead of the staff member's would let a
    // directly-suspended staff account keep working under an unsuspended owner.
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

/**
 * Reads status and suspension facts in one statement. During migration, legacy
 * facts remain the compatibility fallback if the normalized copy differs.
 * A missing normalized row reconstructs the empty/default current state.
 */
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

/**
 * One row's live lockout — suspension or ban — with the lazy expiry attached.
 * Split out of gjc_account_suspension() only so that function can ask the same
 * question of two accounts (the user, then their stall owner) without
 * duplicating it.
 *
 * A ban is read through this same function on purpose: every enforcement point
 * in the app already calls gjc_account_suspension(), so returning bans here is
 * what makes a ban block logins, sessions, stall sales and inbound money
 * without a second check having to be added at each of those sites. Callers
 * that need to tell them apart read the 'kind' key.
 */
function gjc_read_account_suspension(PDO $db, string $idCol, int $userId): ?array
{
    $row = gjc_account_lockout_row($db, $idCol, $userId);
    $status = $row ? (string) $row['status'] : '';
    if (!$row || !in_array($status, ['Suspended', 'Banned'], true)) {
        return null;
    }

    // users.status remains the enforcement authority. lockout_kind is retained
    // as its recorded snapshot, including NULL on older suspension records.
    $kind = $status === 'Banned' ? 'ban' : 'suspension';

    // Only a suspension can run out. A ban has no end date and must never be
    // expired out from under finance even if a stale one is somehow set.
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

/**
 * Puts an account under a finance-issued suspension. $until is the datetime it
 * lifts itself on, or null for indefinite.
 *
 * The status write is an atomic test-and-set (see references/patterns.md): a
 * double-submitted Suspend can't overwrite suspension_prev_status with
 * 'Suspended' and strand the account with nothing to restore to. The same
 * test-and-set excludes 'Banned', so a suspension can never quietly downgrade a
 * ban into something that expires on its own.
 * Returns false when the account doesn't exist, is already suspended, or is
 * banned.
 */
function gjc_suspend_account(PDO $db, int $userId, ?string $until, string $reason, int $suspendedBy): bool
{
    return gjc_claim_account_lockout($db, $userId, 'Suspended', $until, $reason, $suspendedBy);
}

/**
 * Puts an account under a permanent finance-issued ban — the Ban action in
 * admin/users.php. Same lockout record as a suspension (see
 * gjc_ensure_account_suspension_schema) with no end date, so nothing ever
 * expires it: gjc_expire_due_suspensions() only sweeps status='Suspended', and
 * gjc_read_account_suspension() refuses to expire a ban even if an end date
 * somehow lands on the row. Only gjc_lift_account_ban() clears it.
 *
 * Returns false when the account doesn't exist or is already banned. An
 * account that is merely suspended CAN be banned — that is an escalation, and
 * the ban inherits the status the suspension interrupted so lifting the ban
 * later restores the original state rather than the suspension.
 */
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

/**
 * Runs a suspension metadata write without committing a caller's transaction.
 * Schema preparation is allowed only before a transaction; inside one, this
 * requires the already-expanded schema and performs no DDL.
 */
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
 * The one write behind both Suspend and Ban. Claims users.status atomically,
 * remembers the status it interrupted so the lift can restore it, and freezes
 * the student wallet belt-and-braces — the login block already stops the
 * account acting — only when the wallet wasn't frozen already, so lifting can
 * tell our freeze apart from a parent's and never unlocks one it didn't set.
 *
 * $target is 'Suspended' or 'Banned'. The claim excludes the target status
 * itself (a repeat of the same action is a no-op) but a ban may still claim a
 * suspended account, which is how escalation works.
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

        // Escalating a suspension to a ban must carry the pre-suspension status
        // forward, not record 'Suspended' as the thing to restore to — otherwise
        // lifting the ban would drop the account back into a suspension that no
        // longer has a reason or an end date behind it.
        $prev = (string) $row['status'];
        $frozeWallet = 0;
        if (in_array($prev, ['Suspended', 'Banned'], true)) {
            $carried = trim((string) ($row['suspension_prev_status'] ?? ''));
            $prev = ($carried !== '' && !in_array($carried, ['Suspended', 'Banned'], true)) ? $carried : 'Active';
            // The outgoing lockout may already have frozen the wallet, in which
            // case the freeze attempt below is a no-op and would leave the flag at
            // 0 — the ban would then refuse to unfreeze on lift, stranding a wallet
            // that only GenPay had locked. Carry the claim forward instead.
            $frozeWallet = (int) ($row['suspension_froze_wallet'] ?? 0);
        }

        // A suspension may not claim an account that is suspended OR banned; a ban
        // may not re-claim one that is already banned.
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

/**
 * Clears a suspension and puts the account back the way it was: status returns
 * to whatever the suspension interrupted, so a staff account their owner had
 * deactivated stays Inactive instead of being silently reactivated, and the
 * student wallet is unfrozen only when the suspension is what froze it.
 *
 * Shared by the lazy expiry in gjc_account_suspension(), the sweep in
 * gjc_expire_due_suspensions(), and the lift_suspension action, so all three
 * undo a suspension identically. $liftedBy is null for an automatic expiry.
 * Returns false when the account wasn't suspended.
 */
function gjc_lift_account_suspension(PDO $db, int $userId, ?int $liftedBy, string $event = 'suspension_lifted'): bool
{
    return gjc_clear_account_lockout($db, $userId, 'Suspended', $liftedBy, $event);
}

/**
 * Lifts a permanent ban, putting the account back the way the ban found it.
 * Deliberately a separate entry point from gjc_lift_account_suspension(): a ban
 * has no automatic path out, so nothing but an explicit finance action — the
 * lift_ban endpoint in admin/api/users.php — can ever reach this.
 * Returns false when the account isn't banned.
 */
function gjc_lift_account_ban(PDO $db, int $userId, ?int $liftedBy): bool
{
    return gjc_clear_account_lockout($db, $userId, 'Banned', $liftedBy, 'ban_lifted');
}

/**
 * The one write behind lifting either kind of lockout. $fromStatus is the
 * status being cleared, and is part of the test-and-set, so a lift aimed at a
 * suspension can never clear a ban that replaced it between the read and the
 * write (or the reverse).
 */
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

        // Test-and-set again: two concurrent lifts (an admin clicking while the
        // page-load sweep runs) must not both unfreeze the wallet and both log.
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

    // Both paths notify, so the account always learns access is back — whether
    // an admin lifted it early or the clock simply ran out.
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

/**
 * Lifts every timed suspension that has run out. gjc_account_suspension()
 * already expires them one row at a time as it reads them, but admin/users.php
 * renders the whole table from a single query and never calls it per row —
 * this sweeps first so the list can't show a suspension that ended hours ago.
 */
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

/**
 * The one sentence every lockout surface shows — the login error, the login
 * page banner, the blocked-credit responses. Keeps the wording identical
 * wherever a locked-out account gets turned away, and says plainly which kind
 * of lockout it is: a suspension names its end date (or says indefinite), a ban
 * says permanent and never offers one.
 *
 * Takes the array gjc_account_suspension() returns.
 */
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

/**
 * True when a stall may not sell right now — either a restricted-product strike
 * suspension or a finance-issued suspension on the stall owner is live. Every
 * student/parent-facing sales path checks this instead of
 * gjc_merchant_suspended_until() alone, so a manual Suspend closes the stall the
 * same way an automatic one does.
 */
function gjc_merchant_sales_blocked(PDO $db, int $merchantUserId): bool
{
    return gjc_merchant_suspended_until($db, $merchantUserId) !== null
        || gjc_account_suspension($db, $merchantUserId, true) !== null;
}

/**
 * Why money may not be credited into this account right now, or null when it
 * may. Every inbound-money path (top-up approval, parent allowance, P2P
 * transfer) calls this on the RECEIVING user: the login block only stops a
 * suspended account from acting for itself, and can't stop someone else's
 * session from pushing funds at it.
 */
function gjc_funds_in_block_reason(PDO $db, int $userId): ?string
{
    $suspension = gjc_account_suspension($db, $userId);
    if ($suspension === null) {
        $student = $db->prepare('SELECT sw.is_frozen FROM student_wallets sw JOIN users u ON u.userID = sw.user_id WHERE sw.user_id = ? AND u.roleID = 1');
        $student->execute([$userId]);
        $frozen = $student->fetchColumn();
        if ($frozen !== false && ((int) $frozen === 1 || gjc_student_wallet_state($db, $userId) !== 'active')) {
            return 'This student wallet is frozen. Cash-in and incoming transfers are unavailable; the balance can still be withdrawn at the Finance office.';
        }
        return null;
    }
    return gjc_suspension_notice($suspension, gjc_user_label($db, $userId) . "'s account")
        . (($suspension['kind'] ?? 'suspension') === 'ban'
            ? ' Funds cannot be credited to it.'
            : ' Funds cannot be credited to it until the suspension is lifted.');
}
