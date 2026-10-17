<?php
// Verify that optional failures after commit cannot turn a completed payment into failure.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('STUDENT_URL', '/student');
define('MERCHANT_URL', '/merchant');
function gjc_check_parent_balance_alert($db, $walletId) {
    if ($GLOBALS['failBalanceAlert']) throw new RuntimeException('Balance alert unavailable');
}
function logAudit(...$args) {}
function gjc_current_role() { return 'student'; }
function gjc_money_plain($amount) { return (string) $amount; }
function gjc_user_label($db, $id) { return 'User'; }
function gjc_notify(...$args) {
    if ($GLOBALS['failNotification']) throw new RuntimeException('Notification unavailable');
}
$db = new class {
    public int $commits = 0;
    public function commit() { $this->commits++; }
};
$source = file_get_contents(__DIR__ . '/../student/api/checkout.php');
$start = strpos($source, '        $db->commit();');
$end = strpos($source, "\n    } catch (", $start);
if ($start === false || $end === false) throw new RuntimeException('Checkout response block missing');
$block = substr($source, $start, $end - $start);
$studentWallet = ['id' => 1];
$studentUserId = 1;
$order = ['merchant_user_id' => 2, 'merchant_wallet_id' => 2];
$refNo = 'CART-PAID'; $total = 25; $lines = [];
ini_set('error_log', 'NUL');
foreach ([[false, false], [true, false], [false, true], [true, true]] as [$failBalanceAlert, $failNotification]) {
    ob_start();
    eval($block);
    $response = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    if ($response['success'] !== true || $response['reference'] !== $refNo || $response['total'] !== $total) {
        throw new RuntimeException('Completed payment must stay successful when alerts fail');
    }
}
if ($db->commits !== 4) throw new RuntimeException('Each payment must commit once');
echo "PASS: committed student payments stay successful when balance alerts or notifications fail.\n";
