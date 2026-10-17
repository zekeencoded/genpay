<?php

// Restricted-product violation strikes against merchants.
// Loaded by connection/app.php; defines functions and constants only.

/**
 * Optional current restricted-product state. Audit history remains separate.
 * Existing legacy fields remain until a separately approved CONTRACT.
 */
function gjc_ensure_user_violations_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('Violation state schema requires users.');
    }
    $type = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    if (!$type || strtolower($type['Type']) !== 'int') {
        throw new RuntimeException('Violation owner FK requires signed INT users.userID.');
    }
    if (gjc_table_exists($db, 'user_violations')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Violation state schema creation must precede a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS user_violations (
    user_id INT NOT NULL,
    restricted_violation_count INT UNSIGNED NOT NULL DEFAULT 0,
    restricted_violation_last_at DATETIME NULL,
    restricted_suspended_until DATETIME NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_violation_user FOREIGN KEY (user_id)
        REFERENCES users(userID) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    gjc_table_columns($db, 'user_violations', true);
}

/** Current facts, distinct from immutable PRODUCT_RESTRICTION history. */
function gjc_violation_fields(): array
{
    return ['restricted_violation_count', 'restricted_violation_last_at', 'restricted_suspended_until'];
}

function gjc_violation_has_legacy(PDO $db): bool
{
    $count = count(array_intersect(gjc_violation_fields(), gjc_table_columns($db, 'users')));
    if ($count !== 0 && $count !== 3) {
        throw new RuntimeException('Violation legacy fields must be all present or all absent.');
    }
    return $count === 3;
}

/** One-statement projection shared by enforcement and the finance list. */
function gjc_violation_projection(PDO $db): array
{
    $legacy = gjc_violation_has_legacy($db);
    $normalized = gjc_table_exists($db, 'user_violations')
        && !array_diff(array_merge(['user_id'], gjc_violation_fields()), gjc_table_columns($db, 'user_violations'));
    $select = [];
    foreach (gjc_violation_fields() as $field) {
        if ($legacy) {
            $select[] = "u.{$field} AS legacy_{$field}";
        }
        if ($normalized) {
            $select[] = "v.{$field} AS normalized_{$field}";
        }
    }
    return ['legacy' => $legacy, 'normalized' => $normalized, 'select' => $select,
        'join' => $normalized ? ' LEFT JOIN user_violations v ON v.user_id = u.userID' : ''];
}

function gjc_violation_from_projection(array $row, array $projection): array
{
    $legacy = $normalized = [];
    foreach (gjc_violation_fields() as $field) {
        if ($projection['legacy']) {
            $legacy[$field] = $row["legacy_{$field}"];
        }
        if ($projection['normalized']) {
            $normalized[$field] = $field === 'restricted_violation_count'
                ? (int) ($row["normalized_{$field}"] ?? 0) : $row["normalized_{$field}"];
        }
    }
    if (!$projection['normalized']) {
        return $legacy;
    }
    if ($projection['legacy']) {
        foreach (gjc_violation_fields() as $field) {
            $equal = $field === 'restricted_violation_count'
                ? (int) $legacy[$field] === $normalized[$field] : $legacy[$field] === $normalized[$field];
            if (!$equal) {
                return $legacy;
            }
        }
    }
    return $normalized;
}

function gjc_product_violation_state(PDO $db, int $userId, bool $lock = false): ?array
{
    if ($lock && !$db->inTransaction()) {
        throw new RuntimeException('Violation locking requires a transaction.');
    }
    $projection = gjc_violation_projection($db);
    if (!$projection['select']) {
        return null;
    }
    $stmt = $db->prepare('SELECT u.userID, ' . implode(', ', $projection['select'])
        . ' FROM users u' . $projection['join'] . ' WHERE u.userID = ? LIMIT 1'
        . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? gjc_violation_from_projection($row, $projection) : null;
}

function gjc_violation_write(PDO $db, callable $write): mixed
{
    if (!$db->inTransaction()) {
        gjc_ensure_product_violation_schema($db);
    }
    gjc_violation_has_legacy($db);
    if (!gjc_table_exists($db, 'user_violations')
        || array_diff(array_merge(['user_id'], gjc_violation_fields()), gjc_table_columns($db, 'user_violations'))) {
        throw new RuntimeException('Violation migration schema is not ready.');
    }
    $owns = !$db->inTransaction();
    $savepoint = 'gjc_violation_' . bin2hex(random_bytes(8));
    if ($owns) {
        $db->beginTransaction();
    } else {
        $db->exec("SAVEPOINT {$savepoint}");
    }
    try {
        $result = $write();
        if ($owns) {
            $db->commit();
        } else {
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        }
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            if ($owns) {
                $db->rollBack();
            } else {
                $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $db->exec("RELEASE SAVEPOINT {$savepoint}");
            }
        }
        throw $error;
    }
}

function gjc_sync_user_violation(PDO $db, int $userId): void
{
    if (!$db->inTransaction()) {
        throw new RuntimeException('Violation synchronization requires a transaction.');
    }
    if (!gjc_violation_has_legacy($db)) {
        return;
    }
    $db->prepare(
        "INSERT INTO user_violations
            (user_id, restricted_violation_count, restricted_violation_last_at, restricted_suspended_until)
         SELECT userID, restricted_violation_count, restricted_violation_last_at, restricted_suspended_until
           FROM users WHERE userID = ?
         ON DUPLICATE KEY UPDATE
            restricted_violation_count = VALUES(restricted_violation_count),
            restricted_violation_last_at = VALUES(restricted_violation_last_at),
            restricted_suspended_until = VALUES(restricted_suspended_until)"
    )->execute([$userId]);
}

function gjc_clear_product_suspension(PDO $db, int $userId, ?string $cutoff = null): bool
{
    return gjc_violation_write($db, function () use ($db, $userId, $cutoff): bool {
        $state = gjc_product_violation_state($db, $userId, true);
        if (!$state || $state['restricted_suspended_until'] === null
            || ($cutoff !== null && strcmp((string) $state['restricted_suspended_until'], $cutoff) > 0)) {
            return false;
        }
        $table = gjc_violation_has_legacy($db) ? 'users' : 'user_violations';
        $key = $table === 'users' ? 'userID' : 'user_id';
        $db->prepare("UPDATE {$table} SET restricted_suspended_until = NULL WHERE {$key} = ?")->execute([$userId]);
        gjc_sync_user_violation($db, $userId);
        return true;
    });
}

function gjc_expire_product_suspensions(PDO $db): int
{
    $projection = gjc_violation_projection($db);
    if (!$projection['select']) {
        return 0;
    }
    $rows = $db->query('SELECT u.userID, NOW() AS checked_at, ' . implode(', ', $projection['select'])
        . ' FROM users u' . $projection['join'])->fetchAll(PDO::FETCH_ASSOC);
    $count = 0;
    foreach ($rows as $row) {
        $state = gjc_violation_from_projection($row, $projection);
        if ($state['restricted_suspended_until'] !== null
            && strcmp((string) $state['restricted_suspended_until'], (string) $row['checked_at']) <= 0
            && gjc_clear_product_suspension($db, (int) $row['userID'], (string) $row['checked_at'])) {
            $count++;
        }
    }
    return $count;
}

function gjc_product_violators(PDO $db): array
{
    $projection = gjc_violation_projection($db);
    if (!$projection['select']) {
        return [];
    }
    $rows = $db->query('SELECT u.userID, u.first_name, u.last_name, m.stall_name, m.stall_id, '
        . implode(', ', $projection['select']) . ' FROM users u' . $projection['join']
        . ' LEFT JOIN merchant m ON m.userID = u.userID')->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $row) {
        $state = gjc_violation_from_projection($row, $projection);
        if ((int) $state['restricted_violation_count'] <= 0 && $state['restricted_suspended_until'] === null) {
            continue;
        }
        foreach (gjc_violation_fields() as $field) {
            unset($row["legacy_{$field}"], $row["normalized_{$field}"]);
        }
        $result[] = array_merge($row, $state);
    }
    usort($result, static fn(array $a, array $b): int =>
        (int) ($b['restricted_suspended_until'] !== null) <=> (int) ($a['restricted_suspended_until'] !== null)
        ?: (int) $b['restricted_violation_count'] <=> (int) $a['restricted_violation_count']
        ?: strcmp((string) ($b['restricted_violation_last_at'] ?? ''), (string) ($a['restricted_violation_last_at'] ?? '')));
    return $result;
}

