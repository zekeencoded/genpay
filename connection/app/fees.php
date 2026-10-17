<?php

// Fee-waiver credits and maintenance fees.
// Loaded by connection/app.php; defines functions and constants only.

/**
 * School-managed Fee Waiver Credit: a non-wallet, non-GenCoin misc. credit
 * that finance creates (amount + signed parent waiver upload) to be applied
 * against a student's tuition by whichever system actually owns tuition fee
 * — GenPay does not track or manage tuition fee itself, only this credit.
 * Finance's own set_amount/upload_waiver/cancel workflow (admin/api/fee_waiver_credits.php)
 * never touches CirculationEngine, student_wallets, or system_settings.
 *
 * Once a credit is 'posted', the student or a linked parent may separately
 * choose to claim it into real GenCoin (gjc_claim_fee_waiver_credit()) —
 * that is the only path that mints, and it is self-service, one-way, and
 * unrelated to finance's workflow above.
 */
function gjc_ensure_fee_waiver_credits_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS fee_waiver_credits (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            student_user_id INT UNSIGNED NOT NULL,
            amount          DECIMAL(10,2) NULL,
            status          ENUM('empty', 'pending', 'posted', 'claimed') NOT NULL DEFAULT 'empty',
            waiver_file     VARCHAR(255) NULL,
            claim_reference    VARCHAR(30) NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_fee_waiver_student (student_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Existing installs: widen the status enum and add the claim columns.
    gjc_ensure_enum_values($db, 'fee_waiver_credits', 'status', ['empty', 'pending', 'posted', 'claimed']);
    $fwcCols = gjc_table_columns($db, 'fee_waiver_credits');
    $fwcAltered = false;
    foreach ([
        'claim_reference'    => 'VARCHAR(30) NULL',
    ] as $col => $def) {
        if (!in_array($col, $fwcCols, true)) {
            try {
                $db->exec("ALTER TABLE fee_waiver_credits ADD COLUMN {$col} {$def}");
                $fwcAltered = true;
            } catch (\Throwable $ignored) {}
        }
    }
    if ($fwcAltered) {
        gjc_table_columns($db, 'fee_waiver_credits', true);
    }

    // Append-only transition history — no updates/deletes, ever.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS fee_waiver_credit_logs (
            id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fee_waiver_credit_id  INT UNSIGNED NOT NULL,
            old_status            VARCHAR(20) NOT NULL,
            new_status            VARCHAR(20) NOT NULL,
            amount                DECIMAL(10,2) NULL,
            changed_by_user_id    INT UNSIGNED NOT NULL,
            changed_by_role       VARCHAR(20) NOT NULL,
            changed_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_fwcl_credit (fee_waiver_credit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

/**
 * Backfills fee_waiver_credits for students who existed before this feature
 * shipped (the CSV import hook only creates rows for students created from
 * here on). Idempotent — INSERT IGNORE against the unique student_user_id
 * key, so already-populated rows are never touched.
 */
function gjc_backfill_fee_waiver_credits(PDO $db): void
{
    $db->exec(
        "INSERT IGNORE INTO fee_waiver_credits (student_user_id, status)
         SELECT u.userID, 'empty' FROM users u
          WHERE u.roleID = 1
            AND NOT EXISTS (SELECT 1 FROM fee_waiver_credits fwc WHERE fwc.student_user_id = u.userID)"
    );
}

/**
 * The Fee Waiver Credit for one student — reused as-is by the admin,
 * student, and parent views so nobody re-implements this lookup
 * differently. GenPay does not own tuition fee, so this returns only the
 * credit itself (amount, status, signed file), not any tuition total.
 */
function gjc_student_waiver_credit(PDO $db, int $studentUserId): array
{
    gjc_ensure_fee_waiver_credits_schema($db);

    $stmt = $db->prepare(
        // When it was claimed is the claim transaction's time (3NF).
        "SELECT f.amount, f.status, f.waiver_file, f.claim_reference,
                CASE WHEN f.status = 'claimed' THEN t.created_at END AS claimed_at
           FROM fee_waiver_credits f
           LEFT JOIN transactions t ON t.reference_no = f.claim_reference
          WHERE f.student_user_id = ? LIMIT 1"
    );
    $stmt->execute([$studentUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'amount' => null, 'status' => 'empty', 'waiver_file' => null,
        'claimed_at' => null, 'claim_reference' => null,
    ];

    return [
        'status'         => (string) $row['status'],
        'amount'         => $row['amount'] !== null ? (float) $row['amount'] : null,
        'waiver_file'    => $row['waiver_file'],
        'claimed_at'     => $row['claimed_at'],
        'claim_reference' => $row['claim_reference'],
    ];
}

/**
 * Claims a posted Fee Waiver Credit into real GenCoin, minted straight into
 * the student's wallet via CirculationEngine::feeWaiverClaim(). Self-service
 * — callable by the student themselves or by a linked parent (the caller is
 * responsible for that access check; this function only re-checks the row's
 * status). Atomic test-and-set on the status column makes "whoever claims
 * first wins" race-safe between the student and parent hitting this at the
 * same time — see references/patterns.md's race-safety pattern.
 *
 * @throws RuntimeException if the credit isn't currently 'posted', or the
 *         student has no wallet yet.
 */
function gjc_claim_fee_waiver_credit(PDO $db, int $studentUserId, int $actingUserId): array
{
    gjc_ensure_fee_waiver_credits_schema($db);
    require_once __DIR__ . '/../CirculationEngine.php';

    // DDL — must run before any beginTransaction() below.
    gjc_ensure_transaction_types($db, ['fee_waiver_claim']);
    gjc_ensure_enum_values($db, 'cap_increase_log', 'source', ['super_admin_mint', 'tuition_credit', 'fee_waiver_claim']);

    $stmt = $db->prepare("SELECT id, amount FROM fee_waiver_credits WHERE student_user_id = ? LIMIT 1");
    $stmt->execute([$studentUserId]);
    $credit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$credit) {
        throw new RuntimeException('No Fee Waiver Credit record exists for this student.');
    }
    $creditId = (int) $credit['id'];
    $amount = (float) $credit['amount'];

    $claim = $db->prepare(
        "UPDATE fee_waiver_credits
            SET status = 'claimed'
          WHERE id = ? AND status = 'posted'"
    );
    $claim->execute([$creditId]);
    if ($claim->rowCount() === 0) {
        throw new RuntimeException('This credit is not available to claim — it may already have been claimed.');
    }

    try {
        $wallet = gjc_student_wallet($db, $studentUserId);
        if ((int) $wallet['id'] === 0) {
            throw new RuntimeException('Student wallet not found.');
        }

        $engine = new CirculationEngine($db);
        $result = $engine->feeWaiverClaim((int) $wallet['id'], $amount, $actingUserId, $creditId);

        $db->prepare("UPDATE fee_waiver_credits SET claim_reference = ? WHERE id = ?")
            ->execute([$result['reference'], $creditId]);

        logAudit(
            $db, $actingUserId, gjc_current_role(), 'FEE_WAIVER_STATUS_CHANGE', 'fee_waiver_credits',
            ['student_user_id' => $studentUserId, 'status' => 'posted'],
            ['student_user_id' => $studentUserId, 'status' => 'claimed', 'amount' => $amount, 'reference' => $result['reference']],
            null,
            ['target_id' => $studentUserId]
        );

        gjc_notify(
            $db,
            $studentUserId,
            'fee_waiver',
            'Fee Waiver Credit Added to Wallet',
            gjc_money_plain($amount) . ' from your Fee Waiver Credit is now in your GenPay wallet.',
            'hand-holding-dollar',
            STUDENT_URL . '/profile.php'
        );

        return ['reference' => $result['reference'], 'amount' => $amount, 'status' => 'claimed'];
    } catch (\Throwable $e) {
        // Mint failed after the claim was taken — give the credit back to the queue
        // instead of leaving it stuck 'claimed' with no money behind it.
        $db->prepare(
            "UPDATE fee_waiver_credits SET status = 'posted' WHERE id = ? AND status = 'claimed'"
        )->execute([$creditId]);
        throw $e;
    }
}

/**
 * Sibling to gjc_claim_fee_waiver_credit(), for the Bulk Student Import's
 * "Additional Fee" column (admin/maintenance.php): a brand-new student whose
 * import row carries an amount goes straight from 'empty' to 'claimed' —
 * there is no waiver document and no separate self-service claim step,
 * because finance reviewing the import preview and clicking Apply is itself
 * the approval. MUST be called AFTER the caller's own beginTransaction()/
 * commit() around the student-creation insert, never nested inside it —
 * CirculationEngine opens its own transaction and PDO does not support
 * nested transactions.
 *
 * @throws RuntimeException if the credit isn't 'empty' (defensive — the
 *         row was just created moments ago by the same import), or the
 *         student has no wallet yet.
 */
function gjc_import_fee_waiver_credit(PDO $db, int $studentUserId, float $amount, int $creditId, int $initiatedBy): array
{
    require_once __DIR__ . '/../CirculationEngine.php';

    // DDL — must run before any beginTransaction() below.
    gjc_ensure_transaction_types($db, ['fee_waiver_claim']);
    gjc_ensure_enum_values($db, 'cap_increase_log', 'source', ['super_admin_mint', 'tuition_credit', 'fee_waiver_claim']);

    $claim = $db->prepare(
        "UPDATE fee_waiver_credits
            SET status = 'claimed', amount = ?
          WHERE id = ? AND status = 'empty'"
    );
    $claim->execute([$amount, $creditId]);
    if ($claim->rowCount() === 0) {
        throw new RuntimeException("Fee Waiver Credit #{$creditId} was not in the 'empty' state.");
    }

    try {
        $wallet = gjc_student_wallet($db, $studentUserId);
        if ((int) $wallet['id'] === 0) {
            throw new RuntimeException('Student wallet not found.');
        }

        $engine = new CirculationEngine($db);
        $result = $engine->feeWaiverClaim((int) $wallet['id'], $amount, $initiatedBy, $creditId);

        $db->prepare("UPDATE fee_waiver_credits SET claim_reference = ? WHERE id = ?")
            ->execute([$result['reference'], $creditId]);

        logAudit(
            $db, $initiatedBy, gjc_current_role(), 'FEE_WAIVER_STATUS_CHANGE', 'fee_waiver_credits',
            ['student_user_id' => $studentUserId, 'status' => 'empty'],
            ['student_user_id' => $studentUserId, 'status' => 'claimed', 'amount' => $amount, 'reference' => $result['reference'], 'source' => 'bulk_import'],
            null,
            ['target_id' => $studentUserId]
        );

        gjc_notify(
            $db,
            $studentUserId,
            'fee_waiver',
            'Fee Waiver Credit Added to Wallet',
            gjc_money_plain($amount) . ' from your Fee Waiver Credit is now in your GenPay wallet.',
            'hand-holding-dollar',
            STUDENT_URL . '/profile.php'
        );

        return ['reference' => $result['reference'], 'amount' => $amount, 'status' => 'claimed'];
    } catch (\Throwable $e) {
        $db->prepare(
            "UPDATE fee_waiver_credits SET status = 'empty', amount = NULL WHERE id = ? AND status = 'claimed'"
        )->execute([$creditId]);
        throw $e;
    }
}

/**
 * Maintenance fee: a percentage charge on a student's POS payment or
 * P2P transfer. Like the Fee Waiver Credit it is NOT wallet money — the student's
 * balance is never touched and CirculationEngine/system_settings are never
 * involved. Each row is one tuition-assessment line finance bills later, so the
 * lifecycle is accrued -> collected (or voided when the sale is returned).
 *
 * Call this BEFORE beginTransaction(): CREATE TABLE is DDL and would implicitly
 * commit the payment transaction. It also primes the settings cache for the same
 * reason, so gjc_setting() inside the transaction never runs DDL.
 */
function gjc_ensure_maintenance_fees_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db->exec(
        // 3NF: a fee row holds only its own facts. Student, type, amount, school
        // year and time are its ledger row's (reference_no); when and by whom it
        // was collected are its receipt's (collection_id). Read them through
        // gjc_maintenance_fee_source().
        "CREATE TABLE IF NOT EXISTS maintenance_fees (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            reference_no    VARCHAR(40) NOT NULL,
            fee_amount      DECIMAL(10,2) NOT NULL,
            status          ENUM('accrued', 'collected', 'voided') NOT NULL DEFAULT 'accrued',
            collection_id   INT UNSIGNED NULL,
            UNIQUE KEY uq_mfee_reference (reference_no),
            INDEX idx_mfee_status (status),
            INDEX idx_mfee_collection (collection_id),
            CONSTRAINT chk_mfee_collected CHECK ((status = 'collected') = (collection_id IS NOT NULL))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // One row per "Mark collected" action on one student — the receipt finance
    // can point to. maintenance_fees.collection_id links each settled fee to it.
    // The receipt's student is the student of its fees (3NF), not a stored copy.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS maintenance_fee_collections (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            receipt_no      VARCHAR(40) NOT NULL,
            collected_by    INT UNSIGNED NOT NULL,
            collected_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mfc_receipt (receipt_no),
            INDEX idx_mfc_collected_at (collected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    if (!in_array('collection_id', gjc_table_columns($db, 'maintenance_fees', true), true)) {
        $db->exec("ALTER TABLE maintenance_fees ADD COLUMN collection_id INT UNSIGNED NULL, ADD INDEX idx_mfee_collection (collection_id)");
    }

    // Fees collected before receipts existed: group them into one legacy
    // receipt per (student, collected_at, collector) so the history is complete.
    // (Reads the legacy copies, so it only runs before the 3NF contract; after it
    // a collected fee cannot exist without its receipt.)
    $legacy = !gjc_maintenance_fees_has_legacy_copies($db) ? [] : $db->query(
        "SELECT student_user_id, collected_at, collected_by, COUNT(*) AS n, SUM(fee_amount) AS total
           FROM maintenance_fees
          WHERE status = 'collected' AND collection_id IS NULL AND collected_at IS NOT NULL
          GROUP BY student_user_id, collected_at, collected_by"
    )->fetchAll(PDO::FETCH_ASSOC);
    $writeTotals = gjc_mfc_has_stored_totals($db);
    $writeStudent = gjc_mfc_has_stored_student($db);
    foreach ($legacy as $g) {
        $db->prepare(
            "INSERT INTO maintenance_fee_collections
                (receipt_no" . ($writeStudent ? ", student_user_id" : "") . ($writeTotals ? ", entry_count, total_amount" : "") . ", collected_by, collected_at)
             VALUES (?, " . ($writeStudent ? "?, " : "") . ($writeTotals ? "?, ?, " : "") . "?, ?)"
        )->execute(array_merge(
            [gjc_reference('MFR')], $writeStudent ? [(int) $g['student_user_id']] : [],
            $writeTotals ? [(int) $g['n'], (float) $g['total']] : [],
            [(int) ($g['collected_by'] ?? 0), $g['collected_at']]
        ));
        $db->prepare(
            "UPDATE maintenance_fees SET collection_id = ?
              WHERE student_user_id = ? AND status = 'collected' AND collection_id IS NULL
                AND collected_at = ? AND collected_by <=> ?"
        )->execute([(int) $db->lastInsertId(), (int) $g['student_user_id'], $g['collected_at'], $g['collected_by'] !== null ? (int) $g['collected_by'] : null]);
    }

    gjc_settings_all($db);
}

/**
 * maintenance_fees as a FROM source, with every derived fact a query needs.
 * A fee row only stores its own facts (reference_no, fee_amount, status,
 * collection_id); the rest belongs to the ledger row it is a line on, or to
 * its receipt (3NF):
 *   student_user_id  the payer = owner of the transaction's student wallet
 *   source_type      transactions.transaction_type (payment | p2p_transfer)
 *   txn_amount, school_year_id, created_at   the transaction's
 *   collected_at, collected_by               the receipt's
 * Use as: "FROM " . gjc_maintenance_fee_source('mf') . " WHERE mf.student_user_id = ?".
 */
function gjc_maintenance_fee_source(string $alias = 'mf'): string
{
    return "(SELECT f.id, f.reference_no, f.fee_amount, f.status, f.collection_id,
                    fsw.user_id AS student_user_id, ft.transaction_type AS source_type,
                    ft.amount AS txn_amount, ft.school_year_id, ft.created_at,
                    fc.collected_at, fc.collected_by
               FROM maintenance_fees f
               JOIN transactions ft ON ft.reference_no = f.reference_no
               JOIN student_wallets fsw ON fsw.id = ft.student_wallet_id
               LEFT JOIN maintenance_fee_collections fc ON fc.id = f.collection_id) {$alias}";
}

/** True while maintenance_fees still stores the ledger/receipt copies the 3NF contract drops. */
function gjc_maintenance_fees_has_legacy_copies(PDO $db): bool
{
    return in_array('student_user_id', gjc_table_columns($db, 'maintenance_fees'), true);
}

/** True while maintenance_fee_collections still stores the student its fees already imply (before the 3NF contract). */
function gjc_mfc_has_stored_student(PDO $db): bool
{
    return in_array('student_user_id', gjc_table_columns($db, 'maintenance_fee_collections'), true);
}

/** True while maintenance_fee_collections still stores entry_count/total_amount (before the 3NF contract). */
function gjc_mfc_has_stored_totals(PDO $db): bool
{
    return in_array('entry_count', gjc_table_columns($db, 'maintenance_fee_collections'), true);
}

/**
 * Settle every still-accrued fee for one student under a fresh receipt. Runs in
 * the caller's transaction. Returns ['receipt_no', 'entries', 'total'], or null
 * when the student has nothing accrued.
 */
function gjc_collect_maintenance_fees(PDO $db, int $studentUserId, int $collectedBy, ?string $createdBefore = null): ?array
{
    // $createdBefore limits the settle to fees created before that datetime
    // (the auto-collect sweep leaves today's open so a same-day return can still void them).
    // Student and time are the ledger row's (3NF), so the fees are reached through it.
    $cutoffSql = $createdBefore !== null ? ' AND t.created_at < ' . $db->quote($createdBefore) : '';
    $scopeSql = "maintenance_fees mf
           JOIN transactions t ON t.reference_no = mf.reference_no
           JOIN student_wallets sw ON sw.id = t.student_wallet_id";

    // Lock first so the receipt total is exactly what flips.
    $sumStmt = $db->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(mf.fee_amount), 0) AS total
           FROM {$scopeSql}
          WHERE sw.user_id = ? AND mf.status = 'accrued'{$cutoffSql}
            FOR UPDATE OF mf"
    );
    $sumStmt->execute([$studentUserId]);
    $sum = $sumStmt->fetch(PDO::FETCH_ASSOC);
    if ((int) $sum['n'] === 0) {
        return null;
    }

    $receiptNo = gjc_reference('MFR');
    // A receipt's count/total are its maintenance_fees rows (collection_id); the
    // stored copies are only written until the 3NF contract drops them.
    $writeTotals = gjc_mfc_has_stored_totals($db);
    // The receipt's student is the student of its fees; the copy is written only until the contract.
    $writeStudent = gjc_mfc_has_stored_student($db);
    $db->prepare(
        "INSERT INTO maintenance_fee_collections
            (receipt_no" . ($writeStudent ? ", student_user_id" : "") . ($writeTotals ? ", entry_count, total_amount" : "") . ", collected_by)
         VALUES (?, " . ($writeStudent ? "?, " : "") . ($writeTotals ? "?, ?, " : "") . "?)"
    )->execute(array_merge(
        [$receiptNo], $writeStudent ? [$studentUserId] : [],
        $writeTotals ? [(int) $sum['n'], (float) $sum['total']] : [],
        [$collectedBy]
    ));
    $collectionId = (int) $db->lastInsertId();

    // When and by whom is the receipt's; the copies are written only until the contract drops them.
    $legacy = gjc_maintenance_fees_has_legacy_copies($db);
    $db->prepare(
        "UPDATE {$scopeSql}
            SET mf.status = 'collected', mf.collection_id = ?"
            . ($legacy ? ", mf.collected_at = NOW(), mf.collected_by = ?" : "") . "
          WHERE sw.user_id = ? AND mf.status = 'accrued'{$cutoffSql}"
    )->execute(array_merge([$collectionId], $legacy ? [$collectedBy] : [], [$studentUserId]));

    return ['receipt_no' => $receiptNo, 'entries' => (int) $sum['n'], 'total' => (float) $sum['total']];
}

/**
 * Auto-collect: settle every student's accrued fees from before today, one
 * receipt per student (collected_by 0 = system). Today's fees stay accrued so a
 * same-day return can still void them. Called when the admin wallet loads, so
 * no one has to press "Mark collected". Returns the receipts keyed by student.
 */
function gjc_auto_collect_maintenance_fees(PDO $db): array
{
    $cutoff = date('Y-m-d 00:00:00');
    $ids = $db->query(
        "SELECT DISTINCT mf.student_user_id FROM " . gjc_maintenance_fee_source('mf') . "
          WHERE mf.status = 'accrued' AND mf.created_at < " . $db->quote($cutoff)
    )->fetchAll(PDO::FETCH_COLUMN);

    $receipts = [];
    foreach ($ids as $sid) {
        $db->beginTransaction();
        try {
            $receipt = gjc_collect_maintenance_fees($db, (int) $sid, 0, $cutoff);
            if ($receipt === null) {
                $db->rollBack();
                continue;
            }
            $db->commit();
            $receipts[(int) $sid] = $receipt;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[auto-collect maintenance fees] ' . $e->getMessage());
        }
    }
    return $receipts;
}

/**
 * The maintenance fee attached to each given transaction reference, keyed by
 * reference_no: ['fee_amount' => float, 'status' => accrued|collected|voided,
 * 'student_user_id' => int, 'collected_at' => ?string, 'receipt_no' => ?string].
 *
 * The fee is a line ON its payment/transfer, not a row of its own, so every
 * transaction view looks it up by the transaction's reference. It is a tuition
 * charge, never wallet money — nothing here touches balances or circulation.
 * Pass $studentUserId to scope to one student (the sender); references with no
 * fee simply have no key.
 */
function gjc_maintenance_fees_by_reference(PDO $db, array $referenceNos, ?int $studentUserId = null): array
{
    $referenceNos = array_values(array_unique(array_filter(array_map('strval', $referenceNos), static fn($r): bool => $r !== '')));
    if (!$referenceNos) {
        return [];
    }
    gjc_ensure_maintenance_fees_schema($db);

    // Chunked so a long history page never builds an enormous IN list.
    $out = [];
    foreach (array_chunk($referenceNos, 500) as $chunk) {
        $sql = "SELECT mf.reference_no, mf.student_user_id, mf.fee_amount, mf.status, mf.collected_at, c.receipt_no
                  FROM " . gjc_maintenance_fee_source('mf') . "
                  LEFT JOIN maintenance_fee_collections c ON c.id = mf.collection_id
                 WHERE mf.reference_no IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")";
        $params = $chunk;
        if ($studentUserId !== null) {
            $sql .= " AND mf.student_user_id = ?";
            $params[] = $studentUserId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['reference_no']] = [
                'fee_amount'      => (float) $r['fee_amount'],
                'status'          => (string) $r['status'],
                'student_user_id' => (int) $r['student_user_id'],
                'collected_at'    => $r['collected_at'],
                'receipt_no'      => $r['receipt_no'],
            ];
        }
    }
    return $out;
}

