<?php

// Database introspection and the self-healing core schema (ensure_* helpers not owned by one feature).
// Loaded by connection/app.php; defines functions and constants only.

function gjc_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
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
    // Fee columns on transactions. 3NF: this is the only place top-up fees are
    // stored (base/credited always equalled amount and were dropped; the old
    // topup_requests copies and fee_revenue_log were dropped too).
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

/**
 * Makes merchant_inventory.sku safe to use as a scan key for the student
 * cart: a SKU must be unique within a single merchant's catalog (NULL skus
 * are exempt, so optional SKUs still work). Attempts the index once; if
 * older duplicate data blocks it, the ALTER is skipped silently — app-layer
 * checks in merchant/api/inventory.php still prevent new collisions either way.
 */
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
        // Existing duplicate SKUs from before this rule existed — leave the
        // index off for now; app-layer validation still blocks new clashes.
    }
}

/**
 * Widens transactions.transaction_type to include every label in
 * $requiredTypes, by reading the CURRENT live enum and rebuilding the
 * MODIFY with the union — never a hardcoded replacement list, which would
 * silently drop any label added by a migration this function doesn't know
 * about (that was the bug in the old gjc_ensure_transaction_refund_type()).
 * Call this from a page/dispatcher BEFORE any beginTransaction() — it's DDL,
 * and MySQL implicitly commits an open transaction the instant it sees DDL.
 */
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

/**
 * Same safe widen-from-the-live-enum approach as gjc_ensure_transaction_types(),
 * generalized to any ENUM column — used for cap_increase_log.source so new mint
 * sources don't need a bespoke widener each time. Call before beginTransaction();
 * this is DDL and MySQL implicitly commits an open transaction the instant it
 * sees it.
 */
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

/**
 * Widens transactions.transaction_type to include 'refund' (used when a
 * merchant issues a Return). Kept as its own function since
 * merchant/api/returns.php already calls it by this name.
 */
function gjc_ensure_transaction_refund_type(PDO $db): void
{
    gjc_ensure_transaction_types($db, ['refund']);
}

/** Explicit CLI cleanup; export the empty legacy definitions before calling. */
function gjc_ensure_legacy_table_cleanup_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Legacy table cleanup must be invoked explicitly from the CLI.');
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('Legacy table cleanup cannot run inside a transaction.');
    }
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Legacy table cleanup requires the ewallet database.');
    }

    $expected = [
        'topup' => ['topupID', 'adminID', 'userID', 'wallet_id', 'amount', 'remarks', 'date_time'],
        'transaction' => ['transactionID', 'wallet_id', 'merchantID', 'amount', 'date_time', 'description', 'reference'],
        'wallet' => ['wallet_id', 'userID', 'balance', 'last_updated'],
    ];
    $existing = array_filter(array_keys($expected), fn($table) => gjc_table_exists($db, $table));
    if (!$existing) {
        return;
    }

    $archivePath = BASE_PATH . '/storage/backups/genpay-legacy-table-definitions-2026-10-08.sql';
    $archive = is_file($archivePath) ? file_get_contents($archivePath) : false;
    if ($archive === false) {
        throw new RuntimeException('The verified legacy table definition export is required before cleanup.');
    }
    foreach ($existing as $table) {
        $columns = gjc_table_columns($db, $table, true);
        $required = $expected[$table];
        sort($columns);
        sort($required);
        if ($columns !== $required) {
            throw new RuntimeException('Unexpected legacy table structure: ' . $table);
        }
        $ddl = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        if (!str_contains($archive, $ddl . ';')) {
            throw new RuntimeException('Legacy table export does not match the live definition: ' . $table);
        }
        if ((int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() !== 0) {
            throw new RuntimeException('Refusing to drop a nonempty legacy table: ' . $table);
        }
    }

    $db->exec('DROP TABLE IF EXISTS `ewallet`.`topup`, `ewallet`.`transaction`, `ewallet`.`wallet`');
}

