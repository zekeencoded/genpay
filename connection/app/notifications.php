<?php

// In-app notifications and rent reminders.

/** Notification feed (currently read by student pages only). */
function gjc_ensure_notifications_schema(PDO $db): void
{
    // Even CREATE TABLE IF NOT EXISTS can implicitly commit a caller's payment.
    if (gjc_table_exists($db, 'notifications')) {
        // Added later: the transaction a notification is about, so the bell can open it.
        // DDL commits implicitly, so inside a transaction just skip it until a later call.
        if (!in_array('reference_no', gjc_table_columns($db, 'notifications'), true) && !$db->inTransaction()) {
            $db->exec("ALTER TABLE notifications ADD COLUMN reference_no VARCHAR(50) NULL AFTER link");
            gjc_table_columns($db, 'notifications', true);
        }
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Notification schema must be prepared before starting a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            type       VARCHAR(40) NOT NULL DEFAULT 'general',
            icon       VARCHAR(40) NOT NULL DEFAULT 'bell',
            title      VARCHAR(150) NOT NULL,
            message    VARCHAR(500) NOT NULL,
            link       VARCHAR(255) NULL,
            reference_no VARCHAR(50) NULL,
            is_read    TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user (user_id, created_at),
            INDEX idx_notifications_user_unread (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

/**
 * $referenceNo: transactions.reference_no this notification is about (opens its details from the bell).
 * Best-effort; never throws. Callers run it after money has committed, so a failure here
 * must not turn a completed payment into an error the user would retry.
 */
function gjc_notify(PDO $db, int $userId, string $type, string $title, string $message, string $icon = 'bell', ?string $link = null, ?string $referenceNo = null): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        gjc_ensure_notifications_schema($db);
        if (in_array('reference_no', gjc_table_columns($db, 'notifications'), true)) {
            $db->prepare(
                "INSERT INTO notifications (user_id, type, icon, title, message, link, reference_no) VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$userId, $type, $icon, $title, $message, $link, $referenceNo]);
            return;
        }
        $db->prepare(
            "INSERT INTO notifications (user_id, type, icon, title, message, link) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$userId, $type, $icon, $title, $message, $link]);
    } catch (Throwable $e) {
        error_log("[notify] {$type} for user {$userId} not saved: " . $e->getMessage());
    }
}

/** Same as gjc_notify(), but resolves a student_wallets.id into its owning user first. Never throws. */
function gjc_notify_wallet(PDO $db, int $studentWalletId, string $type, string $title, string $message, string $icon = 'bell', ?string $link = null, ?string $referenceNo = null): void
{
    try {
        $stmt = $db->prepare("SELECT user_id FROM student_wallets WHERE id = ? LIMIT 1");
        $stmt->execute([$studentWalletId]);
        $userId = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("[notify] {$type} for wallet {$studentWalletId} not saved: " . $e->getMessage());
        return;
    }
    if ($userId > 0) {
        gjc_notify($db, $userId, $type, $title, $message, $icon, $link, $referenceNo);
    }
}

/** Notify a tenant that their rent payment was logged. Best-effort; never throws. */
function gjc_notify_rent_payment(
    PDO $db,
    int $merchantUserId,
    float $amount,
    string $period,
    string $referenceNo,
    float $stillOwedThisMonth = 0.0,
    ?string $nextDueDate = null
): void {
    if ($merchantUserId <= 0) {
        return;
    }

    $month = gjc_rent_period_label($period);
    $message = gjc_money_plain($amount) . ' was recorded against your ' . $month .
               ' rent. Reference ' . $referenceNo . '.';

    if ($stillOwedThisMonth > 0.005) {
        $message .= ' That month is still ' . gjc_money_plain($stillOwedThisMonth) . ' short.';
    } elseif ($nextDueDate !== null && strtotime($nextDueDate)) {
        $message .= ' Your next rent is due ' . date('M j, Y', strtotime($nextDueDate)) . '.';
    } else {
        $message .= ' Your rent is fully settled.';
    }

    try {
        gjc_notify(
            $db,
            $merchantUserId,
            'rent',
            'Rent payment recorded',
            $message,
            'receipt',
            MERCHANT_URL . '/dashboard.php'
        );
    } catch (\Throwable $e) {
        // The tenant loses a notification, not their payment.
        error_log('gjc_notify_rent_payment failed for user ' . $merchantUserId . ': ' . $e->getMessage());
    }
}

/** Notify a tenant that a rent receipt was voided. */
function gjc_notify_rent_payment_voided(
    PDO $db,
    int $merchantUserId,
    float $amount,
    string $period,
    string $referenceNo
): void {
    if ($merchantUserId <= 0) {
        return;
    }

    try {
        gjc_notify(
            $db,
            $merchantUserId,
            'rent',
            'Rent payment removed',
            'The finance office removed a ' . gjc_money_plain($amount) . ' payment (' . $referenceNo .
            ') that had been recorded for your ' . gjc_rent_period_label($period) .
            ' rent. Please check with them if this was not expected.',
            'receipt',
            MERCHANT_URL . '/dashboard.php'
        );
    } catch (\Throwable $e) {
        error_log('gjc_notify_rent_payment_voided failed for user ' . $merchantUserId . ': ' . $e->getMessage());
    }
}

/** Track the last reminded billing period so reminders fire once a month. */
function gjc_ensure_rent_reminder_schema(PDO $db): void
{
    if (!gjc_table_exists($db, 'merchant_leases')) {
        return;
    }

    if (in_array('rent_reminder_period', gjc_table_columns($db, 'merchant_leases'), true)) {
        return;
    }

    try {
        $db->exec("ALTER TABLE merchant_leases ADD COLUMN rent_reminder_period VARCHAR(7) NULL");
        gjc_table_columns($db, 'merchant_leases', true);
    } catch (\Throwable $ignored) {}
}

/** "Rent due soon" notice; see MerchantTenantDirectory::dispatchRentReminders(). */
function gjc_notify_rent_due_soon(
    PDO $db,
    int $merchantUserId,
    float $amount,
    string $period,
    string $dueDate,
    int $daysUntil,
    float $alsoOverdue = 0.0
): void {
    if ($merchantUserId <= 0) {
        return;
    }

    $when = $daysUntil === 1 ? 'tomorrow' : 'in ' . $daysUntil . ' days';
    $message = 'Your ' . gjc_money_plain($amount) . ' rent for ' . gjc_rent_period_label($period) .
               ' is due on ' . date('M j, Y', strtotime($dueDate)) . ' — ' . $when . '.';

    if ($alsoOverdue > 0.005) {
        $message .= ' You also have ' . gjc_money_plain($alsoOverdue) . ' still unpaid from earlier months.';
    }

    try {
        gjc_notify(
            $db,
            $merchantUserId,
            'rent',
            'Rent due ' . $when,
            $message,
            'calendar-day',
            MERCHANT_URL . '/dashboard.php'
        );
    } catch (\Throwable $e) {
        error_log('gjc_notify_rent_due_soon failed for user ' . $merchantUserId . ': ' . $e->getMessage());
    }
}

/** "2026-08" -> "August 2026", falling back to the raw label if it is malformed. */
function gjc_rent_period_label(string $period): string
{
    $ts = strtotime($period . '-01');

    return $ts ? date('F Y', $ts) : $period;
}

/** Fires once ever, the moment a user's very first notification would be created. */
function gjc_notify_welcome_if_new(PDO $db, int $userId): void
{
    gjc_ensure_notifications_schema($db);
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() === 0) {
        gjc_notify(
            $db,
            $userId,
            'welcome',
            'Welcome to GenPay!',
            'Your wallet is ready. Top up, pay at the canteen, or send GenCoin to a friend — every action shows up here.',
            'hand-sparkles'
        );
    }
}

function gjc_notifications_unread_count(PDO $db, int $userId): int
{
    gjc_ensure_notifications_schema($db);
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function gjc_notifications_recent(PDO $db, int $userId, int $limit = 20): array
{
    gjc_ensure_notifications_schema($db);
    $limit = max(1, min(100, $limit));
    $stmt = $db->prepare(
        "SELECT *
           FROM notifications WHERE user_id = ?
          ORDER BY created_at DESC, id DESC LIMIT {$limit}"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * notification id => signed view_transaction.php URL, only for transactions the viewer
 * can open (their own wallet, or a transfer they received) so a tap never hits "not found".
 * Rows saved before reference_no existed are matched by type and timestamp: the
 * notification is written in the same second as its transaction.
 */
function gjc_notification_view_urls(PDO $db, array $rows, int $userId, string $role): array
{
    if (!$rows || !gjc_table_exists($db, 'transactions')) {
        return [];
    }
    if ($role === 'parent') {
        $wStmt = $db->prepare("SELECT pw.id FROM parent_wallets pw JOIN parents p ON p.id = pw.parent_id WHERE p.user_id = ? LIMIT 1");
        $walletCol = 'parent_wallet_id';
    } else {
        $wStmt = $db->prepare("SELECT id FROM student_wallets WHERE user_id = ? LIMIT 1");
        $walletCol = 'student_wallet_id';
    }
    $wStmt->execute([$userId]);
    $walletId = (int) $wStmt->fetchColumn();
    if ($walletId <= 0) {
        return [];
    }

    $byRef = $db->prepare("SELECT id FROM transactions WHERE reference_no = ? AND {$walletCol} = ? LIMIT 1");
    $byTime = $db->prepare(
        "SELECT id FROM transactions
          WHERE {$walletCol} = ? AND created_at BETWEEN ? - INTERVAL 2 SECOND AND ? + INTERVAL 2 SECOND
          ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, ?)), id DESC LIMIT 1"
    );
    // Types whose transaction sits on the notified user's own wallet. fee_waiver only
    // matches when the credit was claimed into the wallet; "posted" ones have no row.
    $timeMatchTypes = ['payment', 'topup', 'cash_in', 'transfer_out', 'allowance', 'fee_waiver'];

    // Received transfers: the ledger row is the sender's; p2p_transfers names the recipient.
    $received = $role !== 'parent' && gjc_table_exists($db, 'p2p_transfers');
    if ($received) {
        $recvByRef = $db->prepare(
            "SELECT t.id FROM transactions t JOIN p2p_transfers p ON p.reference_no = t.reference_no
              WHERE t.reference_no = ? AND p.to_wallet_id = ? LIMIT 1"
        );
        $recvByTime = $db->prepare(
            "SELECT t.id FROM transactions t JOIN p2p_transfers p ON p.reference_no = t.reference_no
              WHERE p.to_wallet_id = ? AND t.transaction_type = 'p2p_transfer'
                AND t.created_at BETWEEN ? - INTERVAL 2 SECOND AND ? + INTERVAL 2 SECOND
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, t.created_at, ?)), t.id DESC LIMIT 1"
        );
    }

    $urls = [];
    foreach ($rows as $row) {
        $ref = (string) ($row['reference_no'] ?? '');
        if ($received && ($row['type'] ?? '') === 'transfer_in') {
            if ($ref !== '') {
                $recvByRef->execute([$ref, $walletId]);
            } else {
                $at = (string) $row['created_at'];
                $recvByTime->execute([$walletId, $at, $at, $at]);
            }
            $txnId = (int) ($ref !== '' ? $recvByRef : $recvByTime)->fetchColumn();
            if ($txnId > 0) {
                $urls[(int) $row['id']] = STUDENT_URL . '/view_transaction.php?source=received_transfer&token='
                    . urlencode(gjc_make_view_token($txnId, 'student_received_transfer'));
            }
            continue;
        }
        if ($ref !== '') {
            $byRef->execute([$ref, $walletId]);
            $txnId = (int) $byRef->fetchColumn();
        } elseif (in_array((string) ($row['type'] ?? ''), $timeMatchTypes, true)) {
            $at = (string) $row['created_at'];
            $byTime->execute([$walletId, $at, $at, $at]);
            $txnId = (int) $byTime->fetchColumn();
        } else {
            continue;
        }
        if ($txnId > 0) {
            $urls[(int) $row['id']] = $role === 'parent'
                ? PARENT_URL . '/view_transaction.php?token=' . urlencode(gjc_make_view_token($txnId, 'parent_txn'))
                : STUDENT_URL . '/view_transaction.php?source=ledger&token=' . urlencode(gjc_make_view_token($txnId, 'student_ledger'));
        }
    }
    return $urls;
}

/** Pass $id to mark one notification read (only if it belongs to $userId); omit to mark all read. */
function gjc_notifications_mark_read(PDO $db, int $userId, ?int $id = null): void
{
    gjc_ensure_notifications_schema($db);
    if ($id !== null) {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
    } else {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$userId]);
    }
}
