<?php
// A parent's link request must not grant access until the student approves it.
// Runs the real endpoints against the dev DB on an unlinked parent/student pair, then cleans up.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../connection/config.php';
require __DIR__ . '/../connection/pdo.php';
require __DIR__ . '/../connection/app.php';
gjc_ensure_parent_schema($db);

$pair = $db->query(
    "SELECT p.id AS parent_id, pu.userID AS parent_uid, pu.roleID AS parent_role, pu.password AS parent_pw,
            su.userID AS student_uid, su.roleID AS student_role, su.password AS student_pw, si.studentID
       FROM parents p
       JOIN users pu ON pu.userID = p.user_id AND pu.roleID = 7
       JOIN users su ON su.roleID = 1
       JOIN student_info si ON si.userID = su.userID AND si.studentID <> ''
      WHERE NOT EXISTS (SELECT 1 FROM parent_student_links l WHERE l.parent_id = p.id AND l.student_user_id = su.userID)
        AND NOT EXISTS (SELECT 1 FROM parent_link_requests r WHERE r.parent_id = p.id AND r.student_user_id = su.userID)
      LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$pair) { echo "SKIP: no unlinked parent/student pair in this database.\n"; exit; }

/** Run one endpoint in its own process as the given user (endpoints exit and send headers). */
function call(string $endpoint, array $user, array $post): array
{
    $boot = sprintf(
        '<?php session_start(); $_SESSION["gjc_csrf_token"] = "t"; $_POST = %s; $u = %s;'
        . 'require %s; gjc_establish_login_session($u); require %s;',
        var_export($post + ['csrf_token' => 't'], true), var_export($user, true),
        var_export(__DIR__ . '/../connection/app.php', true), var_export(__DIR__ . '/../' . $endpoint, true)
    );
    // A temp file, not -r: Windows escapeshellarg() strips the double quotes out of inline code.
    $file = tempnam(sys_get_temp_dir(), 'gjc');
    file_put_contents($file, $boot);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($file));
    unlink($file);
    return json_decode((string) $out, true) ?? ['success' => false, 'error' => 'bad output: ' . $out];
}
$count = function (string $table) use ($db, $pair): int {
    $s = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE parent_id = ? AND student_user_id = ?");
    $s->execute([$pair['parent_id'], $pair['student_uid']]);
    return (int) $s->fetchColumn();
};
$check = function (bool $ok, string $what): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $what);
};
$parent  = ['userID' => $pair['parent_uid'], 'roleID' => $pair['parent_role'], 'password' => $pair['parent_pw']];
$student = ['userID' => $pair['student_uid'], 'roleID' => $pair['student_role'], 'password' => $pair['student_pw']];
$request = ['action' => 'link_student', 'school_id' => $pair['studentID']];
$reqId = function () use ($db, $pair): int {
    $s = $db->prepare("SELECT id FROM parent_link_requests WHERE parent_id = ? AND student_user_id = ?");
    $s->execute([$pair['parent_id'], $pair['student_uid']]);
    return (int) $s->fetchColumn();
};

try {
    $r = call('parent/api/link.php', $parent, $request);
    $check($r['success'] === true, 'request should be accepted: ' . ($r['error'] ?? ''));
    $check($count('parent_student_links') === 0, 'a request alone must not create the link');
    $check($count('parent_link_requests') === 1, 'request row should exist');
    $check(call('parent/api/link.php', $parent, $request)['success'] === false, 'duplicate request should be refused');

    // Another student cannot answer this request.
    $other = ['userID' => 0, 'roleID' => 1, 'password' => ''];
    $check(call('student/api/guardian_link.php', $other, ['action' => 'approve', 'request_id' => $reqId()])['success'] === false, 'stranger must not approve');
    $check($count('parent_student_links') === 0, 'stranger approval must not link');

    $check(call('student/api/guardian_link.php', $student, ['action' => 'decline', 'request_id' => $reqId()])['success'] === true, 'decline should work');
    $check($count('parent_link_requests') + $count('parent_student_links') === 0, 'decline must leave nothing behind');

    call('parent/api/link.php', $parent, $request);
    $r = call('student/api/guardian_link.php', $student, ['action' => 'approve', 'request_id' => $reqId()]);
    $check($r['success'] === true, 'approve should work: ' . ($r['error'] ?? ''));
    $check($count('parent_student_links') === 1 && $count('parent_link_requests') === 0, 'approve must link and clear the request');
    echo "PASS: a parent link needs the student's approval.\n";
} finally {
    foreach (['parent_student_links', 'parent_link_requests'] as $t) {
        $db->prepare("DELETE FROM {$t} WHERE parent_id = ? AND student_user_id = ?")->execute([$pair['parent_id'], $pair['student_uid']]);
    }
    $db->prepare("DELETE FROM notifications WHERE user_id = ? AND type = 'guardian_link' AND created_at >= NOW() - INTERVAL 5 MINUTE")
       ->execute([$pair['student_uid']]);
}
