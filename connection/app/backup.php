<?php

// Backup & restore: one zip per backup in backups/ holding database.sql (mysqldump),
// database_report.pdf (readable copy, never used by restore), the uploaded files and
// manifest.json. The zips themselves are the registry — a DB table would be rolled
// back by the very restore it describes.

const GJC_BACKUP_FILE_DIRS = ['uploads', 'assets/profile_photos', 'assets/merchant_logos'];
const GJC_BACKUP_KEEP_AUTO = 14;
const GJC_BACKUP_NAME_RE = '/^genpay_\d{8}_\d{6}_[a-f0-9]{4}_(manual|auto|pre-restore)\.zip$/';
// A crashed restore must not take the site down forever.
const GJC_BACKUP_LOCK_TTL = 1800;

function gjc_backup_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/backups';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the backups folder.');
    }
    return $dir;
}

/** Full path of a stored backup, or an exception — the name is user input. */
function gjc_backup_path(string $name): string
{
    $name = basename($name);
    if (!preg_match(GJC_BACKUP_NAME_RE, $name)) {
        throw new InvalidArgumentException('Invalid backup name.');
    }
    $path = gjc_backup_dir() . '/' . $name;
    if (!is_file($path)) {
        throw new RuntimeException('Backup not found.');
    }
    return $path;
}

function gjc_backup_lock_path(): string
{
    return dirname(__DIR__, 2) . '/backups/.restore.lock';
}

function gjc_backup_restore_in_progress(): bool
{
    $lock = gjc_backup_lock_path();
    return is_file($lock) && (time() - (int) @filemtime($lock)) < GJC_BACKUP_LOCK_TTL;
}

/** Called from connection/app.php: every other request waits while a restore runs. */
function gjc_backup_block_during_restore(): void
{
    if (PHP_SAPI === 'cli' || !gjc_backup_restore_in_progress()) {
        return;
    }
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'GenPay is being restored from a backup. Please try again in a few minutes.';
    exit;
}

function gjc_backup_log(array $entry): void
{
    $entry = ['at' => date('c')] + $entry;
    @file_put_contents(gjc_backup_dir() . '/backup.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/** mysqldump / mysql: next to the running server first (matching version), then PATH, then Laragon. */
function gjc_backup_binary(PDO $db, string $name): string
{
    $exe = DIRECTORY_SEPARATOR === '\\' ? $name . '.exe' : $name;
    $dirs = [rtrim((string) $db->query('SELECT @@basedir')->fetchColumn(), '/\\') . '/bin'];
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '') $dirs[] = $dir;
    }
    $laragon = glob('C:/laragon/bin/mysql/*/bin') ?: [];
    rsort($laragon);
    foreach (array_merge($dirs, $laragon) as $dir) {
        if (is_file($dir . '/' . $exe)) {
            return $dir . '/' . $exe;
        }
    }
    throw new RuntimeException("Cannot find $name. Install the MySQL client tools or add them to PATH.");
}

/** Runs a MySQL client tool with the app's credentials; the password goes through the environment, never argv. */
function gjc_backup_run(PDO $db, string $tool, array $args, ?string $stdinFile = null): void
{
    $cmd = array_merge([
        gjc_backup_binary($db, $tool),
        '--host=' . gjc_local_config('db_host', 'GJC_DB_HOST'),
        '--port=' . gjc_local_config('db_port', 'GJC_DB_PORT'),
        '--user=' . gjc_local_config('db_user', 'GJC_DB_USER'),
        '--default-character-set=utf8mb4',
    ], $args);
    $env = getenv();
    $env['MYSQL_PWD'] = gjc_local_config('db_pass', 'GJC_DB_PASS');

    $spec = [
        0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $spec, $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        throw new RuntimeException("Could not start $tool.");
    }
    if (isset($pipes[0])) fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException("$tool failed: " . trim(substr((string) $err, 0, 500)));
    }
}

/** Exact row count per base table. */
function gjc_backup_table_counts(PDO $db): array
{
    $counts = [];
    $tables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                           ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $counts[$t] = (int) $db->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $t) . '`')->fetchColumn();
    }
    return $counts;
}

