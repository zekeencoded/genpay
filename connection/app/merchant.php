<?php

// Merchant sales, remittances, compliance documents and display names.

/** Merchant display name (stall name, else account name). */
function gjc_merchant_display_name(PDO $db, int $merchantUserId): ?string
{
    $stmt = $db->prepare(
        "SELECT m.stall_name, u.first_name, u.last_name
           FROM users u
           LEFT JOIN merchant m ON m.userID = u.userID
          WHERE u.userID = ?
          LIMIT 1"
    );
    $stmt->execute([$merchantUserId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return $row['stall_name'] ?: trim($row['first_name'] . ' ' . $row['last_name']);
}

/** Staff sales not yet remitted since the last remittance today (or midnight). */
function gjc_merchant_unremitted_sales(PDO $db, int $walletId): array
{
    $since = date("Y-m-d 00:00:00");
    $amount = 0.0;
    $count = 0;

    if ($walletId > 0 && gjc_table_exists($db, "sales_remittances")) {
        $stmt = $db->prepare(
            "SELECT period_end FROM sales_remittances
              WHERE merchant_wallet_id = ? AND DATE(period_end) = CURDATE()
              ORDER BY period_end DESC LIMIT 1",
        );
        $stmt->execute([$walletId]);
        $lastRemit = $stmt->fetchColumn();
        if ($lastRemit) {
            $since = $lastRemit;
        }
    }

    if ($walletId > 0 && gjc_table_exists($db, "transactions")) {
        $earningTypes = [
            CirculationEngine::TXN_PAYMENT,
        ];
        $placeholders = implode(", ", array_fill(0, count($earningTypes), "?"));
        $staffSale = gjc_merchant_staff_sale_sql($db);
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0), COUNT(*) FROM transactions t
              WHERE merchant_wallet_id = ?
                AND transaction_type IN ({$placeholders})
                AND status = 'completed' AND {$staffSale}
                AND created_at > ?",
        );
        $stmt->execute(array_merge([$walletId], $earningTypes, [$since]));
        [$amount, $count] = $stmt->fetch(PDO::FETCH_NUM);
    }

    return ["amount" => (float) $amount, "count" => (int) $count, "since" => $since];
}

/** Today's completed sales; staff sales count once remitted. */
function gjc_merchant_remitted_sales_today(PDO $db, int $walletId): array
{
    $amount = 0.0;
    $count = 0;
    $periodStart = null;
    $periodEnd = null;

    if ($walletId > 0 && gjc_table_exists($db, "transactions")) {
        $confirmed = gjc_merchant_confirmed_sale_sql($db);
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(t.amount), 0), COUNT(*), MIN(t.created_at), MAX(t.created_at)
               FROM transactions t
              WHERE t.merchant_wallet_id = ? AND DATE(t.created_at) = CURDATE()
                AND t.transaction_type = ? AND t.status = 'completed' AND {$confirmed}",
        );
        $stmt->execute([$walletId, CirculationEngine::TXN_PAYMENT]);
        [$amount, $count, $periodStart, $periodEnd] = $stmt->fetch(PDO::FETCH_NUM);
    }

    return [
        "amount" => (float) $amount,
        "count" => (int) $count,
        "period_start" => $periodStart,
        "period_end" => $periodEnd,
    ];
}

/** SQL condition for sales that must be remitted (pre-orders and staff sales). */
function gjc_merchant_staff_sale_sql(PDO $db): string
{
    $operator = 't.initiated_by';
    if (gjc_table_exists($db, 'merchant_qr_orders')
        && in_array('created_by', gjc_table_columns($db, 'merchant_qr_orders'), true)) {
        $operator = "COALESCE((SELECT q.created_by FROM merchant_qr_orders q
            WHERE q.merchant_wallet_id = t.merchant_wallet_id AND q.paid_ref = t.reference_no
            LIMIT 1), t.initiated_by)";
    }
    return "(t.reference_no LIKE 'CART-%' OR EXISTS (SELECT 1 FROM users operator_user
        JOIN merchant_wallets operator_wallet ON operator_wallet.id = t.merchant_wallet_id
        WHERE operator_user.userID = {$operator}
          AND operator_user.userID <> operator_wallet.user_id
          AND operator_user.merchant_owner_id = operator_wallet.user_id))";
}

