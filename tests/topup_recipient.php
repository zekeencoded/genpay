<?php
// One ID box: GRD{year}-#### resolves to a guardian, Student/Employee IDs to their wallet. Read-only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

$fail = static function (string $why): never { fwrite(STDERR, "FAIL: {$why}\n"); exit(1); };
$throws = static function (callable $fn): bool { try { $fn(); return false; } catch (Throwable) { return true; } };

if (gjc_guardian_id(12, '2025-06-01 08:00:00') !== 'GRD2025-0012') $fail('guardian id format');

$g = $db->query("SELECT p.id, COALESCE(u.created_at, p.created_at) AS created_at
                   FROM parents p JOIN users u ON u.userID = p.user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($g) {
    $parentId = (int) $g['id'];
    $gid = gjc_guardian_id($parentId, $g['created_at']);
    foreach ([$gid, strtolower($gid), str_replace('-', '', $gid)] as $typed) {
        $r = gjc_find_topup_recipient($db, $typed);
        if ($r['kind'] !== 'parent' || $r['parent_id'] !== $parentId || $r['id'] !== $gid) $fail("guardian not found by {$typed}");
    }
    // Right number, wrong year: rejected rather than matched.
    $wrongYear = 'GRD' . ((int) substr($gid, 3, 4) - 1) . substr($gid, 7);
    if (!$throws(fn() => gjc_find_topup_recipient($db, $wrongYear))) $fail('wrong year must fail');
}

$row = $db->query("SELECT si.studentID, u.roleID FROM student_info si JOIN users u ON u.userID = si.userID
                    JOIN student_wallets sw ON sw.user_id = u.userID
                   WHERE u.roleID IN (1, " . GJC_FACULTY_ROLE_ID . ") LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $r = gjc_find_topup_recipient($db, $row['studentID']);
    if ($r['kind'] !== 'student' || empty($r['wallet_id'])) $fail('student/faculty not found by ID');
}
$fac = $db->query("SELECT si.studentID FROM student_info si JOIN users u ON u.userID = si.userID
                    WHERE u.roleID = " . GJC_FACULTY_ROLE_ID . " LIMIT 1")->fetchColumn();
if ($fac && gjc_find_topup_recipient($db, $fac)['label'] !== 'Faculty') $fail('faculty label');

foreach (['GRD2026-0001', 'grd1', ' GRD9'] as $bad) if (!gjc_id_reserved_for_guardian($bad)) $fail("{$bad} must be reserved");
foreach (['GJC2026-0001', 'EMP2026-0001', 'GRADE-1'] as $ok) if (gjc_id_reserved_for_guardian($ok)) $fail("{$ok} must be allowed");

if (!$throws(fn() => gjc_find_topup_recipient($db, ''))) $fail('empty id must fail');
if (!$throws(fn() => gjc_find_topup_recipient($db, 'GRD2026-999999999'))) $fail('unknown guardian must fail');
if (!$throws(fn() => gjc_find_topup_recipient($db, 'NO-SUCH-ID-' . bin2hex(random_bytes(3))))) $fail('unknown id must fail');

echo "PASS: guardian, student and faculty IDs resolve from one box; unknown IDs are rejected.\n";