function gjc_backup_circulation(PDO $db): array
{
    require_once dirname(__DIR__) . '/CirculationEngine.php';
    try {
        $s = (new CirculationEngine($db))->getCirculationSnapshot();
        return ['cap' => (float) ($s['cap'] ?? 0), 'drift' => round((float) ($s['circulation_drift'] ?? 0), 2)];
    } catch (Throwable $e) {
        return ['cap' => null, 'drift' => null, 'error' => $e->getMessage()];
    }
}

/**
 * Creates a backup zip and returns its manifest.
 * $label: manual | auto | pre-restore. The PDF is skipped for pre-restore safety copies (speed).
 */
function gjc_backup_create(PDO $db, string $label, int $userId, string $userName): array
{
    if (!in_array($label, ['manual', 'auto', 'pre-restore'], true)) {
        throw new InvalidArgumentException('Invalid backup label.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is not enabled.');
    }
    @set_time_limit(0);
    ignore_user_abort(true);

    $dir = gjc_backup_dir();
    $name = sprintf('genpay_%s_%s_%s.zip', date('Ymd_His'), bin2hex(random_bytes(2)), $label);
    $work = $dir . '/.tmp_' . bin2hex(random_bytes(6));
    if (!mkdir($work, 0750)) {
        throw new RuntimeException('Cannot create a temporary folder in backups/.');
    }

    try {
        $raw = $work . '/raw.sql';
        $sql = $work . '/database.sql';
        gjc_backup_run($db, 'mysqldump', [
            '--single-transaction', '--routines', '--triggers', '--hex-blob', '--no-tablespaces',
            '--result-file=' . $raw,
            gjc_local_config('db_name', 'GJC_DB_NAME'),
        ]);
        gjc_backup_strip_definers($raw, $sql);
        unlink($raw);

        $manifest = [
            'format'       => 1,
            'name'         => $name,
            'label'        => $label,
            'created_at'   => date('c'),
            'created_by'   => ['id' => $userId, 'name' => $userName],
            'database'     => (string) $db->query('SELECT DATABASE()')->fetchColumn(),
            'mysql'        => (string) $db->query('SELECT VERSION()')->fetchColumn(),
            'sql_sha256'   => hash_file('sha256', $sql),
            'tables'       => gjc_backup_table_counts($db),
            'circulation'  => gjc_backup_circulation($db),
            'audit_last_hash' => gjc_backup_audit_last_hash($db),
            'pdf'          => 'skipped',
            'files'        => 0,
        ];

        $pdf = $work . '/database_report.pdf';
        if ($label !== 'pre-restore') {
            // A missing PDF must never cost a good SQL dump.
            try {
                require_once __DIR__ . '/backup_pdf.php';
                gjc_backup_build_pdf($db, $pdf, $manifest);
                $manifest['pdf'] = 'ok';
            } catch (Throwable $e) {
                $manifest['pdf'] = 'failed: ' . $e->getMessage();
                error_log('[backup] PDF report failed: ' . $e->getMessage());
            }
        }

        $zip = new ZipArchive();
        $tmpZip = $work . '/' . $name;
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the backup zip.');
        }
        $zip->addFile($sql, 'database.sql');
        if ($manifest['pdf'] === 'ok') {
            $zip->addFile($pdf, 'database_report.pdf');
        }
        $root = dirname(__DIR__, 2);
        foreach (GJC_BACKUP_FILE_DIRS as $rel) {
            if (!is_dir("$root/$rel")) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$rel", FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $entry = 'files/' . $rel . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen("$root/$rel") + 1));
                    $zip->addFile($file->getPathname(), $entry);
                    $manifest['files']++;
                }
            }
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!$zip->close()) {
            throw new RuntimeException('Writing the backup zip failed (disk full?).');
        }
        rename($tmpZip, "$dir/$name");
        $manifest['size'] = filesize("$dir/$name");
        return $manifest;
    } finally {
        gjc_backup_rmdir($work);
    }
}

