<?php

// In-app notifications and rent reminders.
// Loaded by connection/app.php; defines functions and constants only.

/**
 * Student notification feed (wallet events + a one-time welcome message).
 * Role-agnostic by design — `user_id` is just a `users` row — but today only
 * student pages read from it.
 */
function gjc_ensure_notifications_schema(PDO $db): void
{
    // Even CREATE TABLE IF NOT EXISTS can implicitly commit a caller's payment.
    if (gjc_table_exists($db, 'notifications')) {
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
            is_read    TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notifications_user (user_id, created_at),
            INDEX idx_notifications_user_unread (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

function gjc_notify(PDO $db, int $userId, string $type, string $title, string $message, string $icon = 'bell', ?string $link = null): void
{
    if ($userId <= 0) {
        return;
    }
    gjc_ensure_notifications_schema($db);
    $db->prepare(
        "INSERT INTO notifications (user_id, type, icon, title, message, link) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$userId, $type, $icon, $title, $message, $link]);
}

/** Same as gjc_notify(), but resolves a student_wallets.id into its owning user first. */
function gjc_notify_wallet(PDO $db, int $studentWalletId, string $type, string $title, string $message, string $icon = 'bell', ?string $link = null): void
{
    $stmt = $db->prepare("SELECT user_id FROM student_wallets WHERE id = ? LIMIT 1");
    $stmt->execute([$studentWalletId]);
    $userId = (int) $stmt->fetchColumn();
    if ($userId > 0) {
        gjc_notify($db, $userId, $type, $title, $message, $icon, $link);
    }
}

/**
 * Tell a stall tenant that rent they handed over has been logged, with the
 * reference number they can quote back.
 *
 * Collecting the money is the operation that matters here: the caller has
 * already committed the payment by the time this runs, so a notification that
 * cannot be written must never bubble up and make a successful collection look
 * like it failed. Everything below is best-effort.
 */
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

/**
 * Counterpart to gjc_notify_rent_payment(): a receipt that was keyed by mistake
 * has been removed, so a month the tenant was told was settled is open again.
 */
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

/**
 * Marks which billing period a lease has already been reminded about, so the
 * "rent due soon" notice fires once per month instead of on every page load
 * that happens to fall inside the reminder window.
 */
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

/**
 * Heads-up that the next month's rent is coming due. Sent once per billing
 * period; see MerchantTenantDirectory::dispatchRentReminders() for when.
 */
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
        "SELECT id, type, icon, title, message, link, is_read, created_at
           FROM notifications WHERE user_id = ?
          ORDER BY created_at DESC, id DESC LIMIT {$limit}"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
