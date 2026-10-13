<?php

/**
 * 3NF CONTRACT: drops columns that duplicated other data.
 *
 *   student_info.yr_lvl  year level is per school year (student_enrollments.yr_lvl);
 *                        student_info keeps only entry_yr_lvl.
 *   users.sub_role       derived from users.roleID by gjc_sub_role_for_role_id().
 *   topup_requests.user_id, withdrawal_requests.user_id, encashment_requests.user_id,
 *   sales_remittances.merchant_user_id
 *                        implied by the row's wallet (gjc_wallet_owner_copies());
 *                        the wallet column becomes NOT NULL with a RESTRICT foreign key.
 *   transactions.base_amount/credited_amount, topup_requests fee copies
 *                        always equal the ledger row's amount/fee (gjc_derived_fee_columns()).
 *   fee_revenue_log, parent_topup_requests, school_revenue_ledger
 *                        whole tables dropped: the first duplicates the ledger (the fee
 *                        split now lives on transactions.merchant_fee), the others are
 *                        empty and unused (gjc_obsolete_fee_tables()).
 *   vouchers.use_count/last_used_at, maintenance_fee_collections.entry_count/
 *   total_amount, system_settings.last_cap_increased_by/_at
 *                        stored totals derivable from other rows (gjc_derived_total_columns()).
 *   transaction_items.subtotal
 *                        converted (not dropped) to a STORED generated column.
 *   cart_orders.buyer_role, p2p_transfers ledger copies, merchant_leases.stall_number,
 *   stalls.merchant_id (+ stored 'occupied'; merchant.stall_id becomes UNIQUE)
 *                        quick fixes (gjc_quickfix_contracts()).
 *   maintenance_fees     drops the copies of its ledger row and receipt (student, type,
 *                        amount, school year, time, collected at/by); reference_no becomes
 *                        UNIQUE with RESTRICT foreign keys and a status/collection CHECK.
 *
 *   php database/migrations/3nf_contract.php            apply
 *   php database/migrations/3nf_contract.php --dry-run  verify + show the plan, change nothing
 *
 * CLI only. Run it only after the code that no longer reads or writes these
 * columns is deployed. Each contract re-verifies the data (every entry_yr_lvl
 * equals yr_lvl; every stored sub_role equals the one derived from roleID; every
 * user copy equals its wallet's owner) and
 * refuses if any key, index, trigger, view or routine still names the column.
 *
 * Before dropping anything it writes a private JSON export of the dropped
 * values to backups/ (git-ignored) and the contract checks the export matches.
 * Idempotent: an already-contracted column is skipped.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This migration is CLI only.\n");
}

require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

$dryRun = in_array('--dry-run', $argv, true);

$contracts = [
    'student_info.yr_lvl' => [
        'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
            gjc_ensure_student_entry_year_contract_schema($db, $apply, $archive),
        'rows' => static fn(PDO $db): array => $db->query(
            'SELECT userID, yr_lvl FROM student_info ORDER BY userID'
        )->fetchAll(PDO::FETCH_ASSOC),
    ],
    'users.sub_role' => [
        'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
            gjc_ensure_role_contract_schema($db, $apply, $archive),
        'rows' => static fn(PDO $db): array => $db->query(
            'SELECT userID, roleID, sub_role FROM users ORDER BY userID'
        )->fetchAll(PDO::FETCH_ASSOC),
    ],
];
foreach (gjc_wallet_owner_copies() as $table => [$copy, $walletCol]) {
    $contracts["{$table}.{$copy}"] = [
        'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
            gjc_ensure_wallet_owner_contract_schema($db, $table, $apply, $archive),
        'rows' => static fn(PDO $db): array => $db->query(
            "SELECT id, `{$copy}`, `{$walletCol}` FROM `{$table}` ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC),
    ];
}

foreach (gjc_derived_columns() as $table => [$columns]) {
    $contracts["{$table}:" . implode(',', $columns)] = [
        'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
            gjc_ensure_derived_columns_contract_schema($db, $table, $apply, $archive),
        'rows' => static fn(PDO $db): array => $db->query(
            'SELECT id, ' . implode(', ', array_map(static fn($c) => "`{$c}`", $columns)) . " FROM `{$table}` ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC),
    ];
}
$contracts['transaction_items:subtotal'] = [
    'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
        gjc_ensure_transaction_items_subtotal_contract_schema($db, $apply, $archive),
    'rows' => static fn(PDO $db): array => $db->query('SELECT id, subtotal FROM transaction_items ORDER BY id')
        ->fetchAll(PDO::FETCH_ASSOC),
];
foreach (array_keys(gjc_obsolete_fee_tables()) as $table) {
    $contracts["table:{$table}"] = [
        'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
            gjc_ensure_obsolete_fee_table_contract_schema($db, $table, $apply, $archive),
        'rows' => static fn(PDO $db): array => gjc_table_export($db, $table),
    ];
}

foreach (gjc_quickfix_contracts() as $key => [$table]) {
    $contracts[$key] = [
        'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
            gjc_ensure_quickfix_contract_schema($db, $key, $apply, $archive),
        'rows' => static fn(PDO $db): array => gjc_quickfix_export($db, $table),
    ];
}

$contracts['maintenance_fees:ledger_and_receipt_copies'] = [
    'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
        gjc_ensure_maintenance_fees_contract_schema($db, $apply, $archive),
    'rows' => static fn(PDO $db): array => $db->query(
        'SELECT id, reference_no, ' . implode(', ', array_map(static fn($c) => "`{$c}`", gjc_maintenance_fee_legacy_columns()))
        . ' FROM maintenance_fees ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC),
];

try {
    // Verify everything before touching anything.
    $plans = [];
    foreach ($contracts as $column => $contract) {
        $plans[$column] = $contract['run']($db, false);
        echo $plans[$column]
            ? "[plan] {$column}: " . implode('; ', $plans[$column]) . "\n"
            : "[skip] {$column}: already contracted\n";
    }
    if ($dryRun) {
        echo "Dry run: data verified, nothing changed.\n";
        exit(0);
    }

    $archive = [];
    foreach ($contracts as $column => $contract) {
        if ($plans[$column]) {
            $archive[$column] = $contract['rows']($db);
        }
    }
    if (!$archive) {
        echo "Nothing to do.\n";
        exit(0);
    }

    $dir = __DIR__ . '/../../backups';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new RuntimeException('Could not create backups/.');
    }
    $archivePath = $dir . '/3nf-contract-' . date('Ymd-His') . '.json';
    file_put_contents($archivePath, json_encode($archive, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo "[export] {$archivePath}\n";

    foreach ($contracts as $column => $contract) {
        if ($plans[$column]) {
            $contract['run']($db, true, $archivePath);
            echo "[done] {$column}\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[refused] ' . $e->getMessage() . "\n");
    exit(1);
}
