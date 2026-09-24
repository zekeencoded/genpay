<?php
// ============================================================
//  student/api/wallet_summary.php
//  Everything the student dashboard shows, as one JSON payload:
//  balance, GenCoin, stats, account status, and the latest five
//  transactions. Polled by student/dashboard.php.
// ============================================================
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

header('Content-Type: application/json');
gjc_require_role(['student']);

try {
    $currentUser = gjc_current_user($db);
    $wallet = gjc_student_wallet($db, (int) $currentUser['id']);

    $balance = (float) $wallet['balance'];
    $totalSpent = 0.0;
    $totalTxns = 0;
    $transactions = [];

    if ($wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
        $spentStmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
              WHERE student_wallet_id = ?
                AND transaction_type IN ('payment', 'voucher_payment')
                AND status = 'completed'"
        );
        $spentStmt->execute([$wallet['id']]);
        $totalSpent = (float) $spentStmt->fetchColumn();

        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM transactions WHERE student_wallet_id = ?"
        );
        $countStmt->execute([$wallet['id']]);
        $totalTxns = (int) $countStmt->fetchColumn();

        $txnStmt = $db->prepare(
            "SELECT reference_no, transaction_type, amount, created_at
               FROM transactions
              WHERE student_wallet_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 5"
        );
        $txnStmt->execute([$wallet['id']]);

        foreach ($txnStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meta = gjc_student_txn_meta((string) $row['transaction_type']);
            $transactions[] = [
                'ref' => (string) $row['reference_no'],
                'slug' => $meta['slug'],
                'icon' => $meta['icon'],
                'label' => $meta['label'],
                'incoming' => $meta['incoming'],
                'amount' => (float) $row['amount'],
                // Split so the phone layout can drop the year: on a
                // "recent activity" list it is noise, and it was the last
                // 34px standing between "TRANSFER OUT · Aug 25, 2026" and
                // fitting on one line at 360px. dashboard.php is this
                // endpoint's only consumer.
                'date' => date('M j', strtotime((string) $row['created_at'])),
                'year' => date(', Y', strtotime((string) $row['created_at'])),
            ];
        }
    }

    $isFrozen = false;
    if ($wallet['id'] > 0 && $wallet['source'] === 'student_wallets') {
        $fzStmt = $db->prepare("SELECT is_frozen FROM student_wallets WHERE id = ?");
        $fzStmt->execute([$wallet['id']]);
        $isFrozen = (int) $fzStmt->fetchColumn() === 1;
    }

    echo json_encode([
        'success' => true,
        'balance' => $balance,
        'gencoin' => round($balance / GJC_PESOS_PER_GC, 1),
        'total_spent' => $totalSpent,
        'total_txns' => $totalTxns,
        'account_status' => $isFrozen ? 'Frozen' : 'Active',
        'transactions' => $transactions,
    ]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'student/api/wallet_summary', 'Server error. Please try again.')]);
}
