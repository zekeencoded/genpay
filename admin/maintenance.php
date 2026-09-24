<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';
require_once __DIR__ . '/../connection/mailer.php';

gjc_require_role(['finance']);
gjc_ensure_audit_table($db);
gjc_ensure_fee_waiver_credits_schema($db);
gjc_backfill_fee_waiver_credits($db);

$currentUser = gjc_current_user($db);
$currentPage = 'maintenance';

/**
 * A plain header row is trivial to retype by hand — anyone can read the
 * required column names off the error message and fake a "template". This
 * signs the exact column list with the app's existing view-token secret
 * (GJC_VIEW_TOKEN_SECRET, connection/app.php) so a file only passes if it
 * carries the real, unforgeable marker the server itself generated. The
 * signature is tied to the column list too, so if the template ever changes
 * shape, old/faked files stop validating automatically.
 */
function maintenance_csv_template_marker(string $kind, array $columns): string
{
    $sig = substr(hash_hmac('sha256', 'csv_template:' . $kind . ':' . implode(',', $columns), GJC_VIEW_TOKEN_SECRET), 0, 32);
    return '#genpay-template:' . $kind . ':' . $sig;
}

/** Streams a starter CSV (signed marker + header + one example row) and exits.
 *  Must run before any other output — called immediately below, before any
 *  HTML rendering. */
function maintenance_stream_csv_template(string $filename, string $kind, array $header, array $exampleRow): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, maintenance_csv_template_marker($kind, $header) . "\n");
    fputcsv($out, $header);
    fputcsv($out, $exampleRow);
    fclose($out);
    exit;
}

// Header order here MUST match maintenance_student_csv_columns() /
// maintenance_fee_waiver_csv_columns() exactly — the parser only accepts a
// file whose header is byte-for-byte that column list, in that order, so a
// template generated any other way would reject itself on re-upload.
if (($_GET['download_template'] ?? '') === 'students') {
    $cols = maintenance_student_csv_columns();
    maintenance_stream_csv_template(
        'bulk_student_import_template.csv',
        'students',
        array_merge($cols['required'], $cols['optional']),
        ['GJC2026-0001', 'Juan', 'Dela Cruz', 'BS Information Technology', 'juan.delacruz@example.com', '09171234567', '', 'Maria Dela Cruz', 'maria.delacruz@example.com', '09179876543']
    );
}
if (($_GET['download_template'] ?? '') === 'fee_waiver') {
    $cols = maintenance_fee_waiver_csv_columns();
    maintenance_stream_csv_template(
        'fee_waiver_import_template.csv',
        'fee_waiver',
        array_merge($cols['required'], $cols['optional']),
        ['GJC2026-0001', '500.00']
    );
}

function maintenance_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function maintenance_ensure_student_registry(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS imported_student_registry (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            import_batch_id VARCHAR(14) NOT NULL,
            user_id INT NULL,
            student_id_number VARCHAR(80) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            course_program VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone_number VARCHAR(40) NULL,
            parent_name VARCHAR(255) NULL,
            parent_email VARCHAR(255) NULL,
            parent_contact VARCHAR(40) NULL,
            parent_user_id INT NULL,
            parent_status VARCHAR(20) NOT NULL DEFAULT 'none',
            import_status ENUM('imported', 'duplicate', 'failed') NOT NULL,
            message VARCHAR(255) NULL,
            imported_by INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_import_batch (import_batch_id),
            INDEX idx_import_student (student_id_number),
            INDEX idx_import_email (email),
            INDEX idx_import_status (import_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    // Bring pre-parent installs up to date. A fresh read avoids the stale
    // static cache in gjc_table_columns() when this runs twice per request.
    $columns = maintenance_table_columns_fresh($db, 'imported_student_registry');
    $adds = [
        'parent_name'    => "VARCHAR(255) NULL AFTER phone_number",
        'parent_email'   => "VARCHAR(255) NULL AFTER parent_name",
        'parent_contact' => "VARCHAR(40) NULL AFTER parent_email",
        'parent_user_id' => "INT NULL AFTER parent_contact",
        'parent_status'  => "VARCHAR(20) NOT NULL DEFAULT 'none' AFTER parent_user_id",
    ];
    foreach ($adds as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            try {
                $db->exec("ALTER TABLE imported_student_registry ADD COLUMN {$column} {$definition}");
            } catch (\Throwable $ignored) {
            }
        }
    }
}

/** Required student columns and the optional ones (suffix + guardian details). */
function maintenance_student_csv_columns(): array
{
    return [
        'required' => ['student_id_number', 'first_name', 'last_name', 'course_program', 'email', 'phone_number'],
        'optional' => ['suffix', 'parent_name', 'parent_email', 'parent_contact'],
    ];
}

/**
 * Reads and verifies the signed marker line (maintenance_csv_template_marker())
 * that a real "Download Template" file starts with — consumes exactly one
 * line from $handle. A hand-typed CSV, even one that copies the exact column
 * names from an error message, will not have this line, because it can't be
 * forged without GJC_VIEW_TOKEN_SECRET.
 */
function maintenance_assert_template_marker($handle, string $kind, array $columns): void
{
    $firstLine = fgets($handle);
    if ($firstLine === false || trim((string) $firstLine) === '') {
        throw new RuntimeException('The CSV file is empty.');
    }
    $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', (string) $firstLine);
    if (trim((string) $firstLine) !== maintenance_csv_template_marker($kind, $columns)) {
        throw new RuntimeException(
            'This file wasn\'t generated by the "Download Template" button (or its first line was edited or removed). '
            . 'Click Download Template, then enter your data into that file without changing its structure.'
        );
    }
}

/**
 * Only the downloaded template is accepted: the header row must match the
 * template's columns exactly, in the template's exact order — reordering,
 * renaming, adding, or dropping a column (even an optional one) is refused.
 * This is stricter than "every column is recognised" (the old behaviour,
 * which tolerated any column order and let optional columns be omitted).
 * Returns the cleaned header for convenience.
 */
function maintenance_assert_exact_template_header(array $header, array $canonicalOrder): array
{
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    }
    // Trailing blank cells (Excel sometimes exports a stray trailing comma)
    // don't count as a structural mismatch.
    while ($header && trim((string) end($header)) === '') {
        array_pop($header);
    }
    $cleaned = array_map(static fn($name) => strtolower(trim((string) $name)), $header);

    if ($cleaned !== $canonicalOrder) {
        throw new RuntimeException(
            'This file doesn\'t match the required template. Use "Download Template" to get the correct CSV '
            . '(columns must be exactly: ' . implode(', ', $canonicalOrder) . ', in that order) and re-enter your data into it.'
        );
    }

    return $cleaned;
}

function maintenance_parse_student_csv(string $path): array
{
    $cols     = maintenance_student_csv_columns();
    $required = $cols['required'];
    $known    = array_merge($required, $cols['optional']);

    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('Unable to read the uploaded CSV file.');
    }

    try {
        maintenance_assert_template_marker($handle, 'students', $known);

        $header = fgetcsv($handle);
        if (!$header) {
            throw new RuntimeException('The CSV file is empty.');
        }
        maintenance_assert_exact_template_header($header, $known);
    } catch (RuntimeException $e) {
        fclose($handle);
        throw $e;
    }

    // Header is now guaranteed to be $known in that exact order.
    $index = array_flip($known);

    $rows = [];
    $rowNumber = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $rowNumber++;
        if (count(array_filter($data, static fn($value) => trim((string) $value) !== '')) === 0) {
            continue;
        }

        // Pull each known column by its header position; absent optional columns
        // default to '' so downstream code can rely on every key existing.
        $row = [];
        foreach ($known as $col) {
            $pos = $index[$col] ?? null;
            $row[$col] = $pos === null ? '' : trim((string) ($data[$pos] ?? ''));
        }
        $row['row_number'] = $rowNumber;
        $rows[] = $row;
    }

    fclose($handle);

    if (!$rows) {
        throw new RuntimeException('No student rows were found in the CSV file.');
    }

    return $rows;
}

/** Splits a single "Full Name" into first/last, falling back to the email local part. */
function maintenance_split_name(string $fullName, string $fallbackEmail = ''): array
{
    $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');
    if ($fullName === '') {
        $local = trim((string) strstr($fallbackEmail, '@', true));
        return ['first' => $local !== '' ? $local : 'Guardian', 'last' => ''];
    }
    $pos = strpos($fullName, ' ');
    if ($pos === false) {
        return ['first' => $fullName, 'last' => ''];
    }
    return [
        'first' => substr($fullName, 0, $pos),
        'last'  => substr($fullName, $pos + 1),
    ];
}

/**
 * Validates every parsed row up-front so the whole batch can be judged before a
 * single account is written. Each row becomes ready / duplicate / error, with
 * in-file duplicates caught alongside ones already in the database, and the
 * intended guardian action worked out. This is what makes the preview an
 * all-or-nothing gate rather than a row-by-row commit.
 */
