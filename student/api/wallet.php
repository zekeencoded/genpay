<?php
// ============================================================
//  student/api/wallet.php
//  Lightweight wallet stats used to AJAX-refresh the student
//  Dashboard and History pages without a full page reload.
// ============================================================
session_start();
require_once __DIR__ . '/../../connection/config.php';
require_once __DIR__ . '/../../connection/pdo.php';
require_once __DIR__ . '/../../connection/app.php';

header('Content-Type: application/json');
gjc_require_role(['student']);

$action = trim((string) ($_POST['action'] ?? ($_GET['action'] ?? '')));

try {
    if ($action !== 'get_wallet_stats') {
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit;
    }

    $currentUser = gjc_current_user($db);
    $wallet = gjc_student_wallet($db, (int) $currentUser['id']);

    $balance = (float) $wallet['balance'];
    $totalSpent = 0.0;
    $totalReceived = 0.0;
    $totalTxns = 0;

    if ($wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM transactions WHERE student_wallet_id = ?"
        );
        $countStmt->execute([$wallet['id']]);
        $totalTxns = (int) $countStmt->fetchColumn();

        $spentStmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
              WHERE student_wallet_id = ? AND transaction_type = 'payment'"
        );
        $spentStmt->execute([$wallet['id']]);
        $totalSpent = (float) $spentStmt->fetchColumn();

        $receivedStmt = $db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
              WHERE student_wallet_id = ? AND transaction_type IN ('cash_in', 'topup', 'refund')"
        );
        $receivedStmt->execute([$wallet['id']]);
        $totalReceived = (float) $receivedStmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'balance' => $balance,
        'total_spent' => $totalSpent,
        'total_received' => $totalReceived,
        'total_txns' => $totalTxns,
    ]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => gjc_safe_error($e, 'student/api/wallet', 'Server error. Please try again.')]);
}