/**
 * Idempotent self-healer for the restricted-product violation counter —
 * see references/schema.md's gjc_ensure_<feature>_schema() convention.
 */
function gjc_ensure_product_violation_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'users')) {
        return;
    }
    gjc_ensure_user_violations_schema($db);
}

/**
 * Returns the datetime until which this merchant account — or, for staff
 * accounts, their owner's whole stall — is suspended over restricted-product
 * strikes, or null when not suspended. Resolves merchant_owner_id from the
 * DB row (not the session) so it works at login time and for student-facing
 * checks alike, and lazily clears suspensions that already served their days
 * (no cron needed) so admin views never show stale ones.
 */
function gjc_merchant_suspended_until(PDO $db, int $userId): ?string
{
    if ($userId <= 0 || !gjc_table_exists($db, 'users')) {
        return null;
    }
    $cols = gjc_table_columns($db, 'users');
    if (in_array('merchant_owner_id', $cols, true)) {
        $stmt = $db->prepare('SELECT merchant_owner_id FROM users WHERE userID = ? LIMIT 1');
        $stmt->execute([$userId]);
        $ownerId = (int) $stmt->fetchColumn();
        if ($ownerId > 0) {
            $userId = $ownerId;
        }
    }
    $state = gjc_product_violation_state($db, $userId);
    $until = $state['restricted_suspended_until'] ?? null;
    if (!$until) {
        return null;
    }
    if (strtotime((string) $until) > time()) {
        return (string) $until;
    }
    return gjc_violation_write($db, function () use ($db, $userId): ?string {
        $state = gjc_product_violation_state($db, $userId, true);
        $until = $state['restricted_suspended_until'] ?? null;
        if (!$until) {
            return null;
        }
        if (strtotime((string) $until) <= time()) {
            gjc_clear_product_suspension($db, $userId);
            return null;
        }
        return (string) $until;
    });
}

