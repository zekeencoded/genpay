<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';

gjc_require_role(['finance']);

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'type' => trim((string) ($_GET['type'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
];

// sqlCap 0: no cap on any source. This file is the reconciliation
// export - a short CSV that looks complete is the worst outcome.
$transactions = gjc_fetch_admin_transactions($db, $filters, 0, 0);

// Bulk extraction of the whole ledger — every reference, amount, sender and
// receiver — leaving the system as a file. It was completely unlogged: there
// was no way to tell afterwards that anyone had taken a copy, or what of.
logAudit(
    $db,
    gjc_user_id(),
    gjc_current_role(),
    'DATA_EXPORT',
    'transactions',
    null,
    [
        'format'    => 'csv',
        'filters'   => array_filter($filters, static fn($v): bool => $v !== ''),
        'row_count' => count($transactions),
        'columns'   => ['Reference', 'Type', 'Amount', 'Sender', 'Receiver', 'Status', 'Time', 'Source', 'Notes'],
    ],
    null,
    [
        'target_id' => 'transactions-' . date('Ymd-His') . '.csv',
        'reason'    => count($transactions) . ' ledger row(s) exported to CSV.',
    ]
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="transactions-' . date('Ymd-His') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Reference', 'Type', 'Amount', 'Sender', 'Receiver', 'Status', 'Time', 'Source', 'Notes']);

foreach ($transactions as $transaction) {
    fputcsv($output, [
        $transaction['ref'],
        $transaction['type_label'],
        number_format((float) $transaction['amount'], 2, '.', ''),
        $transaction['sender'],
        $transaction['receiver'],
        $transaction['status_label'],
        $transaction['created_at'],
        $transaction['source'],
        $transaction['notes'],
    ]);
}

fclose($output);