/** One transaction's fee, or null when it carried none. See gjc_maintenance_fees_by_reference(). */
function gjc_maintenance_fee_for_reference(PDO $db, string $referenceNo, ?int $studentUserId = null): ?array
{
    return gjc_maintenance_fees_by_reference($db, [$referenceNo], $studentUserId)[$referenceNo] ?? null;
}

/** Plain-language state of a fee, for the "Maintenance fee" line on a transaction. */
function gjc_maintenance_fee_state_label(string $status): string
{
    return match ($status) {
        'collected' => 'Billed to tuition · collected',
        'voided'    => 'Voided · sale returned',
        default     => 'Billed to tuition',
    };
}

/**
 * The maintenance fee for a transaction of $amount pesos: the configured
 * percentage, rounded to the nearest centavo, never below ₱0.01 — so every
 * qualifying transaction pays at least one centavo. 0 when the fee is switched
 * off (rate 0) or the amount is not positive.
 */
function gjc_maintenance_fee_for_amount(PDO $db, float $amount): float
{
    $pct = gjc_setting($db, 'maintenance_fee_rate_pct');
    if ($pct <= 0 || $amount <= 0) {
        return 0.0;
    }
    return max(0.01, round($amount * $pct / 100, 2, PHP_ROUND_HALF_UP));
}