/**
 * Records one blocked-restricted-product attempt (add or edit — both call
 * this) against the merchant admin who tried it. At the 'violation_warn_at'
 * threshold the merchant gets an "approaching suspension" warning; hitting
 * 'violation_risk_at' auto-suspends the whole stall for
 * 'violation_suspend_days' days (owner + staff logins, student purchases —
 * enforced via gjc_merchant_suspended_until), notifies the merchant plus
 * every finance/admin user, and spends the strikes: the counter restarts at
 * 0 so reinstatement gets a clean slate. The full history stays in the
 * PRODUCT_RESTRICTION audit trail, and finance can lift a live suspension
 * early from admin/restricted_products.php. Returns the count reached by
 * this attempt — the inventory UI escalates its blocked-attempt modals off it.
 */
function gjc_record_product_violation(PDO $db, int $merchantUserId, string $reason): int
{
    gjc_ensure_product_violation_schema($db);
    $warnAt = gjc_setting_int($db, 'violation_warn_at');
    $riskAt = gjc_setting_int($db, 'violation_risk_at');
    $suspendDays = gjc_setting_int($db, 'violation_suspend_days');

    $transition = gjc_violation_write($db, function () use ($db, $merchantUserId, $riskAt, $suspendDays): array {
        $state = gjc_product_violation_state($db, $merchantUserId, true);
        if ($state === null) {
            return ['count' => 0, 'until' => null];
        }
        $count = (int) $state['restricted_violation_count'] + 1;
        $legacy = gjc_violation_has_legacy($db);
        if ($legacy) {
            $db->prepare(
                "UPDATE users SET restricted_violation_count = restricted_violation_count + 1,
                    restricted_violation_last_at = NOW() WHERE userID = ?"
            )->execute([$merchantUserId]);
        } else {
            $db->prepare(
                "INSERT INTO user_violations (user_id, restricted_violation_count, restricted_violation_last_at)
                 VALUES (?, 1, NOW()) ON DUPLICATE KEY UPDATE
                    restricted_violation_count = restricted_violation_count + 1,
                    restricted_violation_last_at = NOW()"
            )->execute([$merchantUserId]);
        }
        if ($count >= $riskAt) {
            $table = $legacy ? 'users' : 'user_violations';
            $key = $legacy ? 'userID' : 'user_id';
            $db->prepare("UPDATE {$table} SET restricted_suspended_until = DATE_ADD(NOW(), INTERVAL {$suspendDays} DAY),
                restricted_violation_count = 0 WHERE {$key} = ?")->execute([$merchantUserId]);
        }
        gjc_sync_user_violation($db, $merchantUserId);
        $after = gjc_product_violation_state($db, $merchantUserId);
        return ['count' => $count, 'until' => $after['restricted_suspended_until'] ?? null];
    });
    $count = (int) $transition['count'];


    // >= not ===: if finance lowers the threshold below a merchant's current
    // tally, the warning still fires on the next strike instead of being
    // skipped forever. Bounded above by $riskAt so it can't fire post-suspension.
    if ($count >= $warnAt && $count < $riskAt) {
        $strikesLeft = $riskAt - $count;
        gjc_notify(
            $db,
            $merchantUserId,
            'compliance',
            'Warning: restricted product attempts',
            "You've now had {$count} blocked attempts to list restricted products ({$reason}). "
                . "{$strikesLeft} more and your merchant account will be suspended for {$suspendDays} days — please check the Restricted Products list before adding new items.",
            'triangle-exclamation'
        );
    }

    if ($count >= $riskAt) {
        $untilLabel = date('M d, Y g:i A', strtotime((string) $transition['until']));

        $merchantName = gjc_user_label($db, $merchantUserId);

        if (function_exists('logAudit')) {
            logAudit(
                $db, $merchantUserId, gjc_current_role(),
                'PRODUCT_RESTRICTION', 'users', null,
                [
                    'event' => 'auto_suspended',
                    'merchant_user_id' => $merchantUserId,
                    'strikes' => $count,
                    'suspended_days' => $suspendDays,
                    'suspended_until' => $untilLabel,
                ],
                null,
                ['target_id' => $merchantUserId, 'reason' => $reason]
            );
        }

        gjc_notify(
            $db,
            $merchantUserId,
            'compliance',
            'Your account has been suspended',
            "You reached {$count} blocked attempts to list restricted products ({$reason}). Your merchant account — including staff logins and stall sales — is suspended for {$suspendDays} days, until {$untilLabel}. The GenPay finance team can lift it earlier.",
            'triangle-exclamation'
        );

        $financeIds = $db->query("SELECT userID FROM users WHERE roleID IN (3, 4)")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($financeIds as $financeId) {
            gjc_notify(
                $db,
                (int) $financeId,
                'compliance',
                'Merchant account suspended',
                "{$merchantName} hit {$count} blocked attempts to list restricted products and has been auto-suspended until {$untilLabel}. You can lift the suspension early from the Restricted Products page.",
                'triangle-exclamation',
                ADMIN_URL . '/restricted_products.php'
            );
        }
    }

    return $count;
}
