<?php

// Nightly automatic backup + pruning (keeps the newest GJC_BACKUP_KEEP_AUTO "auto" backups).
// The app has no cron; register this with Windows Task Scheduler, after school hours:
//
//   schtasks /Create /TN "GenPay nightly backup" /SC DAILY /ST 20:00 /RL HIGHEST
//     /TR "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe C:\laragon\www\genpayV3.3\database\backup_cron.php"
//
// The php.exe used must have the zip and pdo_mysql extensions enabled.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

try {
    $m = gjc_backup_create($db, 'auto', 0, '');
    $removed = gjc_backup_prune();
    logAudit($db, 0, '', 'BACKUP_CREATE', 'backups', null,
        ['size' => $m['size'], 'files' => $m['files'], 'pdf' => $m['pdf'], 'pruned' => $removed],
        null, ['target_id' => $m['name']]);
    gjc_backup_log(['action' => 'create', 'name' => $m['name'], 'user' => 0, 'pdf' => $m['pdf'], 'pruned' => $removed]);
    echo "Backup created: {$m['name']} (" . round($m['size'] / 1048576, 1) . " MB, PDF: {$m['pdf']})\n";
    if ($removed) echo 'Pruned: ' . implode(', ', $removed) . "\n";
} catch (Throwable $e) {
    gjc_backup_log(['action' => 'create_failed', 'user' => 0, 'error' => $e->getMessage()]);
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