/** "0.01%", "1%", "2.5%" — a rate label with no trailing zeros. */
function gjc_fee_rate_label(float $pct): string
{
    return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '%';
}

/**
 * Accrue the maintenance fee for one student transaction. Runs inside the
 * caller's transaction so the fee exists if and only if the payment committed.
 * Idempotent per (reference, student). Returns the fee accrued (0.0 when off).
 */
function gjc_record_maintenance_fee(PDO $db, int $studentUserId, string $referenceNo, string $sourceType, float $txnAmount): float
{
    $fee = gjc_maintenance_fee_for_amount($db, $txnAmount);
    if ($fee <= 0 || $studentUserId <= 0) {
        return 0.0;
    }

    // The fee is a line on its ledger row: student, type, amount and school year
    // are that row's (3NF) and are copied here only until the contract drops them.
    if (gjc_maintenance_fees_has_legacy_copies($db)) {
        $db->prepare(
            "INSERT IGNORE INTO maintenance_fees
                (student_user_id, reference_no, source_type, txn_amount, fee_amount, school_year_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$studentUserId, $referenceNo, $sourceType, round($txnAmount, 2), $fee, gjc_active_school_year_id($db)]);
    } else {
        $db->prepare("INSERT IGNORE INTO maintenance_fees (reference_no, fee_amount) VALUES (?, ?)")
           ->execute([$referenceNo, $fee]);
    }

    return $fee;
}

