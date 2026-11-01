<?php

/**
 * 3NF CONTRACT: drops columns and tables that duplicate other data
 * (see the gjc_*_contract_schema() functions in connection/app/contracts.php).
 *
 *   php database/migrations/3nf_contract.php            apply
 *   php database/migrations/3nf_contract.php --dry-run  verify + show the plan, change nothing
 *
 * CLI only; run after the code that stopped using these columns is deployed.
 * Each step re-verifies the data and writes a JSON export to backups/ first. Idempotent.
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
foreach (array_keys(gjc_obsolete_tables()) as $table) {
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

$contracts['voucher_removal'] = [
    'run'  => static fn(PDO $db, bool $apply, ?string $archive = null): array =>
        gjc_ensure_voucher_removal_contract_schema($db, $apply, $archive),
    'rows' => static function (PDO $db): array {
        $export = ['transaction_type' => (string) $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'transaction_type'")->fetchColumn()];
        foreach (['voucher_payment_log', 'vouchers'] as $table) {
            if (gjc_table_exists($db, $table)) {
                $export[$table] = gjc_table_export($db, $table);
            }
        }
        return $export;
    },
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
