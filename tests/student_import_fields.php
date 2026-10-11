<?php
// CLI regression; all test account/address writes are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/student_import_schema.php';

// Load page helpers without authentication, schema changes, or rendering the page.
$pageSource = file_get_contents(__DIR__ . '/../admin/maintenance.php');
$tokens = token_get_all($pageSource);
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
    $source = ''; $depth = 0; $opened = false;
    do {
        $token = $tokens[$i++];
        $source .= is_array($token) ? $token[1] : $token;
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $opened = true; }
        if ($token === '}') { $depth--; }
    } while (!$opened || $depth > 0);
    $i--;
    eval($source);
}
function import_expect(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$cols = maintenance_student_csv_columns();
$header = array_merge($cols['required'], $cols['optional']);
$row = array_fill_keys($header, '');
$tag = bin2hex(random_bytes(6));
$row = array_replace($row, [
    'student_id_number' => 'IMPORT-TEST-' . $tag, 'first_name' => 'Import',
    'middle_name' => 'Middle', 'last_name' => 'Regression', 'suffix' => 'III',
    'course_program' => 'BSIT', 'email' => 'IMPORT-' . $tag . '@example.com',
    'phone_number' => '+63 917 123 4567', 'year_level' => '2nd Year',
    'sex' => 'female', 'date_of_birth' => '2006-03-04',
    'address_barangay' => 'Import Test ' . $tag, 'address_city' => 'Cabiao',
    'address_province' => 'Nueva Ecija',
]);
$path = tempnam(sys_get_temp_dir(), 'import-test-');
try {
    $out = fopen($path, 'w');
    fwrite($out, maintenance_csv_template_marker('students', $header) . "\n");
    fputcsv($out, $header); fputcsv($out, array_values($row)); fclose($out);
    $parsed = maintenance_parse_student_csv($path);
    $report = maintenance_validate_student_rows($db, $parsed);
    import_expect($report['counts']['ready'] === 1, 'Valid new CSV must be ready.');
    $valid = $report['rows'][0]['data'];
    import_expect($valid['year_level'] === '2' && $valid['sex'] === 'Female', 'Values must be canonical.');
    foreach ([['date_of_birth', '2006-02-30'], ['date_of_birth', '2999-01-01'],
        ['year_level', 'second'], ['sex', 'invalid'], ['middle_name', str_repeat('x', 101)],
        ['phone_number', 'abc123'], ['address_city', str_repeat('x', 121)]] as [$key, $value]) {
        $invalid = array_replace($row, [$key => $value, 'row_number' => 2]);
        import_expect(maintenance_validate_student_rows($db, [$invalid])['has_errors'], 'Must reject invalid ' . $key);
    }
    $blank = gjc_normalize_student_import_row(array_replace($row, ['year_level' => '', 'sex' => '', 'date_of_birth' => '']));
    import_expect($blank['year_level'] === '1', 'Blank year must preserve default.');
    $out = fopen($path, 'a'); fputcsv($out, ['too', 'few']); fclose($out);
    $rejected = false;
    try { maintenance_parse_student_csv($path); } catch (RuntimeException $e) { $rejected = true; }
    import_expect($rejected, 'Malformed row must be rejected.');

    $db->beginTransaction();
    $id = maintenance_insert_student_user($db, $valid);
    $read = $db->prepare('SELECT middle_name, suffix, sex, date_of_birth FROM users WHERE userID = ?');
    $read->execute([$id]);
    import_expect($read->fetch() === ['middle_name' => 'Middle', 'suffix' => 'III', 'sex' => 'Female', 'date_of_birth' => '2006-03-04'], 'Actual users fields must retain import values.');
    $address = gjc_user_address_values($db, $id);
    import_expect($address['address_barangay'] === $valid['address_barangay'] && $address['address_city'] === 'Cabiao', 'Normalized address must retain import values.');

    // Execute the same enrollment/history statements as the page's import action.
    $start = strpos($pageSource, '$studentInfoInsert = $db->prepare(');
    $end = strpos($pageSource, '$linkInsert =', $start);
    eval(substr($pageSource, $start, $end - $start));
    $row = $valid; $userId = $id; $studentId = $valid['student_id_number'];
    $courseId = (int) $db->query('SELECT courseID FROM course LIMIT 1')->fetchColumn();
    preg_match('/\$studentInfoInsert->execute\([^;]+;/', $pageSource, $enrollment);
    eval($enrollment[0]);
    $read = $db->prepare('SELECT yr_lvl FROM student_info WHERE userID = ?');
    $read->execute([$id]);
    import_expect($read->fetchColumn() === '2', 'Actual enrollment must save selected year.');
    $batchId = date('YmdHis'); $parentUserId = null; $parentStatus = 'none';
    $registryStatus = 'imported'; $message = 'Regression test'; $currentUser = ['id' => $id];
    $start = strpos($pageSource, '$historyValues = array_map(');
    $end = strpos($pageSource, '], $historyValues));', $start) + strlen('], $historyValues));');
    eval(substr($pageSource, $start, $end - $start));
    $read = $db->prepare('SELECT middle_name, suffix, year_level, sex, date_of_birth, address_city FROM imported_student_registry WHERE user_id = ?');
    $read->execute([$id]);
    import_expect($read->fetch() === ['middle_name' => 'Middle', 'suffix' => 'III', 'year_level' => '2', 'sex' => 'Female', 'date_of_birth' => '2006-03-04', 'address_city' => 'Cabiao'], 'Import history must retain all added values.');
    $db->rollBack();
    echo "PASS: signed CSV, normalization, validation, user fields, enrollment, import history and normalized address (writes rolled back).\n";
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
    unlink($path);
}