function maintenance_validate_student_rows(PDO $db, array $rows): array
{
    $emailCheck   = $db->prepare("SELECT userID FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $studentCheck = $db->prepare("SELECT userID FROM student_info WHERE studentID = ? LIMIT 1");

    $seenStudentIds = [];
    $seenEmails     = [];

    $analysis = [];
    $counts = ['total' => 0, 'ready' => 0, 'duplicate' => 0, 'error' => 0, 'with_parent' => 0];

    foreach ($rows as $row) {
        $counts['total']++;
        $studentId = (string) $row['student_id_number'];
        $email     = strtolower((string) $row['email']);
        $status    = 'ready';
        $message   = 'Ready to import.';

        // ── Required fields, then email shape ──────────────────────────────
        $missing = [];
        foreach ([
            'student_id_number' => 'Student ID',
            'first_name'        => 'First name',
            'last_name'         => 'Last name',
            'course_program'    => 'Course',
            'email'             => 'Email',
        ] as $field => $label) {
            if (trim((string) $row[$field]) === '') {
                $missing[] = $label;
            }
        }
        if ($missing) {
            $status  = 'error';
            $message = 'Missing required field(s): ' . implode(', ', $missing) . '.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $status  = 'error';
            $message = 'Invalid student email address.';
        }

        // ── Duplicate detection: in-file first, then the database ──────────
        if ($status === 'ready') {
            if (isset($seenStudentIds[$studentId])) {
                $status  = 'duplicate';
                $message = 'Duplicate student ID earlier in this file.';
            } elseif (isset($seenEmails[$email])) {
                $status  = 'duplicate';
                $message = 'Duplicate email earlier in this file.';
            } else {
                $studentCheck->execute([$studentId]);
                if ($studentCheck->fetchColumn()) {
                    $status  = 'duplicate';
                    $message = 'Student ID already exists.';
                } else {
                    $emailCheck->execute([$email]);
                    if ($emailCheck->fetchColumn()) {
                        $status  = 'duplicate';
                        $message = 'Email already exists.';
                    }
                }
            }
        }

        // Remember identifiers so later rows in the same file collide with them.
        if ($studentId !== '') {
            $seenStudentIds[$studentId] = true;
        }
        if ($email !== '') {
            $seenEmails[$email] = true;
        }

        // ── Guardian (optional) ────────────────────────────────────────────
        $parentEmail = strtolower(trim((string) $row['parent_email']));
        $parentName  = trim((string) $row['parent_name']);
        $parentPhone = trim((string) $row['parent_contact']);
        $parentAction  = 'none';
        $parentMessage = '';

        if ($parentEmail !== '' || $parentName !== '' || $parentPhone !== '') {
            if ($parentEmail === '') {
                $parentAction  = 'skip';
                $parentMessage = 'Guardian skipped — parent_email is required to create a guardian account.';
            } elseif (!filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
                $parentAction  = 'skip';
                $parentMessage = 'Guardian skipped — invalid parent_email.';
            } elseif ($parentEmail === $email) {
                $parentAction  = 'skip';
                $parentMessage = 'Guardian skipped — parent_email matches the student email.';
            } else {
                $parentAction  = 'link';
                $parentMessage = 'Guardian account will be created/linked.';
                $counts['with_parent']++;
            }
        }

        $counts[$status]++;
        $analysis[] = [
            'row_number'     => (int) $row['row_number'],
            'data'           => $row,
            'status'         => $status,
            'message'        => $message,
            'parent_action'  => $parentAction,
            'parent_message' => $parentMessage,
        ];
    }

    return [
        'rows'       => $analysis,
        'counts'     => $counts,
        'has_errors' => $counts['error'] > 0,
    ];
}

/** The fee_waiver_credits.id for an existing student — the row always exists by
 *  this point (gjc_backfill_fee_waiver_credits() runs on every page load). */
function maintenance_fee_waiver_credit_id(PDO $db, int $studentUserId): int
{
    $stmt = $db->prepare("SELECT id FROM fee_waiver_credits WHERE student_user_id = ? LIMIT 1");
    $stmt->execute([$studentUserId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * ============================================================
 *  Fee Waiver Import — separate from Bulk Student Import above.
 *  Matches EXISTING students by student_id_number and mints their
 *  Fee Waiver Credit straight into GenCoin (empty -> claimed, no
 *  waiver upload) via gjc_import_fee_waiver_credit(). Does not
 *  create or touch any account/user data.
 * ============================================================
 */
function maintenance_fee_waiver_csv_columns(): array
{
    return [
        'required' => ['student_id_number', 'additional_fee'],
        'optional' => [],
    ];
}

function maintenance_parse_fee_waiver_csv(string $path): array
{
    $cols     = maintenance_fee_waiver_csv_columns();
    $required = $cols['required'];
    $known    = array_merge($required, $cols['optional']);

    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException('Unable to read the uploaded CSV file.');
    }

    try {
        maintenance_assert_template_marker($handle, 'fee_waiver', $known);

        $header = fgetcsv($handle);
        if (!$header) {
            throw new RuntimeException('The CSV file is empty.');
        }
        maintenance_assert_exact_template_header($header, $known);
    } catch (RuntimeException $e) {
        fclose($handle);
        throw $e;
    }

    // Header is now guaranteed to be $known in that exact order.
    $index = array_flip($known);

    $rows = [];
    $rowNumber = 1;
    while (($data = fgetcsv($handle)) !== false) {
        $rowNumber++;
        if (count(array_filter($data, static fn($value) => trim((string) $value) !== '')) === 0) {
            continue;
        }
        $row = [];
        foreach ($known as $col) {
            $pos = $index[$col] ?? null;
            $row[$col] = $pos === null ? '' : trim((string) ($data[$pos] ?? ''));
        }
        $row['row_number'] = $rowNumber;
        $rows[] = $row;
    }

    fclose($handle);

    if (!$rows) {
        throw new RuntimeException('No rows were found in the CSV file.');
    }

    return $rows;
}

/**
 * Validates every parsed row against the CURRENT database: the student ID
 * must already exist, the amount must be sane, and — the key difference
 * from the student importer — the student's Fee Waiver Credit must
 * currently be 'empty' (never entered before) since this path mints
 * straight from empty -> claimed with no in-between review step.
 */
function maintenance_validate_fee_waiver_rows(PDO $db, array $rows): array
{
    $studentCheck = $db->prepare(
        "SELECT u.userID, u.first_name, u.last_name, fwc.status
           FROM student_info si
           JOIN users u ON u.userID = si.userID AND u.roleID = 1
           LEFT JOIN fee_waiver_credits fwc ON fwc.student_user_id = u.userID
          WHERE si.studentID = ? LIMIT 1"
    );
    $feeMaxAmount = (float) gjc_setting($db, 'fee_waiver_max_amount');

    $seenStudentIds = [];
    $analysis = [];
    $counts = ['total' => 0, 'ready' => 0, 'skip' => 0, 'error' => 0];

    foreach ($rows as $row) {
        $counts['total']++;
        $studentId = (string) $row['student_id_number'];
        $amountRaw = trim((string) $row['additional_fee']);
        $status  = 'ready';
        $message = 'Ready to credit.';
        $studentUserId = null;
        $studentName = '';
        $amount = null;

        if ($studentId === '') {
            $status  = 'error';
            $message = 'Missing Student ID.';
        } elseif (!is_numeric($amountRaw) || (float) $amountRaw <= 0) {
            $status  = 'error';
            $message = 'Additional Fee must be a positive number.';
        } elseif ((float) $amountRaw > $feeMaxAmount) {
            $status  = 'error';
            $message = 'Additional Fee exceeds the ₱' . number_format($feeMaxAmount, 2) . ' limit.';
        } elseif (isset($seenStudentIds[$studentId])) {
            $status  = 'skip';
            $message = 'Duplicate Student ID earlier in this file.';
        } else {
            $studentCheck->execute([$studentId]);
            $match = $studentCheck->fetch(PDO::FETCH_ASSOC);
            if (!$match) {
                $status  = 'error';
                $message = 'Student ID not found.';
            } else {
                $studentUserId = (int) $match['userID'];
                $studentName   = trim($match['first_name'] . ' ' . $match['last_name']);
                $currentStatus = (string) ($match['status'] ?? 'empty');
                if ($currentStatus !== 'empty') {
                    $status  = 'skip';
                    $message = 'Skipped — this student\'s Fee Waiver Credit is already ' . $currentStatus . '.';
                } else {
                    $amount = round((float) $amountRaw, 2);
                }
            }
        }

        if ($studentId !== '') {
            $seenStudentIds[$studentId] = true;
        }

        $counts[$status]++;
        $analysis[] = [
            'row_number'      => (int) $row['row_number'],
            'data'            => $row,
            'status'          => $status,
            'message'         => $message,
            'student_user_id' => $studentUserId,
            'student_name'    => $studentName,
            'amount'          => $amount,
        ];
    }

    return [
        'rows'       => $analysis,
        'counts'     => $counts,
        'has_errors' => $counts['error'] > 0,
    ];
}

/**
 * Creates or reuses a guardian (parent) login account keyed by email and makes
 * sure a row exists in `parents`, returning what the caller needs to link it to
 * a student. New guardians get a random temp password and must change it on
 * first login, mirroring how imported students are provisioned. An email already
 * in use by a non-guardian account is reported back as a conflict rather than
 * being altered. $cache (keyed by lowercased email) means the same guardian
 * across several student rows is provisioned once and merely linked to each.
 */
function maintenance_provision_parent(PDO $db, array $parent, array &$cache): array
{
    $email = strtolower(trim((string) $parent['email']));
    if (isset($cache[$email])) {
        return $cache[$email];
    }

    $lookup = $db->prepare("SELECT userID, roleID FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
    $lookup->execute([$email]);
    $existing = $lookup->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ((int) $existing['roleID'] !== 7) {
            return $cache[$email] = [
                'ok'     => false,
                'reason' => 'parent_email already belongs to a non-guardian account.',
            ];
        }
        $parentUserId = (int) $existing['userID'];
        $tempPassword = null;
        $reused       = true;
    } else {
        $names        = maintenance_split_name((string) $parent['name'], $email);
        $tempPassword = maintenance_temp_password();
        $columns      = maintenance_table_columns_fresh($db, 'users');
        $payload = [
            'last_name'             => $names['last'],
            'first_name'            => $names['first'],
            'name'                  => trim($names['first'] . ' ' . $names['last']),
            'middle_name'           => '',
            'suffix'                => '',
            'contact_number'        => maintenance_phone_digits((string) $parent['contact']),
            'phone'                 => (string) $parent['contact'],
            'email'                 => $email,
            'roleID'                => 7,
            'sub_role'              => 'parent',
            'password'              => password_hash($tempPassword, PASSWORD_DEFAULT),
            'profile_img'           => '',
            'force_password_change' => 1,
            'is_first_login'        => 1,
            'password_changed'      => 0,
            'temp_password'         => $tempPassword,
        ];

        $insert = [];
        $values = [];
        foreach ($payload as $column => $value) {
            if (in_array($column, $columns, true)) {
                $insert[] = $column;
                $values[] = $value;
            }
        }
        $sql = 'INSERT INTO users (' . implode(', ', $insert) . ') VALUES (' . implode(', ', array_fill(0, count($insert), '?')) . ')';
        $db->prepare($sql)->execute($values);
        $parentUserId = (int) $db->lastInsertId();
        $reused       = false;
    }

    // Ensure the parents row exists and read back its id (parent_student_links.parent_id).
    $db->prepare("INSERT IGNORE INTO parents (user_id) VALUES (?)")->execute([$parentUserId]);
    $pidStmt = $db->prepare("SELECT id FROM parents WHERE user_id = ? LIMIT 1");
    $pidStmt->execute([$parentUserId]);
    $parentId = (int) $pidStmt->fetchColumn();

    return $cache[$email] = [
        'ok'             => true,
        'parent_id'      => $parentId,
        'parent_user_id' => $parentUserId,
        'temp_password'  => $tempPassword,
        'reused'         => $reused,
    ];
}

function maintenance_phone_digits(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?: '0';
    return substr($digits, 0, 18);
}

function maintenance_course_id(PDO $db, string $courseProgram): int
{
    $courseProgram = trim($courseProgram);
    $stmt = $db->prepare(
        "SELECT courseID
           FROM course
          WHERE UPPER(course_code) = UPPER(?)
             OR LOWER(course_name) = LOWER(?)
          LIMIT 1"
    );
    $stmt->execute([$courseProgram, $courseProgram]);
    $courseId = (int) $stmt->fetchColumn();
    if ($courseId > 0) {
        return $courseId;
    }

    $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9-]+/', '', $courseProgram) ?: 'COURSE', 0, 255));
    $db->prepare("INSERT INTO course (course_code, course_name) VALUES (?, ?)")
        ->execute([$code, substr($courseProgram, 0, 255)]);

    return (int) $db->lastInsertId();
}

function maintenance_insert_student_user(PDO $db, array $row): int
{
    $columns = gjc_table_columns($db, 'users');
    $studentId = (string) $row['student_id_number'];
    $payload = [
        'last_name' => $row['last_name'],
        'first_name' => $row['first_name'],
        'middle_name' => '',
        'suffix' => (string) ($row['suffix'] ?? ''),
        'contact_number' => maintenance_phone_digits((string) $row['phone_number']),
        'phone' => $row['phone_number'],
        'email' => $row['email'],
        'roleID' => 1,
        'sub_role' => 'student',
        'school_id' => $studentId,
        'password' => password_hash($studentId, PASSWORD_DEFAULT),
        'profile_img' => '',
        'force_password_change' => 1,
        'is_first_login' => 1,
        'password_changed' => 0,
        'temp_password' => $studentId,
    ];

    $insert = [];
    $values = [];
    foreach ($payload as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insert[] = $column;
            $values[] = $value;
        }
    }

    $sql = 'INSERT INTO users (' . implode(', ', $insert) . ') VALUES (' . implode(', ', array_fill(0, count($insert), '?')) . ')';
    $stmt = $db->prepare($sql);
    $stmt->execute($values);

    return (int) $db->lastInsertId();
}

function maintenance_table_columns_fresh(PDO $db, string $table): array
{
    $stmt = $db->prepare(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function maintenance_ensure_merchant_bypass_schema(PDO $db): void
{
    if (gjc_table_exists($db, 'merchant')) {
        $merchantColumns = maintenance_table_columns_fresh($db, 'merchant');
        if (!in_array('notes', $merchantColumns, true)) {
            $db->exec("ALTER TABLE merchant ADD COLUMN notes TEXT NULL");
        }
    }
}

function maintenance_insert_merchant_user(PDO $db, array $data): int
{
    $columns = maintenance_table_columns_fresh($db, 'users');
    $payload = [
        'last_name' => $data['last_name'],
        'first_name' => $data['first_name'],
        'middle_name' => $data['middle_name'] ?? '',
        'suffix' => $data['suffix'] ?? '',
        'contact_number' => maintenance_phone_digits((string) $data['phone']),
        'phone' => $data['phone'],
        'email' => $data['email'],
        'roleID' => 2,
        'sub_role' => 'merchant_admin',
        'password' => password_hash((string) $data['temp_password'], PASSWORD_BCRYPT),
        'profile_img' => '',
        'force_password_change' => 1,
        'is_first_login' => 1,
        'password_changed' => 0,
        'temp_password' => $data['temp_password'],
    ];

    $insert = [];
    $values = [];
    foreach ($payload as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insert[] = $column;
            $values[] = $value;
        }
    }

    $sql = 'INSERT INTO users (' . implode(', ', $insert) . ') VALUES (' . implode(', ', array_fill(0, count($insert), '?')) . ')';
    $stmt = $db->prepare($sql);
    $stmt->execute($values);

    return (int) $db->lastInsertId();
}

function maintenance_insert_merchant_record(PDO $db, int $userId, string $businessName, string $notes, ?string $stallId = null): int
{
    if (!gjc_table_exists($db, 'merchant')) {
        return 0;
    }

    $columns = maintenance_table_columns_fresh($db, 'merchant');
    $payload = [
        'userID' => $userId,
        'stall_name' => $businessName,
        'stall_id' => ($stallId !== null && $stallId !== '') ? $stallId : null,
        'operational_status' => 'active',
        'notes' => $notes !== '' ? $notes : null,
    ];

    $insert = [];
    $values = [];
    foreach ($payload as $column => $value) {
        if (in_array($column, $columns, true)) {
            $insert[] = $column;
            $values[] = $value;
        }
    }

    if (!$insert) {
        return 0;
    }

    $sql = 'INSERT INTO merchant (' . implode(', ', $insert) . ') VALUES (' . implode(', ', array_fill(0, count($insert), '?')) . ')';
    $stmt = $db->prepare($sql);
    $stmt->execute($values);

    return (int) $db->lastInsertId();
}

function maintenance_temp_password(int $length = 10): string
{
    // Ambiguous characters (0/O, 1/l/I) left out so the password is easy to read aloud.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/**
 * Queues a newly-created guardian's login credentials email for background
 * delivery. Mirrors the merchant credentials mail in
 * admin/api/stall_applications.php. Returns true when queued; a failure never
 * derails an import that has already committed.
 */
function maintenance_send_guardian_credentials(string $email, string $name, string $tempPassword): bool
{
    if ($email === '' || $tempPassword === '') {
        return false;
    }

    $loginUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/login' : '/login';
    $safeName  = htmlspecialchars($name !== '' ? $name : 'Guardian', ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safePass  = htmlspecialchars($tempPassword, ENT_QUOTES, 'UTF-8');

    $body = '
            <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:var(--gjc-soft-2);padding:28px;border-radius:14px">
                <h2 style="color:var(--gp-green-850);margin-top:0">Your GenPay Parent Account</h2>
                <p style="color:var(--gjc-slate);line-height:1.7">Dear <strong>' . $safeName . '</strong>,</p>
                <p style="color:var(--gjc-slate);line-height:1.7">A GenPay parent account has been created so you can monitor and manage your child\'s wallet.</p>
                <div style="background:#052e16;border-radius:10px;padding:16px;margin:16px 0;color:var(--gp-success-bg)">
                    <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#86efb2;text-transform:uppercase">Login Credentials</p>
                    <p style="margin:0"><strong>Email:</strong> ' . $safeEmail . '</p>
                    <p style="margin:6px 0 0"><strong>Temporary Password:</strong> ' . $safePass . '</p>
                </div>
                <p style="color:var(--gp-danger);font-weight:700">You must change this password on first login before accessing your dashboard.</p>
                <p style="color:var(--gjc-slate)">Login page: <a href="' . $loginUrl . '" style="color:#147b3a">' . $loginUrl . '</a></p>
                <p style="font-size:12px;color:var(--gp-muted)">GenPay Team</p>
            </div>';
    $altBody = "Dear {$name},\n\nA GenPay parent account has been created so you can monitor and manage your child's wallet.\n\nEmail: {$email}\nTemporary Password: {$tempPassword}\n\nLog in at {$loginUrl}. You must change your password on first login.\n\nGenPay Team";

    return gjc_queue_email($email, $name !== '' ? $name : 'Guardian', 'GenPay - Parent Account Credentials', $body, $altBody);
}

/**
 * Records an admin-created merchant as an already-approved stall application, so
 * it matches a merchant onboarded through the public form + approval. This is the
 * only home for fields like sex / proprietor_name (no column on users/merchant).
 */
function maintenance_insert_approved_application(PDO $db, array $data, int $userId, int $adminId): int
{
    if (!gjc_table_exists($db, 'stall_applications')) {
        return 0;
    }

    $proprietorName = trim(implode(' ', array_filter(
        [$data['first_name'], $data['middle_name'], $data['last_name'], $data['suffix']],
        fn($part) => $part !== ''
    )));
    $stallId = $data['stall_id'] !== '' ? $data['stall_id'] : null;

    // Address + document columns are left to their '' defaults (not collected here).
    $stmt = $db->prepare(
        "INSERT INTO stall_applications
            (business_name, proprietor_name, first_name, middle_name, last_name, suffix, sex,
             contact_number, email, preferred_stall_id, stall_id,
             profile_picture, business_permit, sanitary_permit, gjc_requirements, clearance,
             terms_accepted, status, current_step, reviewed_by, reviewed_at,
             merchant_user_id, temp_password_plain)
         VALUES (?, ?, ?, ?, ?, ?, ?,  ?, ?, ?, ?,  '', '', '', '', '',  1, 'awarded', 5, ?, NOW(),  ?, ?)"
    );
    $stmt->execute([
        $data['business_name'],
        $proprietorName,
        $data['first_name'],
        $data['middle_name'] !== '' ? $data['middle_name'] : null,
        $data['last_name'],
        $data['suffix'] !== '' ? $data['suffix'] : null,
        $data['sex'],
        $data['phone'],
        $data['email'],
        $stallId,
        $stallId,
        $adminId,
        $userId,
        $data['temp_password'],
    ]);

    return (int) $db->lastInsertId();
}

function maintenance_ensure_restricted_products_schema(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS restricted_products (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            product_name VARCHAR(120) NOT NULL,
            category    VARCHAR(60)  NOT NULL DEFAULT 'general',
            reason      VARCHAR(255) NOT NULL,
            match_type  ENUM('exact','contains') NOT NULL DEFAULT 'contains',
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            flagged_by  INT UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

maintenance_ensure_student_registry($db);
maintenance_ensure_merchant_bypass_schema($db);
maintenance_ensure_restricted_products_schema($db);
gjc_ensure_parent_schema($db);

$previewRows = $_SESSION['bulk_student_import_rows'] ?? [];
$previewFileName = (string) ($_SESSION['bulk_student_import_filename'] ?? '');
$previewReport = null;
$importError = '';
$importSummary = null;
$merchantError = '';
$merchantSuccess = null;

$feeWaiverPreviewRows = $_SESSION['bulk_fee_waiver_import_rows'] ?? [];
$feeWaiverPreviewFileName = (string) ($_SESSION['bulk_fee_waiver_import_filename'] ?? '');
$feeWaiverPreviewReport = null;
$feeWaiverImportError = '';
$feeWaiverImportSummary = null;

$restrictedProducts = $db->query(
    "SELECT * FROM restricted_products ORDER BY is_active DESC, category ASC, product_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$studentWaiverRows = $db->query(
    "SELECT u.userID AS student_user_id, u.first_name, u.last_name, si.studentID,
            fwc.amount AS waiver_amount, fwc.status AS waiver_status
       FROM users u
       LEFT JOIN student_info si ON si.userID = u.userID
       LEFT JOIN fee_waiver_credits fwc ON fwc.student_user_id = u.userID
      WHERE u.roleID = 1
      ORDER BY u.last_name ASC, u.first_name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['student_import_action'] ?? '');
    $merchantAction = (string) ($_POST['merchant_bypass_action'] ?? '');
    $feeWaiverAction = (string) ($_POST['fee_waiver_import_action'] ?? '');

    try {
        maintenance_ensure_student_registry($db);

        if ($action === 'preview') {
            if (empty($_FILES['students_csv']) || (int) $_FILES['students_csv']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please choose a valid CSV file.');
            }

            $previewRows = maintenance_parse_student_csv((string) $_FILES['students_csv']['tmp_name']);
            $_SESSION['bulk_student_import_rows'] = $previewRows;
            $_SESSION['bulk_student_import_filename'] = (string) ($_FILES['students_csv']['name'] ?? 'students.csv');
            $previewFileName = (string) $_SESSION['bulk_student_import_filename'];
        }

        if ($action === 'clear_preview') {
            unset($_SESSION['bulk_student_import_rows'], $_SESSION['bulk_student_import_filename']);
            $previewRows = [];
            $previewFileName = '';
        }

        if ($action === 'import') {
            $rows = $_SESSION['bulk_student_import_rows'] ?? [];
            if (!$rows) {
                throw new RuntimeException('Upload and preview a CSV before importing.');
            }

            gjc_ensure_parent_schema($db);

            // Re-validate the whole batch against the current database. If any row
            // is invalid we refuse before opening a transaction — nothing is
            // written unless every row is clean (duplicates are the one allowed
            // skip, since detecting them is the point of the unique student ID).
            $report = maintenance_validate_student_rows($db, $rows);
            if ($report['has_errors']) {
                throw new RuntimeException(
                    $report['counts']['error'] . ' row(s) still have validation errors. '
                    . 'Fix them and re-upload — nothing was imported.'
                );
            }

            $batchId  = date('YmdHis');
            $fileName = (string) ($_SESSION['bulk_student_import_filename'] ?? 'students.csv');
            $summary = [
                'batch_id'        => $batchId,
                'file_name'       => $fileName,
                'total_rows'      => count($rows),
                'imported'        => 0,
                'duplicates'      => 0,
                'failed'          => 0,
                'parents_created' => 0,
                'parents_linked'  => 0,
                'parents_emailed' => 0,
                'parents_email_failed' => 0,
            ];
            $createdUserIds = [];
            $parentCache    = [];
            // Guardians whose accounts were freshly created in this batch; their
            // temp-password emails are sent AFTER commit so SMTP latency never
            // holds a DB lock open and a bad address can't roll back the import.
            $guardianEmailQueue = [];

            $studentInfoInsert = $db->prepare(
                "INSERT INTO student_info (userID, studentID, yr_lvl, courseID) VALUES (?, ?, '1', ?)"
            );
            $registryInsert = $db->prepare(
                "INSERT INTO imported_student_registry
                    (import_batch_id, user_id, student_id_number, first_name, last_name,
                     course_program, email, phone_number, parent_name, parent_email,
                     parent_contact, parent_user_id, parent_status, import_status, message, imported_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $linkInsert = $db->prepare(
                "INSERT IGNORE INTO parent_student_links (parent_id, student_user_id) VALUES (?, ?)"
            );

            // One all-or-nothing transaction: every insert commits together, or a
            // mid-import failure rolls the whole batch back leaving no partial data.
            $db->beginTransaction();
            try {
                foreach ($report['rows'] as $entry) {
                    $row       = $entry['data'];
                    $studentId = (string) $row['student_id_number'];
                    $userId       = null;
                    $parentUserId = null;
                    $parentStatus = 'none';

                    if ($entry['status'] === 'duplicate') {
                        $summary['duplicates']++;
                        $registryStatus = 'duplicate';
                        $message = $entry['message'];
                    } else {
                        // status === 'ready' (error rows were rejected above)
                        $courseId = maintenance_course_id($db, (string) $row['course_program']);
                        $userId   = maintenance_insert_student_user($db, $row);
                        $studentInfoInsert->execute([$userId, $studentId, $courseId]);
                        gjc_student_wallet($db, $userId);

                        // Fee Waiver Credit: freshly-created student only, never touched again on
                        // re-import. INSERT IGNORE is belt-and-suspenders — $userId is always a
                        // brand-new AUTO_INCREMENT id here, so the unique key can't actually collide.
                        $db->prepare("INSERT IGNORE INTO fee_waiver_credits (student_user_id, status) VALUES (?, 'empty')")
                           ->execute([$userId]);

                        $summary['imported']++;
                        $createdUserIds[] = $userId;
                        $registryStatus = 'imported';
                        $message = 'Student account imported.';

                        // Optional guardian: provision (or reuse) and link.
                        if ($entry['parent_action'] === 'link') {
                            $provision = maintenance_provision_parent($db, [
                                'name'    => $row['parent_name'],
                                'email'   => $row['parent_email'],
                                'contact' => $row['parent_contact'],
                            ], $parentCache);

                            if ($provision['ok']) {
                                $parentUserId = $provision['parent_user_id'];
                                $linkInsert->execute([$provision['parent_id'], $userId]);
                                $summary['parents_linked']++;
                                if ($provision['reused']) {
                                    $parentStatus = 'linked';
                                    $message .= ' Guardian linked.';
                                } else {
                                    $summary['parents_created']++;
                                    $parentStatus = 'created';
                                    $message .= ' Guardian created & linked.';
                                    // Queue the credentials email (sent after commit).
                                    if (!empty($provision['temp_password'])) {
                                        $guardianEmailQueue[$provision['parent_user_id']] = [
                                            'email'    => (string) $row['parent_email'],
                                            'name'     => (string) $row['parent_name'],
                                            'password' => (string) $provision['temp_password'],
                                        ];
                                    }
                                }
                            } else {
                                $parentStatus = 'skipped';
                                $message .= ' Guardian skipped: ' . $provision['reason'];
                            }
                        } elseif ($entry['parent_action'] === 'skip') {
                            $parentStatus = 'skipped';
                            $message .= ' ' . $entry['parent_message'];
                        }
                    }

                    $registryInsert->execute([
                        $batchId,
                        $userId,
                        $studentId,
                        (string) $row['first_name'],
                        (string) $row['last_name'],
                        (string) $row['course_program'],
                        (string) $row['email'],
                        (string) $row['phone_number'],
                        (string) $row['parent_name'],
                        (string) $row['parent_email'],
                        (string) $row['parent_contact'],
                        $parentUserId,
                        $parentStatus,
                        $registryStatus,
                        substr($message, 0, 255),
                        (int) $currentUser['id'],
                    ]);
                }
                $db->commit();
            } catch (Throwable $importFailure) {
                $db->rollBack();
                throw $importFailure;
            }

            // The batch is committed. Now email each newly-created guardian their
            // temporary password. A failure here never affects imported data — it
            // only bumps the "email failed" counter shown in the summary.
            foreach ($guardianEmailQueue as $guardian) {
                if (maintenance_send_guardian_credentials($guardian['email'], $guardian['name'], $guardian['password'])) {
                    $summary['parents_emailed']++;
                } else {
                    $summary['parents_email_failed']++;
                }
            }

            logAudit(
                $db,
                (int) $currentUser['id'],
                gjc_current_role(),
                'USER_IMPORT',
                'imported_student_registry',
                null,
                [
                    'import_batch_id'  => $summary['batch_id'],
                    'file_name'        => $summary['file_name'],
                    'total_rows'       => $summary['total_rows'],
                    'imported'         => $summary['imported'],
                    'duplicates'       => $summary['duplicates'],
                    'failed'           => $summary['failed'],
                    'parents_created'  => $summary['parents_created'],
                    'parents_linked'   => $summary['parents_linked'],
                    'parents_emailed'  => $summary['parents_emailed'],
                    'parents_email_failed' => $summary['parents_email_failed'],
                    'created_user_ids' => $createdUserIds,
                ],
                null,
                ['target_id' => $summary['batch_id']]
            );

            $importSummary = $summary;
            unset($_SESSION['bulk_student_import_rows'], $_SESSION['bulk_student_import_filename']);
            $previewRows = [];
            $previewFileName = '';
        }

        // ── Fee Waiver Import: separate from Bulk Student Import above —
        // matches EXISTING students by student_id_number and mints straight
        // into GenCoin, no account creation involved. ──────────────────────
        if ($feeWaiverAction === 'preview') {
            if (empty($_FILES['fee_waiver_csv']) || (int) $_FILES['fee_waiver_csv']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Please choose a valid CSV file.');
            }

            $feeWaiverPreviewRows = maintenance_parse_fee_waiver_csv((string) $_FILES['fee_waiver_csv']['tmp_name']);
            $_SESSION['bulk_fee_waiver_import_rows'] = $feeWaiverPreviewRows;
            $_SESSION['bulk_fee_waiver_import_filename'] = (string) ($_FILES['fee_waiver_csv']['name'] ?? 'fee_waiver.csv');
            $feeWaiverPreviewFileName = (string) $_SESSION['bulk_fee_waiver_import_filename'];
        }

        if ($feeWaiverAction === 'clear_preview') {
            unset($_SESSION['bulk_fee_waiver_import_rows'], $_SESSION['bulk_fee_waiver_import_filename']);
            $feeWaiverPreviewRows = [];
            $feeWaiverPreviewFileName = '';
        }

        if ($feeWaiverAction === 'import') {
            $rows = $_SESSION['bulk_fee_waiver_import_rows'] ?? [];
            if (!$rows) {
                throw new RuntimeException('Upload and preview a CSV before importing.');
            }

            // Re-validate against the current database — a status may have
            // changed (or another import already claimed a row) since preview.
            $report = maintenance_validate_fee_waiver_rows($db, $rows);
            if ($report['has_errors']) {
                throw new RuntimeException(
                    $report['counts']['error'] . ' row(s) still have validation errors. '
                    . 'Fix them and re-upload — nothing was credited.'
                );
            }

            $feeWaiverSummary = [
                'total_rows' => count($rows),
                'credited'   => 0,
                'skipped'    => $report['counts']['skip'],
                'failed'     => 0,
            ];
            $failures = [];

            // No outer transaction: each row is an independent existing student,
            // and gjc_import_fee_waiver_credit() opens its own transaction per
            // call — one failed row never affects the others.
            foreach ($report['rows'] as $entry) {
                if ($entry['status'] !== 'ready') {
                    continue;
                }
                try {
                    gjc_import_fee_waiver_credit(
                        $db,
                        (int) $entry['student_user_id'],
                        (float) $entry['amount'],
                        maintenance_fee_waiver_credit_id($db, (int) $entry['student_user_id']),
                        (int) $currentUser['id']
                    );
                    $feeWaiverSummary['credited']++;
                } catch (Throwable $creditFailure) {
                    $feeWaiverSummary['failed']++;
                    $failures[] = $entry['data']['student_id_number'] . ': ' . $creditFailure->getMessage();
                    error_log('[admin/maintenance.php] Fee Waiver Import credit failed for student '
                        . $entry['data']['student_id_number'] . ': ' . $creditFailure->getMessage());
                }
            }
            if ($failures) {
                $feeWaiverSummary['failure_details'] = $failures;
            }

            $feeWaiverImportSummary = $feeWaiverSummary;
            unset($_SESSION['bulk_fee_waiver_import_rows'], $_SESSION['bulk_fee_waiver_import_filename']);
            $feeWaiverPreviewRows = [];
            $feeWaiverPreviewFileName = '';
        }

        if ($merchantAction === 'create') {
            $merchantData = [
                'first_name' => trim((string) ($_POST['merchant_first_name'] ?? '')),
                'middle_name' => trim((string) ($_POST['merchant_middle_name'] ?? '')),
                'last_name' => trim((string) ($_POST['merchant_last_name'] ?? '')),
                'suffix' => trim((string) ($_POST['merchant_suffix'] ?? '')),
                'sex' => strtolower(trim((string) ($_POST['merchant_sex'] ?? ''))),
                'email' => strtolower(trim((string) ($_POST['merchant_email'] ?? ''))),
                'phone' => trim((string) ($_POST['merchant_phone'] ?? '')),
                'business_name' => trim((string) ($_POST['merchant_business_name'] ?? '')),
                'stall_id' => trim((string) ($_POST['merchant_stall_id'] ?? '')),
                'notes' => trim((string) ($_POST['merchant_notes'] ?? '')),
                'temp_password' => maintenance_temp_password(),
            ];

            if ($merchantData['first_name'] === '' || $merchantData['last_name'] === '' || $merchantData['email'] === '' || $merchantData['phone'] === '' || $merchantData['business_name'] === '') {
                throw new RuntimeException('First name, last name, email, phone, and business name are required.');
            }
            if (!filter_var($merchantData['email'], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid merchant email address.');
            }
            if (!in_array($merchantData['sex'], ['male', 'female'], true)) {
                throw new RuntimeException('Please select the proprietor\'s sex.');
            }

            maintenance_ensure_merchant_bypass_schema($db);

            $emailCheck = $db->prepare("SELECT userID FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $emailCheck->execute([$merchantData['email']]);
            if ($emailCheck->fetchColumn()) {
                throw new RuntimeException('A user with this email already exists.');
            }

            $db->beginTransaction();
            try {
                $newMerchantUserId = maintenance_insert_merchant_user($db, $merchantData);
                $newMerchantId = maintenance_insert_merchant_record(
                    $db,
                    $newMerchantUserId,
                    $merchantData['business_name'],
                    $merchantData['notes'],
                    $merchantData['stall_id'] !== '' ? $merchantData['stall_id'] : null
                );
                gjc_merchant_wallet($db, $newMerchantUserId);

                if ($merchantData['stall_id'] !== '') {
                    $occupyStall = $db->prepare(
                        "UPDATE stalls
                            SET status = 'occupied', merchant_id = ?, pending_expires_at = NULL
                          WHERE stall_id = ? AND status = 'vacant'"
                    );
                    // stalls.merchant_id references merchant.merchantID (not the user id).
                    $occupyStall->execute([$newMerchantId, $merchantData['stall_id']]);
                    if ($occupyStall->rowCount() === 0) {
                        throw new RuntimeException('That stall is no longer available — please pick another.');
                    }
                }

                maintenance_insert_approved_application($db, $merchantData, $newMerchantUserId, (int) $currentUser['id']);

                $db->commit();
            } catch (Throwable $merchantCreateError) {
                $db->rollBack();
                throw $merchantCreateError;
            }

            logAudit(
                $db,
                (int) $currentUser['id'],
                gjc_current_role(),
                'MERCHANT_CREATE',
                'users',
                null,
                [
                    'created_user_id' => $newMerchantUserId,
                    'merchant_id' => $newMerchantId,
                    'first_name' => $merchantData['first_name'],
                    'last_name' => $merchantData['last_name'],
                    'sex' => $merchantData['sex'],
                    'email' => $merchantData['email'],
                    'phone' => $merchantData['phone'],
                    'business_name' => $merchantData['business_name'],
                    'stall_id' => $merchantData['stall_id'] !== '' ? $merchantData['stall_id'] : null,
                    'notes' => $merchantData['notes'],
                    'roleID' => 2,
                    'sub_role' => 'merchant_admin',
                    'forced_password_change' => true,
                    'email_sent' => false,
                ],
                null,
                ['target_id' => $newMerchantUserId]
            );

            $merchantSuccess = [
                'user_id' => $newMerchantUserId,
                'merchant_id' => $newMerchantId,
                'business_name' => $merchantData['business_name'],
                'email' => $merchantData['email'],
                'temp_password' => $merchantData['temp_password'],
            ];
        }

    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($merchantAction === 'create') {
            $merchantError = $error->getMessage();
        } elseif ($feeWaiverAction !== '') {
            $feeWaiverImportError = $error->getMessage();
        } else {
            $importError = $error->getMessage();
        }
    }
}

// Validate whatever is staged for preview against the current database so the
// operator always sees an up-to-date verdict (works on POST preview and on any
// later page reload while a preview is held in the session).
$previewReport = $previewRows ? maintenance_validate_student_rows($db, $previewRows) : null;
$feeWaiverPreviewReport = $feeWaiverPreviewRows ? maintenance_validate_fee_waiver_rows($db, $feeWaiverPreviewRows) : null;

$vacantStalls = $db->query(
    "SELECT stall_id, label, monthly_rate FROM stalls WHERE status = 'vacant' ORDER BY label ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/maintenance.css?v=19">
</head>
<body class="gp-theme">
<div class="admin-layout">
    <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

    <main class="admin-main">
        <header class="topbar">
            <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
            <div>
                <h1>Maintenance</h1>
                <p>Administrative maintenance workspace.</p>
            </div>
            <div class="admin-user">
                <span><?= gjc_e($currentUser['name']) ?></span>
                <div class="avatar">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
            </div>
        </header>

        <section class="maintenance-grid">
            <div class="maintenance-placeholder">
                <span class="section-tag">Students</span>
                <h3>Bulk Student Import</h3>
                <p class="maintenance-help">
                    Only the downloaded template is accepted — same columns, same order:
                    <strong>student_id_number, first_name, last_name, course_program, email, phone_number, suffix, parent_name, parent_email, parent_contact</strong>.
                    The last four may be left blank per row (guardian is auto-created &amp; linked when filled in), but the columns themselves must stay.
                </p>

                <?php if ($importError !== ''): ?>
                <div class="maintenance-alert error">
                    <?= maintenance_e($importError) ?>
                </div>
                <?php endif; ?>

                <?php if ($importSummary): ?>
                <div class="maintenance-alert success">
                    Import batch <strong><?= maintenance_e($importSummary['batch_id']) ?></strong>
                    from <strong><?= maintenance_e($importSummary['file_name']) ?></strong> completed.
                    Duplicates were skipped and logged in the import registry.
                    <div class="import-summary-grid">
                        <div class="import-summary-card">
                            <span>Total Rows</span>
                            <strong><?= (int) $importSummary['total_rows'] ?></strong>
                        </div>
                        <div class="import-summary-card">
                            <span>Imported</span>
                            <strong><?= (int) $importSummary['imported'] ?></strong>
                        </div>
                        <div class="import-summary-card">
                            <span>Duplicates</span>
                            <strong><?= (int) $importSummary['duplicates'] ?></strong>
                        </div>
                        <div class="import-summary-card">
                            <span>Guardians Created</span>
                            <strong><?= (int) $importSummary['parents_created'] ?></strong>
                        </div>
                        <div class="import-summary-card">
                            <span>Guardians Linked</span>
                            <strong><?= (int) $importSummary['parents_linked'] ?></strong>
                        </div>
                        <div class="import-summary-card">
                            <span>Credentials Emailed</span>
                            <strong><?= (int) ($importSummary['parents_emailed'] ?? 0) ?></strong>
                        </div>
                    </div>
                    <?php if ((int) ($importSummary['parents_email_failed'] ?? 0) > 0): ?>
                        <p style="margin:10px 0 0;color:var(--gp-danger);font-size:12px">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <?= (int) $importSummary['parents_email_failed'] ?> credential email(s) could not be sent.
                            Those guardians' temporary passwords are still saved and can be retrieved from the import registry.
                        </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form class="student-import-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="student_import_action" value="preview">
                    <label for="students_csv">CSV File</label>
                    <input type="file" id="students_csv" name="students_csv" accept=".csv,text/csv" required>
                    <p class="maintenance-help mb-0 mt-2">
                        Imported students use their student ID number as the initial password. Guardians (when provided)
                        get a generated temporary password emailed to them automatically. Both are forced to change it on first login.
                    </p>
                    <div class="maintenance-btn-row">
                        <button class="maintenance-btn primary" type="submit">Preview CSV</button>
                        <a class="maintenance-btn muted" href="?download_template=students">
                            <i class="fa-solid fa-download" style="margin-right:6px"></i>Download Template
                        </a>
                    </div>
                </form>

            </div>

            <div class="maintenance-placeholder">
                <span class="section-tag">Merchants</span>
                <h3>Add Merchant</h3>
                <p class="maintenance-help">
                    Manually create a merchant account and set credentials directly. No email is sent &mdash; hand the
                    temporary password over in person. The merchant is prompted to set their own password on first login.
                </p>

                <?php if ($merchantSuccess): ?>
                <div class="maintenance-alert success">
                    Merchant account created for
                    <strong><?= maintenance_e($merchantSuccess['business_name']) ?></strong>
                    (User ID <strong><?= (int) $merchantSuccess['user_id'] ?></strong>).
                    They sign in with email <strong><?= maintenance_e($merchantSuccess['email']) ?></strong>
                    and temporary password
                    <strong style="font-family:monospace;letter-spacing:.5px"><?= maintenance_e($merchantSuccess['temp_password']) ?></strong>.
                    Share these with the merchant — they will be asked to set a new password on first login.
                </div>
                <?php endif; ?>

                <div class="maintenance-btn-row">
                    <button class="maintenance-btn primary" data-bs-toggle="modal" data-bs-target="#addMerchantModal">
                        <i class="fa-solid fa-store" style="margin-right:6px"></i>Add Merchant
                    </button>
                </div>
            </div>
        </section>

        <!-- Import Preview Modal -->
        <?php if ($previewRows && $previewReport): $rc = $previewReport['counts']; ?>
        <div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <div class="modal-content" style="border-radius:16px;border:none">
                    <div class="modal-header border-0" style="padding:20px 24px 6px">
                        <div>
                            <h5 class="modal-title fw-bold" style="font-size:17px">
                                <i class="fa-solid fa-file-csv me-2" style="color:var(--gp-success)"></i>Import Preview
                            </h5>
                            <p style="font-size:12px;color:var(--gp-muted);margin:3px 0 0">
                                <?= maintenance_e($previewFileName ?: 'Uploaded CSV') ?> — <?= (int) $rc['total'] ?> parsed row<?= $rc['total'] !== 1 ? 's' : '' ?>, reviewed before anything is written.
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:12px 24px 8px">
                        <div class="import-summary-grid">
                            <div class="import-summary-card"><span>Total</span><strong><?= (int) $rc['total'] ?></strong></div>
                            <div class="import-summary-card"><span>Ready</span><strong><?= (int) $rc['ready'] ?></strong></div>
                            <div class="import-summary-card"><span>Duplicates</span><strong><?= (int) $rc['duplicate'] ?></strong></div>
                            <div class="import-summary-card"><span>Errors</span><strong><?= (int) $rc['error'] ?></strong></div>
                            <div class="import-summary-card"><span>With Guardian</span><strong><?= (int) $rc['with_parent'] ?></strong></div>
                        </div>

                        <?php if ($previewReport['has_errors']): ?>
                        <div class="maintenance-alert error">
                            <?= (int) $rc['error'] ?> row(s) have validation errors (highlighted below). Fix them in the CSV
                            and upload again — the import stays blocked until every row is valid.
                        </div>
                        <?php endif; ?>

                        <div class="table-responsive" style="max-height:52vh;overflow:auto">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Row</th>
                                        <th>Status</th>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Course</th>
                                        <th>Email</th>
                                        <th>Guardian</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($previewReport['rows'], 0, 100) as $entry): $r = $entry['data']; ?>
                                    <tr class="<?= $entry['status'] === 'error' ? 'table-danger' : ($entry['status'] === 'duplicate' ? 'table-warning' : '') ?>">
                                        <td><?= (int) $entry['row_number'] ?></td>
                                        <td>
                                            <?php if ($entry['status'] === 'ready'): ?>
                                                <span class="badge bg-success">Ready</span>
                                            <?php elseif ($entry['status'] === 'duplicate'): ?>
                                                <span class="badge bg-warning text-dark">Duplicate</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Error</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= maintenance_e($r['student_id_number']) ?></td>
                                        <td><?= maintenance_e(trim(preg_replace('/\s+/', ' ', $r['first_name'] . ' ' . $r['last_name'] . ' ' . ($r['suffix'] ?? '')))) ?></td>
                                        <td><?= maintenance_e($r['course_program']) ?></td>
                                        <td><?= maintenance_e($r['email']) ?></td>
                                        <td>
                                            <?php if ($entry['parent_action'] === 'link'): ?>
                                                <span title="<?= maintenance_e($r['parent_email']) ?>">
                                                    <i class="fa-solid fa-user-shield" style="color:var(--gp-success)"></i>
                                                    <?= maintenance_e($r['parent_name'] ?: $r['parent_email']) ?>
                                                </span>
                                            <?php elseif ($entry['parent_action'] === 'skip'): ?>
                                                <span class="text-warning" title="<?= maintenance_e($entry['parent_message']) ?>">
                                                    <i class="fa-solid fa-triangle-exclamation"></i> skipped
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px"><?= maintenance_e(trim($entry['message'] . ' ' . ($entry['parent_action'] === 'skip' ? $entry['parent_message'] : ''))) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($previewReport['rows']) > 100): ?>
                        <p class="maintenance-help mb-0">Showing the first 100 of <?= count($previewReport['rows']) ?> rows.</p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-0" style="padding:8px 24px 22px">
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="student_import_action" value="clear_preview">
                            <button class="maintenance-btn muted" type="submit">Clear Preview</button>
                        </form>
                        <?php if ($previewReport['has_errors']): ?>
                            <button class="maintenance-btn warning" type="button" disabled
                                    title="Resolve the highlighted validation errors first">
                                Import Blocked — Fix <?= (int) $rc['error'] ?> Error<?= $rc['error'] !== 1 ? 's' : '' ?>
                            </button>
                        <?php elseif ((int) $rc['ready'] === 0): ?>
                            <button class="maintenance-btn muted" type="button" disabled>
                                No New Students to Import
                            </button>
                        <?php else: ?>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="student_import_action" value="import">
                                <button class="maintenance-btn warning" type="submit">
                                    Import <?= (int) $rc['ready'] ?> Student<?= $rc['ready'] !== 1 ? 's' : '' ?><?php if ($rc['duplicate']): ?> (<?= (int) $rc['duplicate'] ?> dup skipped)<?php endif; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Fee Waiver Import Preview Modal -->
        <?php if ($feeWaiverPreviewRows && $feeWaiverPreviewReport): $fwrc = $feeWaiverPreviewReport['counts']; ?>
        <div class="modal fade" id="feeWaiverPreviewModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <div class="modal-content" style="border-radius:16px;border:none">
                    <div class="modal-header border-0" style="padding:20px 24px 6px">
                        <div>
                            <h5 class="modal-title fw-bold" style="font-size:17px">
                                <i class="fa-solid fa-file-csv me-2" style="color:var(--gp-success)"></i>Fee Waiver Import Preview
                            </h5>
                            <p style="font-size:12px;color:var(--gp-muted);margin:3px 0 0">
                                <?= maintenance_e($feeWaiverPreviewFileName ?: 'Uploaded CSV') ?> — <?= (int) $fwrc['total'] ?> parsed row<?= $fwrc['total'] !== 1 ? 's' : '' ?>, reviewed before anything is credited.
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:12px 24px 8px">
                        <div class="import-summary-grid">
                            <div class="import-summary-card"><span>Total</span><strong><?= (int) $fwrc['total'] ?></strong></div>
                            <div class="import-summary-card"><span>Ready</span><strong><?= (int) $fwrc['ready'] ?></strong></div>
                            <div class="import-summary-card"><span>Skipped</span><strong><?= (int) $fwrc['skip'] ?></strong></div>
                            <div class="import-summary-card"><span>Errors</span><strong><?= (int) $fwrc['error'] ?></strong></div>
                        </div>

                        <?php if ($feeWaiverPreviewReport['has_errors']): ?>
                        <div class="maintenance-alert error">
                            <?= (int) $fwrc['error'] ?> row(s) have validation errors (highlighted below). Fix them in the CSV
                            and upload again — the import stays blocked until every row is valid.
                        </div>
                        <?php endif; ?>

                        <div class="table-responsive" style="max-height:52vh;overflow:auto">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>Row</th>
                                        <th>Status</th>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Amount</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($feeWaiverPreviewReport['rows'], 0, 100) as $entry): $r = $entry['data']; ?>
                                    <tr class="<?= $entry['status'] === 'error' ? 'table-danger' : ($entry['status'] === 'skip' ? 'table-warning' : '') ?>">
                                        <td><?= (int) $entry['row_number'] ?></td>
                                        <td>
                                            <?php if ($entry['status'] === 'ready'): ?>
                                                <span class="badge bg-success">Ready</span>
                                            <?php elseif ($entry['status'] === 'skip'): ?>
                                                <span class="badge bg-warning text-dark">Skipped</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Error</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= maintenance_e($r['student_id_number']) ?></td>
                                        <td><?= maintenance_e($entry['student_name'] ?: '—') ?></td>
                                        <td>
                                            <?php if ($entry['amount'] !== null): ?>
                                                <span style="color:var(--gp-success);font-weight:700">&#8369;<?= number_format($entry['amount'], 2) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px"><?= maintenance_e($entry['message']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($feeWaiverPreviewReport['rows']) > 100): ?>
                        <p class="maintenance-help mb-0">Showing the first 100 of <?= count($feeWaiverPreviewReport['rows']) ?> rows.</p>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer border-0" style="padding:8px 24px 22px">
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="fee_waiver_import_action" value="clear_preview">
                            <button class="maintenance-btn muted" type="submit">Clear Preview</button>
                        </form>
                        <?php if ($feeWaiverPreviewReport['has_errors']): ?>
                            <button class="maintenance-btn warning" type="button" disabled
                                    title="Resolve the highlighted validation errors first">
                                Import Blocked — Fix <?= (int) $fwrc['error'] ?> Error<?= $fwrc['error'] !== 1 ? 's' : '' ?>
                            </button>
                        <?php elseif ((int) $fwrc['ready'] === 0): ?>
                            <button class="maintenance-btn muted" type="button" disabled>
                                No Rows Ready to Credit
                            </button>
                        <?php else: ?>
                            <form method="POST" style="margin:0">
                                <input type="hidden" name="fee_waiver_import_action" value="import">
                                <button class="maintenance-btn warning" type="submit">
                                    Credit <?= (int) $fwrc['ready'] ?> Student<?= $fwrc['ready'] !== 1 ? 's' : '' ?><?php if ($fwrc['skip']): ?> (<?= (int) $fwrc['skip'] ?> skipped)<?php endif; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Add Merchant Modal -->
        <div class="modal fade" id="addMerchantModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
                <div class="modal-content" style="border-radius:16px;border:none">
                    <div class="modal-header border-0" style="padding:20px 24px 0">
                        <div>
                            <h5 class="modal-title fw-bold" style="font-size:17px">
                                <i class="fa-solid fa-store me-2" style="color:var(--gp-success)"></i>Add Merchant
                            </h5>
                            <p style="font-size:12px;color:var(--gp-muted);margin:3px 0 0">Same details as the public application, but approved instantly — the account is created and ready to use right away.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:16px 24px 24px">
                        <?php if ($merchantError !== ''): ?>
                        <div class="maintenance-alert error mb-3">
                            <?= maintenance_e($merchantError) ?>
                        </div>
                        <?php endif; ?>

                        <form class="merchant-bypass-form" method="POST" autocomplete="off">
                            <input type="hidden" name="merchant_bypass_action" value="create">
                            <div class="mbf-section">
                                <div class="mbf-section-eyebrow"><i class="fa-solid fa-user"></i> Proprietor</div>
                                <div class="maintenance-form-grid">
                                    <div>
                                        <label for="merchant_first_name">First Name *</label>
                                        <input type="text" id="merchant_first_name" name="merchant_first_name" maxlength="60" required>
                                    </div>
                                    <div>
                                        <label for="merchant_middle_name">Middle Name</label>
                                        <input type="text" id="merchant_middle_name" name="merchant_middle_name" maxlength="60">
                                    </div>
                                    <div>
                                        <label for="merchant_last_name">Last Name *</label>
                                        <input type="text" id="merchant_last_name" name="merchant_last_name" maxlength="60" required>
                                    </div>
                                    <div>
                                        <label for="merchant_suffix">Suffix</label>
                                        <input type="text" id="merchant_suffix" name="merchant_suffix" maxlength="20" placeholder="e.g. Jr., Sr., III">
                                    </div>
                                    <div>
                                        <label for="merchant_sex">Sex *</label>
                                        <select id="merchant_sex" name="merchant_sex" required>
                                            <option value="">Select…</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mbf-section">
                                <div class="mbf-section-eyebrow"><i class="fa-solid fa-store"></i> Business &amp; contact</div>
                                <div class="maintenance-form-grid">
                                    <div class="full">
                                        <label for="merchant_business_name">Business Name *</label>
                                        <input type="text" id="merchant_business_name" name="merchant_business_name" required>
                                    </div>
                                    <div class="full">
                                        <label for="merchant_stall_id">Stall</label>
                                        <select id="merchant_stall_id" name="merchant_stall_id">
                                            <option value="">No stall — assign later</option>
                                            <?php foreach ($vacantStalls as $st): ?>
                                            <option value="<?= maintenance_e($st['stall_id']) ?>"><?= maintenance_e($st['label']) ?> — <?= gjc_money((float) $st['monthly_rate']) ?>/mo</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="merchant_email">Email *</label>
                                        <input type="email" id="merchant_email" name="merchant_email" required>
                                    </div>
                                    <div>
                                        <label for="merchant_phone">Phone *</label>
                                        <input type="text" id="merchant_phone" name="merchant_phone" required>
                                    </div>
                                </div>
                            </div>

                            <div class="mbf-section">
                                <div class="mbf-section-eyebrow"><i class="fa-solid fa-key"></i> Login credentials</div>
                                <div class="mbf-credentials-note">
                                    <i class="fa-solid fa-circle-info"></i>
                                    <span>The merchant signs in with the email above. A temporary password is generated automatically and shown here once the account is created — share it with the merchant, who will be prompted to set a new password on first login.</span>
                                </div>
                            </div>

                            <div class="mbf-section">
                                <div class="mbf-section-eyebrow"><i class="fa-solid fa-note-sticky"></i> Internal notes</div>
                                <div class="maintenance-form-grid">
                                    <div class="full">
                                        <label for="merchant_notes">Notes</label>
                                        <textarea id="merchant_notes" name="merchant_notes" placeholder="e.g. onboarding context or special terms — admins only"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="maintenance-btn-row mt-2">
                                <button class="maintenance-btn primary" type="submit">Create Merchant</button>
                                <button type="button" class="maintenance-btn muted" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section C: Restricted Products (same data as admin/restricted_products.php) -->
        <section class="rp-section">
            <div class="rp-section-header">
                <div class="rp-section-title">
                    <i class="fa-solid fa-ban" style="font-size:16px;color:var(--gp-red)"></i>
                    <h3>Restricted Products</h3>
                    <span class="rp-count-badge" id="rp-count">
                        <?= count($restrictedProducts) ?> item<?= count($restrictedProducts) !== 1 ? 's' : '' ?>
                    </span>
                </div>
                <button class="rp-add-btn" data-bs-toggle="modal" data-bs-target="#flagProductModal">
                    <i class="fa-solid fa-plus"></i> Flag Product
                </button>
            </div>
            <p class="maintenance-help">
                The same list appears on
                <a href="<?= ADMIN_URL ?>/restricted_products.php">Restricted Products</a>, which also shows
                which merchants are close to an automatic suspension. Strike thresholds are set under
                <a href="<?= ADMIN_URL ?>/settings.php">Settings &rsaquo; Merchant Strikes</a>.
            </p>

            <?php
            $categoryIcons = [
                'beverage'   => 'fa-mug-hot',
                'drink'      => 'fa-bottle-water',
                'snack'      => 'fa-cookie-bite',
                'food'       => 'fa-burger',
                'alcohol'    => 'fa-wine-glass',
                'tobacco'    => 'fa-smoking',
                'supplement' => 'fa-pills',
                'medicine'   => 'fa-capsules',
                'candy'      => 'fa-candy-cane',
                'drug'       => 'fa-syringe',
                'general'    => 'fa-ban',
            ];
            ?>

            <div class="table-responsive">
                <table class="table rp-table align-middle js-datatable" id="rp-datatable" data-page-length="10" data-empty-message="No prohibited products flagged. All product categories are currently allowed.">
                    <thead>
                        <tr>
                            <th>Product / Keyword</th>
                            <th>Category</th>
                            <th>Match Type</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th data-orderable="false">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rp-tbody">
                    <?php foreach ($restrictedProducts as $rp): ?>
                    <?php $active = (int) $rp['is_active']; ?>
                    <tr id="rp-card-<?= (int)$rp['id'] ?>">
                        <td><strong><?= maintenance_e($rp['product_name']) ?></strong></td>
                        <td style="text-transform:capitalize"><?= maintenance_e($rp['category']) ?></td>
                        <td>
                            <span class="rp-tag rp-tag--match-<?= maintenance_e($rp['match_type']) ?>">
                                <?= $rp['match_type'] === 'exact' ? 'Exact match' : 'Contains' ?>
                            </span>
                        </td>
                        <td style="max-width:260px;font-size:13px"><?= maintenance_e($rp['reason']) ?></td>
                        <td>
                            <span class="rp-status-badge <?= $active ? 'rp-status--banned' : 'rp-status--lifted' ?>">
                                <?= $active ? 'BANNED' : 'LIFTED' ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($active): ?>
                            <button class="rp-toggle-btn rp-toggle-btn--ban"
                                    onclick="rpToggle(<?= (int)$rp['id'] ?>, 0)">
                                <i class="fa-solid fa-lock-open me-1"></i>Lift Ban
                            </button>
                            <?php else: ?>
                            <button class="rp-toggle-btn rp-toggle-btn--lift"
                                    onclick="rpToggle(<?= (int)$rp['id'] ?>, 1)">
                                <i class="fa-solid fa-ban me-1"></i>Reinstate
                            </button>
                            <?php endif; ?>
                            <button class="rp-remove-btn ms-1" title="Remove permanently"
                                    onclick="rpRemove(<?= (int)$rp['id'] ?>, '<?= addslashes($rp['product_name']) ?>')">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Flag Product Modal -->
        <div class="modal fade" id="flagProductModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
                <div class="modal-content" style="border-radius:16px;border:none">
                    <div class="modal-header border-0 pb-0" style="padding:20px 24px 10px">
                        <div>
                            <h5 class="modal-title fw-bold" style="color:var(--gp-red)">
                                <i class="fa-solid fa-ban me-2"></i>Flag Restricted Product
                            </h5>
                            <p style="font-size:12px;color:var(--gp-muted);margin:4px 0 0">Flagged products will be blocked from being sold on the platform.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:16px 24px 24px">
                        <div class="rp-modal-field">
                            <label class="rp-modal-label">Product Name *</label>
                            <input type="text" id="rp-name" class="rp-modal-input" placeholder="e.g. Energy Drink">
                        </div>
                        <div class="rp-modal-field">
                            <label class="rp-modal-label">Category</label>
                            <select id="rp-category" class="rp-modal-input">
                                <option value="general">General</option>
                                <option value="beverage">Beverage</option>
                                <option value="snack">Snack</option>
                                <option value="food">Food</option>
                                <option value="alcohol">Alcohol</option>
                                <option value="tobacco">Tobacco</option>
                                <option value="supplement">Supplement</option>
                                <option value="medicine">Medicine</option>
                                <option value="candy">Candy</option>
                            </select>
                        </div>
                        <div class="rp-modal-field">
                            <label class="rp-modal-label">Match Type</label>
                            <select id="rp-match-type" class="rp-modal-input">
                                <option value="contains">Contains — block anything with this word</option>
                                <option value="exact">Exact — block only exact name</option>
                            </select>
                        </div>
                        <div class="rp-modal-field">
                            <label class="rp-modal-label">Reason *</label>
                            <input type="text" id="rp-reason" class="rp-modal-input"
                                   placeholder="e.g. High sugar content — DepEd health guidelines">
                        </div>
                        <div id="rp-modal-alert"></div>
                        <button type="button" id="rp-flag-btn" class="rp-add-btn w-100 justify-content-center mt-2"
                                style="border-radius:10px;padding:11px"
                                onclick="rpFlagProduct()">
                            <i class="fa-solid fa-ban me-1"></i> Flag This Product
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section D: Fee Waiver Credits -->
        <section class="rp-section">
            <div class="rp-section-header">
                <div class="rp-section-title">
                    <i class="fa-solid fa-hand-holding-dollar" style="font-size:16px;color:var(--gp-success)"></i>
                    <h3>Fee Waiver Credits</h3>
                    <span class="rp-count-badge">
                        <?= count($studentWaiverRows) ?> student<?= count($studentWaiverRows) !== 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>
            <p class="maintenance-help">
                A misc. credit finance creates to be applied against a student's tuition — separate from the
                GenCoin wallet, and separate from tuition fee itself, which this system does not manage.
            </p>

            <div class="fwc-import-panel">
                <div class="fwc-import-panel-head">
                    <strong>Fee Waiver Import</strong>
                    <span class="maintenance-help mb-0">
                        Bulk-credit existing students. Only the downloaded template is accepted (<strong>student_id_number, additional_fee</strong>) —
                        mints straight into their wallet, no waiver upload. Students whose credit isn't empty are skipped.
                    </span>
                </div>

                <?php if ($feeWaiverImportError !== ''): ?>
                <div class="maintenance-alert error">
                    <?= maintenance_e($feeWaiverImportError) ?>
                </div>
                <?php endif; ?>

                <?php if ($feeWaiverImportSummary): ?>
                <div class="maintenance-alert success">
                    Fee Waiver Import completed.
                    <div class="import-summary-grid">
                        <div class="import-summary-card">
                            <span>Total Rows</span>
                            <strong><?= (int) $feeWaiverImportSummary['total_rows'] ?></strong>
                        </div>
                        <div class="import-summary-card">
                            <span>Credited</span>
                            <strong><?= (int) $feeWaiverImportSummary['credited'] ?></strong>
                        </div>
                        <div class="import-summary-card">
                            <span>Skipped</span>
                            <strong><?= (int) $feeWaiverImportSummary['skipped'] ?></strong>
                        </div>
                    </div>
                    <?php if ((int) ($feeWaiverImportSummary['failed'] ?? 0) > 0): ?>
                        <p style="margin:10px 0 0;color:var(--gp-danger);font-size:12px">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <?= (int) $feeWaiverImportSummary['failed'] ?> row(s) could not be credited. Check the audit log or retry them individually below.
                        </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <form class="fwc-import-form" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="fee_waiver_import_action" value="preview">
                    <input type="file" id="fee_waiver_csv" name="fee_waiver_csv" accept=".csv,text/csv" required>
                    <button class="maintenance-btn primary" type="submit">Preview CSV</button>
                    <a class="maintenance-btn muted" href="?download_template=fee_waiver">
                        <i class="fa-solid fa-download" style="margin-right:6px"></i>Download Template
                    </a>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table rp-table align-middle js-datatable" id="fwc-datatable" data-page-length="10" data-empty-message="No students found.">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Waiver Credit</th>
                            <th>Status</th>
                            <th data-orderable="false">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($studentWaiverRows as $sf): ?>
                        <?php
                        $sid = (int) $sf['student_user_id'];
                        $status = (string) ($sf['waiver_status'] ?: 'empty');
                        $statusBadgeClass = match ($status) {
                            'posted'  => 'bg-success',
                            'pending' => 'bg-warning text-dark',
                            default   => 'bg-secondary',
                        };
                        ?>
                        <tr id="fwc-row-<?= $sid ?>">
                            <td><strong><?= maintenance_e(trim($sf['first_name'] . ' ' . $sf['last_name'])) ?></strong></td>
                            <td><?= maintenance_e($sf['studentID'] ?: '—') ?></td>
                            <td>
                                <?= $status === 'posted' ? '₱' . maintenance_e(number_format((float) $sf['waiver_amount'], 2)) : '—' ?>
                            </td>
                            <td>
                                <span class="rp-status-badge badge <?= $statusBadgeClass ?>" style="text-transform:uppercase">
                                    <?= maintenance_e(ucfirst($status)) ?>
                                </span>
                            </td>
                            <td>
                                <div class="fwc-actions">
                                <?php if ($status === 'empty'): ?>
                                    <button class="fwc-action-btn fwc-action-btn--primary" title="Enter waiver amount" onclick="fwcOpenAmountModal(<?= $sid ?>)">
                                        <i class="fa-solid fa-plus"></i>Enter Amount
                                    </button>
                                <?php elseif ($status === 'pending'): ?>
                                    <button class="fwc-action-btn fwc-action-btn--primary" title="Upload signed waiver" onclick="fwcOpenUploadModal(<?= $sid ?>)">
                                        <i class="fa-solid fa-upload"></i>Upload Waiver
                                    </button>
                                    <a class="rp-icon-btn" title="Download blank waiver form" href="<?= ADMIN_URL ?>/print_fee_waiver.php?student_user_id=<?= $sid ?>" target="_blank">
                                        <i class="fa-solid fa-print"></i>
                                    </a>
                                    <button class="rp-remove-btn" title="Cancel and reset" onclick="fwcCancel(<?= $sid ?>)">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="fwc-action-btn fwc-action-btn--muted" title="View log &amp; details" onclick="fwcOpenDetailModal(<?= $sid ?>)">
                                        <i class="fa-solid fa-clock-rotate-left"></i>View Log
                                    </button>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Fee Waiver: Set Amount Modal -->
        <div class="modal fade" id="fwcAmountModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
                <div class="modal-content" style="border-radius:16px;border:none">
                    <div class="modal-header border-0 pb-0" style="padding:20px 24px 10px">
                        <div>
                            <h5 class="modal-title fw-bold" style="color:var(--gp-success)">
                                <i class="fa-solid fa-hand-holding-dollar me-2"></i>Fee Waiver Credit Amount
                            </h5>
                            <p style="font-size:12px;color:var(--gp-muted);margin:4px 0 0">
                                Enter the approved waiver amount. The credit moves to Pending until the signed waiver is uploaded.
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:16px 24px 24px">
                        <input type="hidden" id="fwc-amount-student-id">
                        <div class="rp-modal-field">
                            <label class="rp-modal-label">Amount (₱) *</label>
                            <input type="number" id="fwc-amount-input" class="rp-modal-input" min="0.01" max="<?= gjc_setting($db, 'fee_waiver_max_amount') ?>" step="0.01" placeholder="e.g. 500.00">
                        </div>
                        <div id="fwc-amount-alert"></div>
                        <button type="button" id="fwc-amount-btn" class="rp-add-btn w-100 justify-content-center mt-2"
                                style="border-radius:10px;padding:11px" onclick="fwcSubmitAmount()">
                            <i class="fa-solid fa-check me-1"></i> Save Amount
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Waiver: Upload Signed Waiver Modal -->
        <div class="modal fade" id="fwcUploadModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
                <div class="modal-content" style="border-radius:16px;border:none">
                    <div class="modal-header border-0 pb-0" style="padding:20px 24px 10px">
                        <div>
                            <h5 class="modal-title fw-bold" style="color:var(--gp-success)">
                                <i class="fa-solid fa-upload me-2"></i>Upload Signed Waiver
                            </h5>
                            <p style="font-size:12px;color:var(--gp-muted);margin:4px 0 0">JPG, PNG, or PDF, up to 5&nbsp;MB.</p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:16px 24px 24px">
                        <input type="hidden" id="fwc-upload-student-id">
                        <p style="margin:0 0 14px">
                            <a id="fwc-upload-blank-link" href="#" target="_blank"
                               style="font-size:13px;color:var(--gp-success);font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                                <i class="fa-solid fa-print"></i>Download blank waiver
                            </a>
                        </p>
                        <div class="rp-modal-field">
                            <label class="rp-modal-label">Signed Waiver File *</label>
                            <input type="file" id="fwc-upload-input" class="rp-modal-input" accept=".jpg,.jpeg,.png,.pdf">
                        </div>
                        <div id="fwc-upload-alert"></div>
                        <button type="button" id="fwc-upload-btn" class="rp-add-btn w-100 justify-content-center mt-2"
                                style="border-radius:10px;padding:11px" onclick="fwcSubmitUpload()">
                            <i class="fa-solid fa-upload me-1"></i> Upload &amp; Post
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Waiver: Detail / Log History Modal -->
        <div class="modal fade" id="fwcDetailModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" style="border-radius:16px;border:none">
                    <div class="modal-header border-0" style="padding:20px 24px 6px">
                        <div>
                            <h5 class="modal-title fw-bold" style="font-size:17px">
                                <i class="fa-solid fa-clock-rotate-left me-2" style="color:var(--gp-success)"></i>Fee Waiver Credit — Detail
                            </h5>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:12px 24px 8px">
                        <div class="import-summary-grid" id="fwc-detail-summary"></div>
                        <div class="table-responsive mt-3" style="max-height:40vh;overflow:auto">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr><th>When</th><th>Change</th><th>Amount</th><th>By</th></tr>
                                </thead>
                                <tbody id="fwc-detail-logs"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer border-0" style="padding:8px 24px 22px">
                        <a id="fwc-detail-waiver-link" href="#" onclick="return gjcViewWaiver(this.href);" class="maintenance-btn primary" style="display:none">
                            <i class="fa-solid fa-file-lines me-1"></i>View Signed Waiver
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fee Waiver: Signed Waiver Viewer (inline, no new tab/window) -->
        <div class="modal fade" id="gjcWaiverModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content" style="border-radius:16px;border:none;overflow:hidden">
                    <div class="modal-header border-0" style="padding:16px 20px">
                        <h5 class="modal-title fw-bold" style="font-size:15px">
                            <i class="fa-solid fa-file-lines me-2"></i>Signed Waiver
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" style="padding:0">
                        <iframe id="gjcWaiverFrame" src="" style="width:100%;height:70vh;border:0;display:block"></iframe>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/../includes/partials/datatables_assets.php'; ?>
<?php require __DIR__ . '/../includes/partials/feedback_assets.php'; ?>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
}

<?php if ($merchantError !== ''): ?>
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('addMerchantModal')).show();
});
<?php endif; ?>

<?php if ($previewRows && $previewReport): ?>
document.addEventListener('DOMContentLoaded', function () {
    var pm = document.getElementById('previewModal');
    if (pm) new bootstrap.Modal(pm).show();
});
<?php endif; ?>

<?php if ($feeWaiverPreviewRows && $feeWaiverPreviewReport): ?>
document.addEventListener('DOMContentLoaded', function () {
    var fwpm = document.getElementById('feeWaiverPreviewModal');
    if (fwpm) new bootstrap.Modal(fwpm).show();
});
<?php endif; ?>

// ── Restricted Products ────────────────────────────────────────────────────
const RP_API = '<?= ADMIN_URL ?>/api/restricted_products.php';

async function rpToggle(id, newActive) {
    const row = document.getElementById('rp-card-' + id);
    if (!row) return;

    const banning = Number(newActive) === 1;
    const nameCell = row.querySelector('td strong');
    const name = (nameCell ? nameCell.textContent : '').trim() || 'this item';

    if (!await gpConfirm({
            title: banning ? 'Ban this product again?' : 'Lift this ban?',
            body: banning
                ? 'Merchants will be stopped from listing it, and each attempt adds a strike toward suspending the stall.'
                : 'Merchants will be free to list it again. Existing strikes are not removed.',
            detail: [['Product', name.trim()]],
            confirmLabel: banning ? 'Ban product' : 'Lift ban',
            danger: banning,
        })) return;

    const data = await gpFetchJson(RP_API, {
        action: 'toggle_restriction',
        id: id,
        is_active: newActive,
    });
    if (data.success) {
        const badge = row.querySelector('.rp-status-badge');
        badge.className = 'rp-status-badge ' + (newActive ? 'rp-status--banned' : 'rp-status--lifted');
        badge.textContent = newActive ? 'BANNED' : 'LIFTED';
        const btn = row.querySelector('.rp-toggle-btn');
        if (newActive) {
            btn.className = 'rp-toggle-btn rp-toggle-btn--ban';
            btn.innerHTML = '<i class="fa-solid fa-lock-open me-1"></i>Lift Ban';
            btn.setAttribute('onclick', `rpToggle(${id}, 0)`);
        } else {
            btn.className = 'rp-toggle-btn rp-toggle-btn--lift';
            btn.innerHTML = '<i class="fa-solid fa-ban me-1"></i>Reinstate';
            btn.setAttribute('onclick', `rpToggle(${id}, 1)`);
        }
        gpToast(banning ? 'Product banned.' : 'Ban lifted.');
    } else {
        gpToast(data.message || 'The product could not be updated.', false);
    }
}

async function rpRemove(id, name) {
    if (!await gpConfirm({
            title: 'Remove this from the prohibited list?',
            body: 'The entry is deleted outright. To keep the record but stop blocking, lift the ban instead.',
            detail: [['Product', name]],
            note: 'This cannot be undone.',
            confirmLabel: 'Remove permanently',
            danger: true,
        })) return;

    const data = await gpFetchJson(RP_API, {
        action: 'delete_restriction',
        id: id,
    });
    if (data.success) {
        const row = document.getElementById('rp-card-' + id);
        if (row) {
            $('#rp-datatable').DataTable().row(row).remove().draw();
        }
        rpUpdateCount(-1);
        gpToast('Removed from the prohibited list.');
    } else {
        gpToast(data.message || 'The entry could not be removed.', false);
    }
}

async function rpFlagProduct() {
    const name      = document.getElementById('rp-name').value.trim();
    const category  = document.getElementById('rp-category').value;
    const matchType = document.getElementById('rp-match-type').value;
    const reason    = document.getElementById('rp-reason').value.trim();
    const alertEl   = document.getElementById('rp-modal-alert');
    const btn       = document.getElementById('rp-flag-btn');

    if (!name || !reason) {
        alertEl.innerHTML = '<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">Product name and reason are required.</div>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Flagging…';
    alertEl.innerHTML = '';

    const f = new FormData();
    f.append('action',       'flag_product');
    f.append('product_name', name);
    f.append('category',     category);
    f.append('match_type',   matchType);
    f.append('reason',       reason);

    try {
        const res  = await fetch(RP_API, { method: 'POST', body: f });
        const data = await res.json();
        if (data.success) {
            alertEl.innerHTML = '<div class="rp-modal-alert" style="background:var(--gp-success-bg);color:#27764b"><i class="fa-solid fa-circle-check me-1"></i>Product flagged successfully.</div>';
            document.getElementById('rp-name').value   = '';
            document.getElementById('rp-reason').value = '';
            rpInjectCard({ product_name: name, category, match_type: matchType, reason, is_active: 1 });
            rpUpdateCount(1);
        } else {
            alertEl.innerHTML = `<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">${data.message || 'Failed.'}</div>`;
        }
    } catch {
        alertEl.innerHTML = '<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">Network error.</div>';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-ban me-1"></i> Flag This Product';
}

function rpInjectCard(rp) {
    const matchTag = rp.match_type === 'exact'
        ? '<span class="rp-tag rp-tag--match-exact">Exact match</span>'
        : '<span class="rp-tag rp-tag--match-contains">Contains</span>';

    const $row = $('<tr>');
    $row.append($('<td>').html('<strong>' + $('<span>').text(rp.product_name).html() + '</strong>'));
    $row.append($('<td style="text-transform:capitalize">').text(rp.category));
    $row.append($('<td>').html(matchTag));
    $row.append($('<td style="max-width:260px;font-size:13px">').text(rp.reason));
    $row.append($('<td>').html('<span class="rp-status-badge rp-status--banned">BANNED</span>'));
    $row.append($('<td>').html('<span style="font-size:11px;color:var(--gp-subtle);font-style:italic">Reload to manage</span>'));

    $('#rp-datatable').DataTable().row.add($row[0]).draw();
}

function rpUpdateCount(delta) {
    const badge = document.getElementById('rp-count');
    if (!badge) return;
    const match = badge.textContent.match(/\d+/);
    const current = match ? parseInt(match[0]) : 0;
    const next = Math.max(0, current + delta);
    badge.textContent = next + ' item' + (next !== 1 ? 's' : '');
}

// ── Fee Waiver Credits ───────────────────────────────────────────────────────
const FWC_API = '<?= ADMIN_URL ?>/api/fee_waiver_credits.php';
const FWC_DOC_BASE = '<?= ADMIN_URL ?>/doc.php?f=';

function fwcOpenAmountModal(studentId) {
    document.getElementById('fwc-amount-student-id').value = studentId;
    document.getElementById('fwc-amount-input').value = '';
    document.getElementById('fwc-amount-alert').innerHTML = '';
    document.getElementById('fwc-amount-btn').disabled = false;
    new bootstrap.Modal(document.getElementById('fwcAmountModal')).show();
}

async function fwcSubmitAmount() {
    const studentId = document.getElementById('fwc-amount-student-id').value;
    const amount = parseFloat(document.getElementById('fwc-amount-input').value);
    const alertEl = document.getElementById('fwc-amount-alert');
    const btn = document.getElementById('fwc-amount-btn');
    if (!amount || amount <= 0) {
        alertEl.innerHTML = '<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">Enter a valid amount.</div>';
        return;
    }
    btn.disabled = true;
    const f = new FormData();
    f.append('action', 'set_amount');
    f.append('student_user_id', studentId);
    f.append('amount', amount.toFixed(2));
    try {
        const res = await fetch(FWC_API, { method: 'POST', body: f });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alertEl.innerHTML = `<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">${data.message || 'Failed.'}</div>`;
            btn.disabled = false;
        }
    } catch {
        alertEl.innerHTML = '<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">Network error.</div>';
        btn.disabled = false;
    }
}

function fwcOpenUploadModal(studentId) {
    document.getElementById('fwc-upload-student-id').value = studentId;
    document.getElementById('fwc-upload-input').value = '';
    document.getElementById('fwc-upload-alert').innerHTML = '';
    document.getElementById('fwc-upload-btn').disabled = false;
    document.getElementById('fwc-upload-blank-link').href = '<?= ADMIN_URL ?>/print_fee_waiver.php?student_user_id=' + studentId;
    new bootstrap.Modal(document.getElementById('fwcUploadModal')).show();
}

async function fwcSubmitUpload() {
    const studentId = document.getElementById('fwc-upload-student-id').value;
    const fileInput = document.getElementById('fwc-upload-input');
    const alertEl = document.getElementById('fwc-upload-alert');
    const btn = document.getElementById('fwc-upload-btn');
    if (!fileInput.files.length) {
        alertEl.innerHTML = '<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">Choose a file to upload.</div>';
        return;
    }
    btn.disabled = true;
    const f = new FormData();
    f.append('action', 'upload_waiver');
    f.append('student_user_id', studentId);
    f.append('waiver', fileInput.files[0]);
    try {
        const res = await fetch(FWC_API, { method: 'POST', body: f });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alertEl.innerHTML = `<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">${data.message || 'Failed.'}</div>`;
            btn.disabled = false;
        }
    } catch {
        alertEl.innerHTML = '<div class="rp-modal-alert" style="background:var(--gp-danger-bg);color:var(--gp-danger)">Network error.</div>';
        btn.disabled = false;
    }
}

async function fwcCancel(studentId) {
    if (!await gpConfirm({
            title: 'Cancel this fee waiver credit?',
            body: 'The pending credit is cleared and the student goes back to having no waiver on file.',
            note: 'You can create a new waiver credit afterwards if this was a mistake.',
            confirmLabel: 'Cancel credit',
            danger: true,
        })) return;

    const data = await gpFetchJson(FWC_API, {
        action: 'cancel',
        student_user_id: studentId,
    });

    if (data.success) {
        gpToast('Fee waiver credit cancelled.');
        setTimeout(() => location.reload(), 900);
        return;
    }
    gpToast(data.message || 'The credit could not be cancelled.', false);
}

// Show the signed waiver inline in a modal instead of opening a new tab/window.
function gjcViewWaiver(url) {
    document.getElementById('gjcWaiverFrame').src = url;
    new bootstrap.Modal(document.getElementById('gjcWaiverModal')).show();
    return false;
}
document.getElementById('gjcWaiverModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('gjcWaiverFrame').src = '';
});

async function fwcOpenDetailModal(studentId) {
    const summaryEl = document.getElementById('fwc-detail-summary');
    const logsEl = document.getElementById('fwc-detail-logs');
    const linkEl = document.getElementById('fwc-detail-waiver-link');
    summaryEl.innerHTML = '<p class="text-muted">Loading…</p>';
    logsEl.innerHTML = '';
    linkEl.style.display = 'none';
    new bootstrap.Modal(document.getElementById('fwcDetailModal')).show();

    const f = new FormData();
    f.append('action', 'detail');
    f.append('student_user_id', studentId);
    try {
        const res = await fetch(FWC_API, { method: 'POST', body: f });
        const data = await res.json();
        if (!data.success) {
            summaryEl.innerHTML = `<p class="text-danger">${data.message || 'Failed to load.'}</p>`;
            return;
        }
        const c = data.credit;
        summaryEl.innerHTML = `
            <div class="import-summary-card"><span>Status</span><strong>${c.status.charAt(0).toUpperCase() + c.status.slice(1)}</strong></div>
            <div class="import-summary-card"><span>Amount</span><strong>${c.amount !== null ? '₱' + Number(c.amount).toFixed(2) : '—'}</strong></div>
        `;
        if (c.status === 'posted' && c.waiver_file) {
            linkEl.href = FWC_DOC_BASE + encodeURIComponent(c.waiver_file);
            linkEl.style.display = 'inline-flex';
        }
        if (!data.logs.length) {
            logsEl.innerHTML = '<tr><td colspan="4" class="text-muted text-center">No transitions recorded yet.</td></tr>';
        } else {
            logsEl.innerHTML = data.logs.map(function (log) {
                const when = new Date(log.changed_at.replace(' ', 'T')).toLocaleString();
                const amt = log.amount !== null ? '₱' + Number(log.amount).toFixed(2) : '—';
                return `<tr><td style="font-size:12px">${when}</td><td>${log.old_status} → ${log.new_status}</td><td>${amt}</td><td>${log.changed_by_role}</td></tr>`;
            }).join('');
        }
    } catch {
        summaryEl.innerHTML = '<p class="text-danger">Network error.</p>';
    }
}

// ── Fixed table header: glued to the viewport once the real thead scrolls
// past it, instead of scrolling away with the table. A CSS "sticky" thead
// doesn't work here (Bootstrap's .table-responsive forces overflow-y: auto,
// which hijacks the sticky positioning context), so this measures the real
// header cells and mirrors them into a position:fixed bar.
function rpInitFixedHeader(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const headerRow = table.querySelector('thead tr');
    if (!headerRow) return;

    const bar = document.createElement('div');
    bar.className = 'rp-fixed-header-bar';
    Array.from(headerRow.children).forEach(function (th) {
        const cell = document.createElement('div');
        cell.className = 'rp-fhb-cell';
        cell.textContent = th.textContent.trim();
        bar.appendChild(cell);
    });
    document.body.appendChild(bar);
    const barCells = Array.from(bar.children);

    function reposition() {
        const rect = table.getBoundingClientRect();
        const headerHeight = headerRow.getBoundingClientRect().height || 40;
        const shouldShow = rect.top < 0 && rect.bottom > headerHeight;

        if (!shouldShow) {
            bar.style.display = 'none';
            return;
        }

        bar.style.display = 'flex';
        bar.style.left = rect.left + 'px';
        bar.style.width = rect.width + 'px';
        bar.style.height = headerHeight + 'px';

        Array.from(headerRow.children).forEach(function (th, i) {
            const w = th.getBoundingClientRect().width;
            barCells[i].style.width = w + 'px';
            barCells[i].style.minWidth = w + 'px';
            barCells[i].style.maxWidth = w + 'px';
        });
    }

    let ticking = false;
    function onScrollOrResize() {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () {
            reposition();
            ticking = false;
        });
    }

    window.addEventListener('scroll', onScrollOrResize, { passive: true });
    window.addEventListener('resize', onScrollOrResize);

    // Column widths can change on sort/redraw even while scroll position
    // doesn't — re-measure then too, not just on scroll.
    if (window.jQuery && jQuery.fn.DataTable && jQuery.fn.DataTable.isDataTable('#' + tableId)) {
        jQuery('#' + tableId).on('draw.dt order.dt', function () {
            setTimeout(reposition, 0);
        });
    }

    reposition();
}

rpInitFixedHeader('fwc-datatable');
</script>
</body>
</html>