/** Direct sales count immediately; staff sales count after remittance, once each. */
function gjc_merchant_confirmed_sale_sql(PDO $db): string
{
    $staffSale = gjc_merchant_staff_sale_sql($db);
    return "(NOT ({$staffSale}) OR EXISTS (SELECT 1 FROM sales_remittances r
        WHERE r.merchant_wallet_id = t.merchant_wallet_id AND DATE(r.period_end) = CURDATE()
          AND t.created_at > r.period_start AND t.created_at <= r.period_end))";
}

function gjc_merchant_owner_id(PDO $db, ?int $userId = null): int
{
    $userId = $userId ?: gjc_user_id();
    if (!$userId) {
        return 0;
    }

    if (!gjc_is_merchant_staff()) {
        return $userId;
    }

    $sessionOwnerId = (int) ($_SESSION["merchant_owner_id"] ?? 0);
    if ($sessionOwnerId > 0) {
        return $sessionOwnerId;
    }

    if (!gjc_table_exists($db, "users")) {
        return $userId;
    }

    $idColumn = gjc_column($db, "users", ["id", "userID"]);
    if (!$idColumn || !in_array("merchant_owner_id", gjc_table_columns($db, "users"), true)) {
        return $userId;
    }

    $stmt = $db->prepare("SELECT merchant_owner_id FROM users WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute([$userId]);
    $ownerId = (int) $stmt->fetchColumn();

    if ($ownerId > 0) {
        $_SESSION["merchant_owner_id"] = $ownerId;
        return $ownerId;
    }

    return $userId;
}

/** Shared "last checked" time per merchant card; clears the badge for all finance admins. */
function gjc_ensure_merchant_card_views_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS merchant_card_views (
            merchant_id     INT NOT NULL,
            last_viewed_at  DATETIME NOT NULL,
            viewed_by       INT NULL,
            PRIMARY KEY (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
}

/** Self-declared sales remittances (no approval, no balance change). */
function gjc_ensure_sales_remittances_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db->exec(
        "CREATE TABLE IF NOT EXISTS sales_remittances (
            id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            merchant_wallet_id  INT UNSIGNED NOT NULL,
            staff_user_id       INT UNSIGNED NOT NULL,
            reference_no        VARCHAR(40) NULL UNIQUE,
            amount              DECIMAL(15,2) NOT NULL,
            txn_count           INT UNSIGNED NOT NULL DEFAULT 0,
            period_start        DATETIME NOT NULL,
            period_end          DATETIME NOT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_remit_wallet (merchant_wallet_id),
            INDEX idx_remit_wallet_period (merchant_wallet_id, period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // merchant_user_id is legacy; allow NULL until the contract drops it.
    if (!$db->inTransaction()) {
        $nullable = $db->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_remittances'
              AND COLUMN_NAME = 'merchant_user_id'")->fetchColumn();
        if ($nullable === 'NO') {
            $db->exec("ALTER TABLE sales_remittances MODIFY COLUMN merchant_user_id INT UNSIGNED NULL");
        }
    }
}

// ─── Merchant compliance documents (newest upload per document wins) ───

/** The documents a stall is expected to keep on file: key => [label, hint]. */
function gjc_merchant_compliance_docs(): array
{
    return [
        'business_permit'  => ['Business permit', 'Mayor or business permit for the stall'],
        'sanitary_permit'  => ['Sanitary permit', 'Health and sanitation clearance'],
        'clearance'        => ['Barangay clearance', 'Clearance from your barangay'],
        'gjc_requirements' => ['GJC requirements', 'School-specific documents'],
    ];
}

function gjc_ensure_merchant_documents_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS merchant_documents (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            merchant_user_id INT UNSIGNED NOT NULL,
            doc_key          VARCHAR(40)  NOT NULL,
            file_path        VARCHAR(500) NOT NULL,
            original_name    VARCHAR(255) NULL,
            uploaded_by      INT UNSIGNED NOT NULL,
            uploaded_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_merchant_doc (merchant_user_id, doc_key)
        ) ENGINE=InnoDB"
    );
}

/** Current file per compliance document: key => ['path', 'source' => upload|application, 'uploaded_at']. */
function gjc_merchant_document_files(PDO $db, int $ownerId, ?array $application = null): array
{
    $files = [];
    foreach (array_keys(gjc_merchant_compliance_docs()) as $key) {
        $path = trim((string) ($application[$key] ?? ''));
        if ($path !== '' && $path !== 'pending_path') {
            $files[$key] = ['path' => $path, 'source' => 'application', 'uploaded_at' => $application['awarded_at'] ?? null];
        }
    }

    if ($ownerId > 0 && gjc_table_exists($db, 'merchant_documents')) {
        $stmt = $db->prepare("SELECT doc_key, file_path, uploaded_at FROM merchant_documents WHERE merchant_user_id = ?");
        $stmt->execute([$ownerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset(gjc_merchant_compliance_docs()[$row['doc_key']])) {
                $files[$row['doc_key']] = ['path' => $row['file_path'], 'source' => 'upload', 'uploaded_at' => $row['uploaded_at']];
            }
        }
    }

    return $files;
}

/** Insert the merchant row for a new merchant user. Returns merchant.id (0 if the table is missing). */
function gjc_insert_merchant_record(PDO $db, int $userId, string $businessName, string $notes, ?string $stallId = null): int
{
    if (!gjc_table_exists($db, 'merchant')) {
        return 0;
    }

    $columns = gjc_table_columns($db, 'merchant', true);
    $payload = [
        'userID' => $userId,
        'stall_name' => $businessName,
        'stall_id' => ($stallId !== null && $stallId !== '') ? $stallId : null,
        'operational_status' => 'active',
        'notes' => $notes !== '' ? $notes : null,
    ];

    $insert = [];
    $values = [];
    foreach ($payload as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insert[] = $column;
            $values[] = $value;
        }
    }

    if (!$insert) {
        return 0;
    }

    $sql = 'INSERT INTO merchant (' . implode(', ', $insert) . ') VALUES (' . implode(', ', array_fill(0, count($insert), '?')) . ')';
    $stmt = $db->prepare($sql);
    $stmt->execute($values);

    return (int) $db->lastInsertId();
}