/**
 * Void the still-accrued fee attached to a reversed sale. A fee finance has
 * already collected is left alone — that is a billing correction, not automatic.
 */
function gjc_void_maintenance_fee(PDO $db, string $referenceNo): int
{
    $stmt = $db->prepare(
        "UPDATE maintenance_fees SET status = 'voided'
          WHERE reference_no = ? AND status = 'accrued'"
    );
    $stmt->execute([$referenceNo]);
    return $stmt->rowCount();
}

/** Accrued / collected totals for one student — shared by the student and parent views. */
function gjc_student_maintenance_fees(PDO $db, int $studentUserId): array
{
    gjc_ensure_maintenance_fees_schema($db);

    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'accrued'   THEN fee_amount END), 0) AS accrued,
            COALESCE(SUM(CASE WHEN status = 'collected' THEN fee_amount END), 0) AS collected,
            COALESCE(SUM(CASE WHEN status = 'accrued'   THEN 1 END), 0)          AS accrued_count
           FROM " . gjc_maintenance_fee_source('mf') . "
          WHERE mf.student_user_id = ?"
    );
    $stmt->execute([$studentUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'accrued'       => (float) ($row['accrued'] ?? 0),
        'collected'     => (float) ($row['collected'] ?? 0),
        'accrued_count' => (int) ($row['accrued_count'] ?? 0),
        'rate_pct'      => gjc_setting($db, 'maintenance_fee_rate_pct'),
    ];
}
