<?php

/** Import history keeps the submitted values; live profiles use their canonical tables. */
function gjc_student_import_history_columns(): array
{
    return [
        'middle_name' => 'VARCHAR(100) NULL',
        'suffix' => 'VARCHAR(20) NULL',
        'year_level' => 'VARCHAR(11) NULL',
        'sex' => "ENUM('Male', 'Female', 'Prefer not to say') NULL",
        'date_of_birth' => 'DATE NULL',
        'address_barangay' => 'VARCHAR(120) NULL',
        'address_city' => 'VARCHAR(120) NULL',
        'address_province' => 'VARCHAR(120) NULL',
    ];
}

function gjc_ensure_student_import_fields(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new LogicException('Student import schema must be prepared before importing.');
    }
    gjc_ensure_user_profile_schema($db);
    $fields = [
        'users' => ['middle_name' => 'VARCHAR(100) NULL', 'suffix' => 'VARCHAR(20) NULL'],
        'imported_student_registry' => gjc_student_import_history_columns(),
    ];
    foreach ($fields as $table => $definitions) {
        $read = $db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $read->execute([$table]);
        $existing = $read->fetchAll(PDO::FETCH_COLUMN);
        foreach ($definitions as $column => $definition) {
            if (!in_array($column, $existing, true)) {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }
    gjc_table_columns($db, 'users', true);
}

/** Clean optional CSV values and reject values that would truncate or change meaning. */
function gjc_normalize_student_import_row(array $row): array
{
    foreach (gjc_student_import_history_columns() as $field => $_) {
        $row[$field] = trim((string) ($row[$field] ?? ''));
    }
    $row['email'] = strtolower(trim((string) ($row['email'] ?? '')));
    $row['parent_email'] = strtolower(trim((string) ($row['parent_email'] ?? '')));
    $year = $row['year_level'];
    if ($year === '') {
        $row['year_level'] = '1';
    } elseif (preg_match('/^([1-9]|1[0-2])(?:(?:st|nd|rd|th)?\s+year)?$/i', $year, $match)) {
        $row['year_level'] = $match[1];
    } else {
        throw new InvalidArgumentException('Year level must be 1–12 (for example, 2 or 2nd Year).');
    }
    if ($row['sex'] !== '') {
        $options = array_combine(array_map('strtolower', gjc_sex_options()), gjc_sex_options());
        $sex = $options[strtolower($row['sex'])] ?? null;
        if ($sex === null) {
            throw new InvalidArgumentException('Sex must be Male, Female, or Prefer not to say.');
        }
        $row['sex'] = $sex;
    }
    if ($row['date_of_birth'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $row['date_of_birth']);
        if (!$date || $date->format('Y-m-d') !== $row['date_of_birth']) {
            throw new InvalidArgumentException('Date of birth must be a valid YYYY-MM-DD date.');
        }
    }
    $details = gjc_collect_profile_details($row, ['sex', 'date_of_birth']);
    if ($details['error'] !== null) {
        throw new InvalidArgumentException($details['error']);
    }
    foreach ([
        'student_id_number' => 80, 'first_name' => 100, 'middle_name' => 100,
        'last_name' => 100, 'suffix' => 20, 'course_program' => 255,
        'email' => 255, 'phone_number' => 40, 'parent_name' => 255,
        'parent_email' => 255, 'parent_contact' => 40,
        'address_barangay' => 120, 'address_city' => 120, 'address_province' => 120,
    ] as $field => $limit) {
        if (mb_strlen((string) ($row[$field] ?? ''), 'UTF-8') > $limit) {
            throw new InvalidArgumentException($field . ' must be at most ' . $limit . ' characters.');
        }
    }
    foreach (['phone_number', 'parent_contact'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '' && (!preg_match('/^\+?[0-9 ()-]+$/', $value)
            || !preg_match('/^[0-9]{7,15}$/', preg_replace('/\D/', '', $value)))) {
            throw new InvalidArgumentException($field . ' must contain a valid phone number (7–15 digits).');
        }
    }
    return $row;
}