/** DEFINER=`root`@`localhost` on triggers/views would break a restore under any other MySQL user. */
function gjc_backup_strip_definers(string $in, string $out): void
{
    $r = fopen($in, 'rb');
    $w = fopen($out, 'wb');
    while (($line = fgets($r)) !== false) {
        if (str_contains($line, 'DEFINER=')) {
            $line = preg_replace('/\/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*\/\s?/', '', $line);
            $line = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s?/', '', $line);
        }
        fwrite($w, $line);
    }
    fclose($r);
    fclose($w);
}

function gjc_backup_audit_last_hash(PDO $db): ?string
{
    try {
        $h = $db->query('SELECT entry_hash FROM systemic_audit_trail WHERE entry_hash IS NOT NULL ORDER BY log_id DESC LIMIT 1')->fetchColumn();
        return $h === false ? null : (string) $h;
    } catch (Throwable $e) {
        return null;
    }
}

function gjc_backup_read_manifest(string $path): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) return null;
    $json = $zip->getFromName('manifest.json');
    $zip->close();
    $m = $json === false ? null : json_decode($json, true);
    return is_array($m) ? $m : null;
}

/** Newest first. */
function gjc_backup_list(): array
{
    $out = [];
    foreach (glob(gjc_backup_dir() . '/genpay_*.zip') ?: [] as $path) {
        if (!preg_match(GJC_BACKUP_NAME_RE, basename($path))) continue;
        $m = gjc_backup_read_manifest($path) ?? [];
        $out[] = [
            'name'       => basename($path),
            'label'      => $m['label'] ?? '?',
            'created_at' => $m['created_at'] ?? date('c', (int) filemtime($path)),
            'created_by' => $m['created_by']['name'] ?? '',
            'size'       => (int) filesize($path),
            'tables'     => count($m['tables'] ?? []),
            'rows'       => array_sum($m['tables'] ?? []),
            'files'      => (int) ($m['files'] ?? 0),
            'pdf'        => $m['pdf'] ?? 'skipped',
            'valid'      => $m !== [],
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

/** Keeps the newest GJC_BACKUP_KEEP_AUTO automatic backups; manual and pre-restore ones are never pruned. */
function gjc_backup_prune(): array
{
    $auto = array_values(array_filter(gjc_backup_list(), fn($b) => str_ends_with($b['name'], '_auto.zip')));
    $removed = [];
    foreach (array_slice($auto, GJC_BACKUP_KEEP_AUTO) as $b) {
        if (@unlink(gjc_backup_dir() . '/' . $b['name'])) $removed[] = $b['name'];
    }
    return $removed;
}

/**
 * Replaces the database and uploaded files with the contents of a backup.
 * Takes a pre-restore safety backup first; if the SQL import fails, that copy is
 * re-imported automatically so the database is never left empty.
 */
function gjc_backup_restore(PDO $db, string $name, int $userId, string $userName): array
{
    $path = gjc_backup_path($name);
    @set_time_limit(0);
    ignore_user_abort(true);

    $manifest = gjc_backup_read_manifest($path);
    if (!$manifest) {
        throw new RuntimeException('This backup has no readable manifest.');
    }

    $dir = gjc_backup_dir();
    $work = $dir . '/.tmp_' . bin2hex(random_bytes(6));
    mkdir($work, 0750);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        gjc_backup_rmdir($work);
        throw new RuntimeException('Cannot open the backup zip.');
    }

    $lock = gjc_backup_lock_path();
    if (gjc_backup_restore_in_progress() || @file_put_contents($lock, (string) $userId, LOCK_EX) === false) {
        $zip->close();
        gjc_backup_rmdir($work);
        throw new RuntimeException('Another restore is already running.');
    }

    try {
        // Extract and verify before touching anything live.
        if (!$zip->extractTo($work)) {
            throw new RuntimeException('Extracting the backup failed.');
        }
        $zip->close();
        $sql = $work . '/database.sql';
        if (!is_file($sql) || !hash_equals((string) ($manifest['sql_sha256'] ?? ''), hash_file('sha256', $sql))) {
            throw new RuntimeException('The backup failed its integrity check (database.sql does not match the manifest). Nothing was changed.');
        }

        $safety = gjc_backup_create($db, 'pre-restore', $userId, $userName);
        $safetySql = $work . '/safety.sql';
        $sz = new ZipArchive();
        $sz->open($dir . '/' . $safety['name'], ZipArchive::RDONLY);
        file_put_contents($safetySql, $sz->getFromName('database.sql'));
        $sz->close();

        try {
            gjc_backup_import($db, $sql);
        } catch (Throwable $e) {
            gjc_backup_import($db, $safetySql);
            throw new RuntimeException('Restore failed and the database was put back as it was. ' . $e->getMessage());
        }

        // Live folders are parked outside $work and deleted only after a clean swap,
        // so a failed swap can never take them down with the temp folder.
        $parked = $dir . '/.old_files_' . bin2hex(random_bytes(6));
        mkdir($parked, 0750);
        gjc_backup_swap_files($work . '/files', $parked);
        gjc_backup_rmdir($parked);

        $checks = gjc_backup_post_checks($db, $manifest);
        return ['restored' => $name, 'safety_backup' => $safety['name'], 'checks' => $checks];
    } finally {
        @unlink($lock);
        gjc_backup_rmdir($work);
    }
}

/** Drops every table and view (so tables created after the backup don't linger), then imports. */
function gjc_backup_import(PDO $db, string $sqlFile): void
{
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    $rows = $db->query("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll();
    foreach ($rows as $r) {
        $q = '`' . str_replace('`', '``', $r['TABLE_NAME']) . '`';
        $db->exec(($r['TABLE_TYPE'] === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $q);
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    gjc_backup_run($db, 'mysql', [gjc_local_config('db_name', 'GJC_DB_NAME')], $sqlFile);
}

/**
 * Moves each live folder aside into $work and the backup's copy in. On any failure the
 * moved-aside folders are put back (anything that can't be is left in $work for manual recovery).
 */
function gjc_backup_swap_files(string $from, string $work): void
{
    $root = dirname(__DIR__, 2);
    $moved = [];
    try {
        foreach (GJC_BACKUP_FILE_DIRS as $i => $rel) {
            $live = "$root/$rel";
            $old = "$work/old_$i";
            $new = "$from/$rel";
            if (is_dir($live)) {
                if (!@rename($live, $old)) {
                    throw new RuntimeException("Cannot replace $rel (a file in it may be open).");
                }
                $moved[] = [$live, $old];
            } else {
                $moved[] = [$live, null];
            }
            $ok = is_dir($new) ? @rename($new, $live) : @mkdir($live, 0755, true);
            if (!$ok) {
                throw new RuntimeException("Cannot put the backup copy of $rel in place.");
            }
            // uploads/.htaccess (Deny from all) is not recreated by the app — never lose it.
            if (is_file("$old/.htaccess") && !is_file("$live/.htaccess")) {
                copy("$old/.htaccess", "$live/.htaccess");
            }
        }
    } catch (Throwable $e) {
        foreach (array_reverse($moved) as [$live, $old]) {
            gjc_backup_rmdir($live); // the backup's copy — still safe inside the zip
            if ($old !== null) @rename($old, $live);
        }
        throw new RuntimeException('The database was restored, but uploaded files were left as they were: ' . $e->getMessage());
    }
}

function gjc_backup_post_checks(PDO $db, array $manifest): array
{
    $mismatch = [];
    $now = gjc_backup_table_counts($db);
    foreach ($manifest['tables'] ?? [] as $t => $n) {
        if (($now[$t] ?? -1) !== (int) $n) $mismatch[] = $t;
    }
    $circ = gjc_backup_circulation($db);
    $chain = function_exists('gjc_audit_verify_chain') ? gjc_audit_verify_chain() : ['ok' => null];
    return [
        'row_counts_ok'  => $mismatch === [],
        'row_mismatch'   => $mismatch,
        'circulation_ok' => $circ['drift'] !== null && abs($circ['drift']) < 0.01,
        'drift'          => $circ['drift'],
        'audit_chain_ok' => $chain['ok'],
        'audit_chain_reason' => $chain['reason'] ?? null,
    ];
}

function gjc_backup_rmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
