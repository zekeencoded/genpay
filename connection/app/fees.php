<?php

// Fee-waiver credits and maintenance fees.

/**
 * Fee Waiver Credit: non-wallet credit finance applies against tuition.
 * Once posted, the student or parent may claim it into GenCoin (the only path that mints).
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

/** Create missing fee_waiver_credits rows for existing students (idempotent). */
function gjc_backfill_fee_waiver_credits(PDO $db): void
{
    $db->exec(
        "INSERT IGNORE INTO fee_waiver_credits (student_user_id, status)
         SELECT u.userID, 'empty' FROM users u
          WHERE u.roleID = 1
            AND NOT EXISTS (SELECT 1 FROM fee_waiver_credits fwc WHERE fwc.student_user_id = u.userID)"
    );
}

/** A student's Fee Waiver Credit (amount, status, signed file). */
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
 * Claim a posted credit into GenCoin. Caller checks access; first claim wins (test-and-set).
 *
 * @throws RuntimeException if the credit isn't 'posted' or the student has no wallet.
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
        // Mint failed: return the credit to 'posted'.
        $db->prepare(
            "UPDATE fee_waiver_credits SET status = 'posted' WHERE id = ? AND status = 'claimed'"
        )->execute([$creditId]);
        throw $e;
    }
}

/**
 * Claim an imported "Additional Fee" straight from 'empty' to 'claimed'.
 * Call after the caller's own transaction commits (no nested transactions).
 *
 * @throws RuntimeException if the credit isn't 'empty' or the student has no wallet.
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
 * Maintenance fee: a % charge on POS payments and P2P transfers, billed to tuition (not wallet money).
 * Call before beginTransaction(); it runs DDL.
 */
function gjc_ensure_maintenance_fees_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db->exec(
        // Other facts come from the ledger row and receipt; see gjc_maintenance_fee_source().
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

    // One receipt per "Mark collected" action.
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

    // Group pre-receipt collected fees into legacy receipts (pre-3NF contract only).
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
 * maintenance_fees as a FROM source with derived columns (student_user_id, source_type,
 * txn_amount, school_year_id, created_at, collected_at, collected_by).
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

/** Settle a student's accrued fees under a new receipt. Returns ['receipt_no', 'entries', 'total'] or null. */
function gjc_collect_maintenance_fees(PDO $db, int $studentUserId, int $collectedBy, ?string $createdBefore = null): ?array
{
    // $createdBefore leaves today's fees open so same-day returns can void them.
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
    // Stored totals are only written until the 3NF contract drops them.
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

/** Settle all accrued fees from before today, one receipt per student (collected_by 0 = system). */
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

/** Maintenance fee per transaction reference, keyed by reference_no. */
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
        'collected' => 'Collected',
        'voided'    => 'Voided · sale returned',
        default     => '',
    };
}

/** Fee for $amount: configured %, rounded, minimum ₱0.01. 0 when off. */
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

/** Accrue the fee inside the caller's transaction. Idempotent per (reference, student). */
function gjc_record_maintenance_fee(PDO $db, int $studentUserId, string $referenceNo, string $sourceType, float $txnAmount): float
{
    $fee = gjc_maintenance_fee_for_amount($db, $txnAmount);
    if ($fee <= 0 || $studentUserId <= 0) {
        return 0.0;
    }
    // Faculty pay no tuition, so there is no bill to carry the fee.
    $role = $db->prepare("SELECT roleID FROM users WHERE userID = ?");
    $role->execute([$studentUserId]);
    if ((int) $role->fetchColumn() === GJC_FACULTY_ROLE_ID) {
        return 0.0;
    }

    // Legacy copies are written until the 3NF contract drops them.
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

/** Void the accrued fee of a reversed sale; collected fees are left alone. */
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
