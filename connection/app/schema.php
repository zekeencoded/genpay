<?php

// Database introspection and the self-healing core schema.

function gjc_table_exists(PDO $db, string $table): bool
{
    // Cache only "exists"; skip caching on CLI (migrations drop tables).
    static $exists = [];
    if (isset($exists[$table])) {
        return true;
    }
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    $found = (int) $stmt->fetchColumn() > 0;
    if ($found && PHP_SAPI !== 'cli') {
        $exists[$table] = true;
    }
    return $found;
}

function gjc_table_columns(PDO $db, string $table, bool $refresh = false): array
{
    static $cache = [];
    if (!$refresh && isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $db->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
    );
    $stmt->execute([$table]);
    return $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function gjc_column(PDO $db, string $table, array $candidates): ?string
{
    $columns = gjc_table_columns($db, $table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function gjc_ensure_operational_tables(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS encashment_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            merchant_wallet_id INT UNSIGNED NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            method VARCHAR(80) NOT NULL DEFAULT 'Cashier Release',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reference_no VARCHAR(40) NULL UNIQUE,
            released_by INT NULL,
            released_at DATETIME NULL,
            rejected_by INT NULL,
            rejected_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_encash_wallet (merchant_wallet_id),
            INDEX idx_encash_status (status)
        ) ENGINE=InnoDB",
    );

    $encashAdds = [
        "merchant_wallet_id" => "INT UNSIGNED NULL",
        "method" => "VARCHAR(80) NOT NULL DEFAULT 'Cashier Release'",
        "reference_no" => "VARCHAR(40) NULL",
        "released_by" => "INT NULL",
        "released_at" => "DATETIME NULL",
        "rejected_by" => "INT NULL",
        "rejected_at" => "DATETIME NULL",
        "created_at" => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($encashAdds as $column => $definition) {
        if (
            !in_array(
                $column,
                gjc_table_columns($db, "encashment_requests"),
                true,
            )
        ) {
            $db->exec(
                "ALTER TABLE encashment_requests ADD COLUMN {$column} {$definition}",
            );
        }
    }

    gjc_ensure_fee_schema($db);
}

function gjc_ensure_fee_schema(PDO $db): void
{
    // Fee columns on transactions (the only place top-up fees are stored).
    foreach ([
        'top_up_source  VARCHAR(20) NULL',
        'fee_amount     DECIMAL(15,2) NULL',
        // 3NF: the merchant's share of fee_amount; school fee = fee_amount - merchant_fee.
        'merchant_fee    DECIMAL(15,2) NULL AFTER fee_amount',
    ] as $col) {
        try { $db->exec("ALTER TABLE transactions ADD COLUMN $col"); }
        catch (\PDOException $e) { /* already exists */ }
    }
    // Backfill the split from fee_revenue_log (the only other place it was kept).
    if (gjc_table_exists($db, 'fee_revenue_log')) {
        $db->exec(
            "UPDATE transactions t JOIN fee_revenue_log f ON f.transaction_ref = t.reference_no
                SET t.merchant_fee = f.merchant_fee
              WHERE t.merchant_fee IS NULL AND t.fee_amount IS NOT NULL"
        );
    }
}

/** Unique (merchant, sku) index; skipped if old duplicates exist. */
function gjc_ensure_inventory_sku_index(PDO $db): void
{
    if (!gjc_table_exists($db, "merchant_inventory")) {
        return;
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'merchant_inventory' AND INDEX_NAME = 'uq_merchant_sku'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    try {
        $db->exec(
            "ALTER TABLE merchant_inventory ADD UNIQUE KEY uq_merchant_sku (merchant_user_id, sku)"
        );
    } catch (\Throwable $e) {
        // Old duplicates block the index; app validation still prevents new ones.
    }
}

/** Add labels to transactions.transaction_type, merged with the live enum. DDL: call before beginTransaction(). */
function gjc_ensure_transaction_types(PDO $db, array $requiredTypes): void
{
    try {
        $column = $db->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
        $type = (string) ($column['Type'] ?? '');
        if (!$column) {
            return;
        }

        $missing = false;
        foreach ($requiredTypes as $required) {
            if (strpos($type, "'{$required}'") === false) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $labelMatches);
        $labels = $labelMatches[1] ?? [];
        foreach ($requiredTypes as $required) {
            if (!in_array($required, $labels, true)) {
                $labels[] = $required;
            }
        }
        $enumSql = implode(', ', array_map(
            static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
            $labels
        ));
        $db->exec("ALTER TABLE transactions MODIFY transaction_type ENUM({$enumSql}) NOT NULL");
    } catch (\Throwable $e) {
        // Leave the ENUM as-is; the insert below will surface the failure clearly.
    }
}

/** Add values to any ENUM column, merged with the live enum. DDL: call before beginTransaction(). */
function gjc_ensure_enum_values(PDO $db, string $table, string $column, array $required): void
{
    try {
        $column_info = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column))->fetch(PDO::FETCH_ASSOC);
        if (!$column_info) {
            return;
        }
        $type = (string) ($column_info['Type'] ?? '');

        $missing = false;
        foreach ($required as $value) {
            if (strpos($type, "'{$value}'") === false) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $labelMatches);
        $labels = $labelMatches[1] ?? [];
        foreach ($required as $value) {
            if (!in_array($value, $labels, true)) {
                $labels[] = $value;
            }
        }
        $enumSql = implode(', ', array_map(
            static fn(string $label): string => "'" . str_replace("'", "\\'", $label) . "'",
            $labels
        ));
        $null = stripos($type, 'NOT NULL') !== false ? ' NOT NULL' : '';
        $db->exec("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$enumSql}){$null}");
    } catch (\Throwable $e) {
        // Leave the ENUM as-is; the insert below will surface the failure clearly.
    }
}

/** Add the 'refund' transaction type. */
function gjc_ensure_transaction_refund_type(PDO $db): void
{
    gjc_ensure_transaction_types($db, ['refund']);
}












