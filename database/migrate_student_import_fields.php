<?php
// Apply with: php database/migrate_student_import_fields.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/student_import_schema.php';
gjc_ensure_student_import_fields($db);
echo "Student import fields applied to " . $db->query('SELECT DATABASE()')->fetchColumn() . ".\n";