/**
 * Explicit Group B wallet-link EXPAND; preflight both tables before any DDL.
 */
function gjc_ensure_group_b_wallet_links_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Group B wallet-link expansion requires an explicit CLI call outside a transaction.');
    }
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Group B wallet-link expansion requires the ewallet database.');
    }
    $plan = [
        'parent_alerts' => 'fk_group_b_parent_alerts_student_wallet',
        'tuition_credits' => 'fk_group_b_tuition_credits_student_wallet',
    ];
    foreach (array_merge(['student_wallets'], array_keys($plan)) as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required wallet-link table is missing: ' . $table);
        }
    }
    $metadata = [];
    foreach ($db->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('student_wallets', 'parent_alerts', 'tuition_credits')")
        ->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metadata[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    }
    if (($metadata['student_wallets']['id']['COLUMN_TYPE'] ?? null) !== 'int unsigned') {
        throw new RuntimeException('Unexpected student wallet primary-key type.');
    }
    $existing = $db->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
        k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
         AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL")
        ->fetchAll(PDO::FETCH_ASSOC);
    $additions = [];
    foreach ($plan as $table => $name) {
        $column = $metadata[$table]['student_wallet_id'] ?? null;
        if (!$column || $column['COLUMN_TYPE'] !== 'int unsigned' || $column['IS_NULLABLE'] !== 'NO') {
            throw new RuntimeException('Unexpected student-wallet reference definition: ' . $table);
        }
        if ((int) $db->query("SELECT COUNT(*) FROM `{$table}` c
            LEFT JOIN student_wallets sw ON sw.id = c.student_wallet_id
            WHERE sw.id IS NULL")->fetchColumn() !== 0) {
            throw new RuntimeException('Orphan student-wallet references block expansion: ' . $table);
        }
        $matches = array_values(array_filter($existing,
            static fn($row) => $row['CONSTRAINT_NAME'] === $name));
        foreach ($existing as $row) {
            if ($row['TABLE_NAME'] === $table && $row['COLUMN_NAME'] === 'student_wallet_id'
                && $row['CONSTRAINT_NAME'] !== $name) {
                throw new RuntimeException('An unplanned foreign key uses: ' . $table . '.student_wallet_id');
            }
        }
        if ($matches) {
            $row = $matches[0];
            if (count($matches) !== 1 || $row['TABLE_NAME'] !== $table
                || $row['COLUMN_NAME'] !== 'student_wallet_id'
                || $row['REFERENCED_TABLE_NAME'] !== 'student_wallets'
                || $row['REFERENCED_COLUMN_NAME'] !== 'id'
                || $row['DELETE_RULE'] !== 'RESTRICT' || $row['UPDATE_RULE'] !== 'RESTRICT') {
                throw new RuntimeException('Conflicting wallet-link foreign key: ' . $name);
            }
        } else {
            $additions[$table] = $name;
        }
    }
    foreach ($additions as $table => $name) {
        $db->exec("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}`
            FOREIGN KEY (student_wallet_id) REFERENCES student_wallets(id)
            ON DELETE RESTRICT ON UPDATE RESTRICT");
    }
}

/** Explicit core-only migration; preflight all relationships before any DDL. */
function gjc_ensure_core_integrity_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Core integrity migration requires an explicit CLI call outside a transaction.');
    }
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Core integrity migration requires the ewallet database.');
    }
    $plan = [
        'users' => [
            'roleID' => ['role', 'roleID', 'RESTRICT'],
            'suspended_by' => ['users', 'userID', 'RESTRICT'],
        ],
        'auth_remember_tokens' => [
            'user_id' => ['users', 'userID', 'CASCADE'],
        ],
        'student_wallets' => [
            'user_id' => ['users', 'userID', 'RESTRICT'],
        ],
        'parents' => [
            'user_id' => ['users', 'userID', 'RESTRICT'],
        ],
        'parent_wallets' => [
            'parent_id' => ['parents', 'id', 'RESTRICT'],
        ],
        'parent_student_links' => [
            'parent_id' => ['parents', 'id', 'CASCADE'],
            'student_user_id' => ['users', 'userID', 'CASCADE'],
        ],
        'parent_link_requests' => [
            'parent_id' => ['parents', 'id', 'CASCADE'],
            'student_user_id' => ['users', 'userID', 'CASCADE'],
        ],
        'cart_orders' => [
            'student_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'parent_wallet_id' => ['parent_wallets', 'id', 'RESTRICT'],
            'served_by' => ['users', 'userID', 'RESTRICT'],
            'paid_ref' => ['transactions', 'reference_no', 'RESTRICT'],
        ],
        'merchant_qr_orders' => [
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'paid_ref' => ['transactions', 'reference_no', 'RESTRICT'],
        ],
        'transactions' => [
            'initiated_by' => ['users', 'userID', 'RESTRICT'],
            'student_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'parent_wallet_id' => ['parent_wallets', 'id', 'RESTRICT'],
            'school_year_id' => ['school_years', 'id', 'RESTRICT'],
        ],
        'transaction_items' => [
            'transaction_id' => ['transactions', 'id', 'RESTRICT'],
        ],
        'p2p_transfers' => [
            'to_wallet_id' => ['student_wallets', 'id', 'RESTRICT'],
            'reference_no' => ['transactions', 'reference_no', 'RESTRICT'],
        ],
        'encashment_requests' => [
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
            'released_by' => ['users', 'userID', 'RESTRICT'],
            'rejected_by' => ['users', 'userID', 'RESTRICT'],
        ],
        'sales_remittances' => [
            'merchant_wallet_id' => ['merchant_wallets', 'id', 'RESTRICT'],
        ],
        'maintenance_fees' => [
            'reference_no' => ['transactions', 'reference_no', 'RESTRICT'],
            'collection_id' => ['maintenance_fee_collections', 'id', 'RESTRICT'],
        ],
    ];
    // The normalized schema owns this relationship after suspension CONTRACT.
    if (!gjc_has_legacy_user_suspension($db)) {
        unset($plan['users']['suspended_by']);
    }
    $metadata = [];
    foreach ($db->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metadata[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    }
    $existing = [];
    foreach ($db->query('SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[$row['CONSTRAINT_NAME']][] = $row;
    }
    $typeChanges = [];
    $additions = [];
    foreach ($plan as $table => $columns) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Core integrity source table is missing: ' . $table);
        }
        $liveColumns = gjc_table_columns($db, $table, true);
        $ddl = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
        foreach ($columns as $column => [$parent, $parentKey, $deleteRule]) {
            if (!in_array($column, $liveColumns, true) || !isset($metadata[$parent][$parentKey])) {
                throw new RuntimeException('Core integrity column is missing: ' . $table . '.' . $column);
            }
            $source = $metadata[$table][$column];
            $target = $metadata[$parent][$parentKey];
            $isUserReference = $parent === 'users' && $parentKey === 'userID';
            if ($isUserReference) {
                if ($target['COLUMN_TYPE'] !== 'int' || !in_array($source['COLUMN_TYPE'], ['int', 'int unsigned'], true)) {
                    throw new RuntimeException('Unexpected user reference type: ' . $table . '.' . $column);
                }
                $invalidRange = (int) $db->query("SELECT COUNT(*) FROM `{$table}`
                    WHERE `{$column}` < 0 OR `{$column}` > 2147483647")->fetchColumn();
                if ($invalidRange) {
                    throw new RuntimeException('User reference is outside the approved signed range: ' . $table . '.' . $column);
                }
                if ($source['COLUMN_TYPE'] === 'int unsigned') {
                    $pattern = '/^  (`' . preg_quote($column, '/') . '` )int unsigned(.*),$/mi';
                    if (!preg_match($pattern, $ddl, $match)) {
                        throw new RuntimeException('Cannot preserve the live column definition: ' . $table . '.' . $column);
                    }
                    $typeChanges[$table][] = 'MODIFY COLUMN ' . $match[1] . 'int' . $match[2];
                }
            } elseif ($source['CHARACTER_SET_NAME'] !== null || $target['CHARACTER_SET_NAME'] !== null) {
                if (!str_starts_with($source['COLUMN_TYPE'], 'varchar(')
                    || !str_starts_with($target['COLUMN_TYPE'], 'varchar(')
                    || $source['CHARACTER_SET_NAME'] !== $target['CHARACTER_SET_NAME']
                    || $source['COLLATION_NAME'] !== $target['COLLATION_NAME']) {
                    throw new RuntimeException('Incompatible text reference: ' . $table . '.' . $column);
                }
            } elseif ($source['COLUMN_TYPE'] !== $target['COLUMN_TYPE']) {
                throw new RuntimeException('Incompatible numeric reference: ' . $table . '.' . $column);
            }
            $orphans = (int) $db->query("SELECT COUNT(*) FROM `{$table}` c
                LEFT JOIN `{$parent}` p ON c.`{$column}` = p.`{$parentKey}`
                WHERE c.`{$column}` IS NOT NULL AND p.`{$parentKey}` IS NULL")->fetchColumn();
            if ($orphans) {
                throw new RuntimeException('Orphan records block core integrity: ' . $table . '.' . $column);
            }
            $name = 'fk_core_' . $table . '_' . $column;
            if (strlen($name) > 64) {
                throw new RuntimeException('Core constraint name is too long.');
            }
            if (isset($existing[$name])) {
                $rows = $existing[$name];
                $row = $rows[0];
                if (count($rows) !== 1 || $row['TABLE_NAME'] !== $table || $row['COLUMN_NAME'] !== $column
                    || $row['REFERENCED_TABLE_NAME'] !== $parent || $row['REFERENCED_COLUMN_NAME'] !== $parentKey
                    || $row['DELETE_RULE'] !== $deleteRule || $row['UPDATE_RULE'] !== 'RESTRICT') {
                    throw new RuntimeException('Conflicting core constraint: ' . $name);
                }
            } else {
                foreach ($existing as $rows) {
                    foreach ($rows as $row) {
                        if ($row['TABLE_NAME'] === $table && $row['COLUMN_NAME'] === $column) {
                            throw new RuntimeException('An unplanned foreign key already uses: ' . $table . '.' . $column);
                        }
                    }
                }
                $additions[$table][] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`)
                    REFERENCES `{$parent}` (`{$parentKey}`) ON DELETE {$deleteRule} ON UPDATE RESTRICT";
            }
        }
    }

    if (!$typeChanges && !$additions) {
        return;
    }
    $archivePath = BASE_PATH . '/storage/backups/genpay-core-integrity-before-2026-10-08.sql';
    if (!is_file($archivePath) || !is_readable($archivePath)) {
        throw new RuntimeException('The core integrity definition export is required before DDL.');
    }
    // No foreign-key checks are disabled. Each ALTER is independently committed by MySQL.
    foreach ($typeChanges as $table => $changes) {
        $db->exec("ALTER TABLE `{$table}` " . implode(', ', $changes));
        gjc_table_columns($db, $table, true);
    }
    foreach ($additions as $table => $changes) {
        $db->exec("ALTER TABLE `{$table}` " . implode(', ', $changes));
    }
}

function gjc_ensure_merchant_application_product_types_schema(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new RuntimeException('Initialize product-type schema before a transaction.');
    }
    if (!gjc_table_exists($db, 'merchant_applications')) {
        throw new RuntimeException('Merchant applications table is missing.');
    }

    $parent = $db->query(
        "SHOW COLUMNS FROM merchant_applications LIKE 'id'"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$parent || strtolower($parent['Type']) !== 'int unsigned') {
        throw new RuntimeException('Review application ID type before adding a foreign key.');
    }

    if (!gjc_table_exists($db, 'merchant_application_product_types')) {
        $db->exec("CREATE TABLE merchant_application_product_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            merchant_application_id INT UNSIGNED NOT NULL,
            product_type VARCHAR(120) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_map_application (merchant_application_id),
            CONSTRAINT fk_map_application
                FOREIGN KEY (merchant_application_id)
                REFERENCES merchant_applications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_general_ci");
    }

    $columns = gjc_table_columns($db, 'merchant_application_product_types', true);
    foreach (['id', 'merchant_application_id', 'product_type'] as $column) {
        if (!in_array($column, $columns, true)) {
            throw new RuntimeException('Unexpected product-type table structure.');
        }
    }

    $duplicates = $db->query(
        "SELECT 1 FROM merchant_application_product_types
         GROUP BY merchant_application_id, product_type
         HAVING COUNT(*) > 1 LIMIT 1"
    )->fetchColumn();
    if ($duplicates !== false) {
        throw new RuntimeException('Resolve duplicate product types before adding uniqueness.');
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'merchant_application_product_types'
           AND INDEX_NAME = 'uq_map_application_product_type'"
    );
    $stmt->execute();
    if (!(int) $stmt->fetchColumn()) {
        $db->exec(
            "ALTER TABLE merchant_application_product_types
             ADD UNIQUE KEY uq_map_application_product_type
                 (merchant_application_id, product_type)"
        );
    }
}

function gjc_ensure_merchant_wallet_owner_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Merchant-wallet owner schema requires an explicit CLI call outside a transaction.');
    }
    if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Expected the ewallet database.');
    }
    foreach (['users' => 'userID', 'merchant_wallets' => 'user_id'] as $table => $column) {
        if (!gjc_table_exists($db, $table)
            || !in_array($column, gjc_table_columns($db, $table, true), true)) {
            throw new RuntimeException('Required wallet-owner table or column is missing.');
        }
    }
    $parent = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    $child = $db->query("SHOW COLUMNS FROM merchant_wallets LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
    if ($parent['Type'] !== 'int' || $parent['Null'] !== 'NO'
        || !in_array($child['Type'], ['int', 'int unsigned'], true)
        || $child['Null'] !== 'NO') {
        throw new RuntimeException('Unexpected owner column definitions; review before changing types.');
    }
    $invalid = $db->query(
        'SELECT 1 FROM merchant_wallets w
         LEFT JOIN users u ON u.userID = w.user_id
         WHERE u.userID IS NULL OR w.user_id > 2147483647 LIMIT 1'
    )->fetchColumn();
    if ($invalid !== false) {
        throw new RuntimeException('Missing wallet owners or owner IDs outside signed INT range.');
    }
    $foreignKeys = $db->query(
        "SELECT k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                r.DELETE_RULE, r.UPDATE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
          AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
         WHERE k.TABLE_SCHEMA = DATABASE()
           AND k.TABLE_NAME = 'merchant_wallets'
           AND k.COLUMN_NAME = 'user_id'"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($foreignKeys) {
        $fk = $foreignKeys[0];
        if (count($foreignKeys) !== 1 || $child['Type'] !== 'int'
            || $fk['REFERENCED_TABLE_NAME'] !== 'users'
            || $fk['REFERENCED_COLUMN_NAME'] !== 'userID'
            || $fk['DELETE_RULE'] !== 'RESTRICT' || $fk['UPDATE_RULE'] !== 'RESTRICT') {
            throw new RuntimeException('Unexpected existing owner foreign key; no changes applied.');
        }
        return;
    }
    if ((int) $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME = 'fk_core_merchant_wallets_user_id'"
    )->fetchColumn()) {
        throw new RuntimeException('Owner foreign-key name is already in use.');
    }
    $changes = [];
    if ($child['Type'] === 'int unsigned') {
        $changes[] = "MODIFY COLUMN user_id INT NOT NULL COMMENT 'FK -> users.userID (merchant role)'";
    }
    $changes[] = 'ADD CONSTRAINT fk_core_merchant_wallets_user_id
                  FOREIGN KEY (user_id) REFERENCES users(userID)
                  ON DELETE RESTRICT ON UPDATE RESTRICT';
    $db->exec('ALTER TABLE merchant_wallets ' . implode(', ', $changes));
    gjc_table_columns($db, 'merchant_wallets', true);
}

function gjc_ensure_group_a_normalized_schema(PDO $db): void
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Group A CONTRACT requires an explicit CLI call outside a transaction.');
    }
    $targets = [
        'cart_orders' => 'items_json',
        'merchant_qr_orders' => 'items_json',
        'merchant_applications' => 'product_types',
    ];
    $hasLegacy = [];
    foreach ($targets as $table => $column) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Missing Group A table: ' . $table);
        }
        $hasLegacy[$table] = in_array($column, gjc_table_columns($db, $table, true), true);
    }
    foreach (['cart_order_items', 'qr_order_items', 'merchant_application_product_types'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Missing normalized table: ' . $table);
        }
    }

    // Validate all sources before the first DROP.
    foreach (['cart' => 'cart_orders', 'qr' => 'merchant_qr_orders'] as $source => $table) {
        $select = 'id' . ($hasLegacy[$table] ? ', items_json' : '');
        foreach ($db->query("SELECT {$select} FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $actual = gjc_order_item_lines($db, $source, (int) $row['id']);
            if (!$actual) {
                throw new RuntimeException('Missing official items for ' . $table . ' #' . $row['id']);
            }
            if (!$hasLegacy[$table]) {
                continue;
            }
            $expected = gjc_validate_order_item_lines(json_decode($row['items_json'], true, 512, JSON_THROW_ON_ERROR));
            if (count($actual) !== count($expected)) {
                throw new RuntimeException('Order-item count mismatch.');
            }
            $fields = $source === 'cart'
                ? ['id', 'name', 'qty', 'price', 'sku', 'stock_qty', 'line_total']
                : ['id', 'name', 'qty', 'price'];
            foreach ($expected as $i => $line) {
                if (array_diff(array_keys($line), $fields)) {
                    throw new RuntimeException('Unmapped JSON item fields; stop before CONTRACT.');
                }
                foreach ($fields as $field) {
                    $value = $line[$field] ?? null;
                    if ($field === 'line_total' && $value === null) {
                        $value = round($line['qty'] * $line['price'], 2);
                    }
                    $saved = $actual[$i][$field] ?? null;
                    $matches = in_array($field, ['name', 'sku'], true) ? $saved === $value : $saved == $value;
                    if (!$matches) {
                        throw new RuntimeException('Order-item snapshot mismatch.');
                    }
                }
            }
        }
    }
    if ($hasLegacy['merchant_applications']) {
        foreach ($db->query('SELECT id, product_types FROM merchant_applications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $expected = array_values(array_filter(array_map('trim', explode(',', $row['product_types'])), static fn($value) => $value !== ''));
            $stmt = $db->prepare('SELECT product_type FROM merchant_application_product_types WHERE merchant_application_id = ? ORDER BY id');
            $stmt->execute([$row['id']]);
            $actual = $stmt->fetchAll(PDO::FETCH_COLUMN);
            sort($expected);
            sort($actual);
            if ($expected !== $actual) {
                throw new RuntimeException('Application product types do not match.');
            }
        }
    }

    foreach ($targets as $table => $column) {
        if ($hasLegacy[$table]) {
            $db->exec("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            gjc_table_columns($db, $table, true);
        }
    }
}