/** Record an admin-created merchant as an approved stall application. */
function gjc_insert_awarded_merchant_application(PDO $db, array $data, int $userId, int $adminId): int
{
    if (!gjc_table_exists($db, 'stall_applications')) {
        return 0;
    }

    $proprietorName = trim(implode(' ', array_filter(
        [$data['first_name'], $data['middle_name'], $data['last_name'], $data['suffix']],
        fn($part) => $part !== ''
    )));
    $stallId = $data['stall_id'] !== '' ? $data['stall_id'] : null;

    // Address + document columns are left to their '' defaults (not collected here).
    return gjc_stall_application_create($db,[
        'business_name'=>$data['business_name'],'first_name'=>$data['first_name'],
        'middle_name'=>$data['middle_name']!==''?$data['middle_name']:null,'last_name'=>$data['last_name'],
        'suffix'=>$data['suffix']!==''?$data['suffix']:null,'sex'=>$data['sex'],
        'contact_number'=>$data['phone'],'email'=>$data['email'],'preferred_stall_id'=>$stallId,'stall_id'=>$stallId,
        'profile_picture'=>'','business_permit'=>'','sanitary_permit'=>'','gjc_requirements'=>'','clearance'=>'',
        'terms_accepted'=>1,'status'=>'awarded','current_step'=>5,'reviewed_by'=>$adminId,
        'reviewed_at'=>GJC_APPLICATION_DB_NOW,'merchant_user_id'=>$userId,
    ]);
}
