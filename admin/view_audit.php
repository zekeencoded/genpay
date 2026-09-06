<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);

/**
 * CRUD "Read": view a single audit-trail entry. Identified by a signed, opaque
 * token in the URL (?token=...) — an HMAC over the log_id, so editing the URL
 * by hand fails verification and falls through to "not found". Read-only.
 */
$logId = gjc_verify_view_token($_GET['token'] ?? null, 'audit');

$log = null;
if ($logId !== null && gjc_table_exists($db, 'systemic_audit_trail')) {
    $stmt = $db->prepare(
        "SELECT sat.*,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS actor_name,
                u.email AS actor_email
           FROM systemic_audit_trail sat
           LEFT JOIN users u ON u.userID = sat.user_id
          WHERE sat.log_id = ? LIMIT 1"
    );
    $stmt->execute([$logId]);
    $log = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$log) {
    http_response_code(404);
}

$esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** Render an audit JSON payload (old_value / new_value) as a key/value table. */
$auditKv = static function (?string $json) use ($esc): string {
    $raw = trim((string) $json);
    if ($raw === '' || strtolower($raw) === 'null') {
        return '<p class="uv-muted">No data recorded.</p>';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return '<p class="uv-kv-plain" style="font-family:var(--gp-mono);word-break:break-word;">' . $esc($raw) . '</p>';
    }
    if ($data === []) {
        return '<p class="uv-muted">No data recorded.</p>';
    }
    $rows = '';
    foreach ($data as $k => $v) {
        $val = is_scalar($v) || $v === null
            ? (string) $v
            : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rows .= '<tr><th>' . $esc(ucwords(str_replace('_', ' ', (string) $k))) . '</th>'
            . '<td>' . $esc($val) . '</td></tr>';
    }
    return '<div class="table-responsive"><table class="table align-middle uv-kv"><tbody>' . $rows . '</tbody></table></div>';
};

$field = static function (string $icon, string $label, string $value, bool $mono = false) use ($esc): void {
    echo '<div class="uv-field"><div class="uv-field-ic"><i class="' . $esc($icon) . '"></i></div><div>'
        . '<div class="uv-field-label">' . $esc($label) . '</div>'
        . '<div class="uv-field-value' . ($mono ? ' is-mono' : '') . '">' . $esc($value) . '</div>'
        . '</div></div>';
};

if ($log) {
    $action = (string) ($log['action_type'] ?? '');
    $actionLabel = ucwords(strtolower(str_replace('_', ' ', $action)));
    if ($actionLabel === '') {
        $actionLabel = 'Audit Event';
    }
    $actorName = trim((string) ($log['actor_name'] ?? ''));
    $actorEmail = trim((string) ($log['actor_email'] ?? ''));
    $actor = $actorName !== '' ? $actorName : ($actorEmail !== '' ? $actorEmail : 'User #' . (int) ($log['user_id'] ?? 0));
    $role = trim((string) ($log['user_role'] ?? ''));
    $table = trim((string) ($log['affected_table'] ?? ''));
    $ip = trim((string) ($log['ip_address'] ?? ''));
    $recordedAt = !empty($log['timestamp'])
        ? date('M d, Y · g:i A', strtotime((string) $log['timestamp']))
        : '—';

    $iconMap = [
        'LOGIN' => 'fa-right-to-bracket', 'LOGOUT' => 'fa-right-from-bracket',
        'PASSWORD_CHANGE' => 'fa-key', 'TRANSACTION' => 'fa-receipt',
        'MENU_MUTATION' => 'fa-utensils', 'STALL_UPDATE' => 'fa-store',
        'USER_IMPORT' => 'fa-file-import', 'MERCHANT_CREATE' => 'fa-store',
        'USER_ACCOUNT' => 'fa-user-gear', 'MERCHANT_ONBOARDING' => 'fa-store',
        'PRODUCT_RESTRICTION' => 'fa-ban', 'LOGIN_FAILED' => 'fa-triangle-exclamation',
        'TUITION_CREDIT' => 'fa-hand-holding-dollar', 'FEE_WAIVER_STATUS_CHANGE' => 'fa-hand-holding-dollar',
        'SCHOOL_YEAR_CREATED' => 'fa-calendar-plus', 'SCHOOL_YEAR_ROLLOVER' => 'fa-calendar-check',
        'STUDENT_GRADUATED' => 'fa-user-graduate', 'SY_TXN_BACKFILL' => 'fa-database',
    ];
    $icon = $iconMap[$action] ?? 'fa-clipboard-list';

    $old = (string) ($log['old_value'] ?? '');
    $new = (string) ($log['new_value'] ?? '');
    $hasOld = trim($old) !== '' && strtolower(trim($old)) !== 'null';
    $hasNew = trim($new) !== '' && strtolower(trim($new)) !== 'null';

    // Credential material is never written to the audit trail, but keep the raw
    // dump honest by showing every stored column.
    $rawRecord = $log;
    unset($rawRecord['actor_name'], $rawRecord['actor_email']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $log ? 'Audit Entry' : 'Content Not Available' ?> | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=35">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=11">
</head>

<body class="gp-theme">
    <div class="uv-wrap">
        <?php if (!$log): ?>
        <!-- Generic not-available state, consistent with the other detail pages. -->
        <div class="uv-notfound">
            <div class="uv-nf-ic"><i class="fa-regular fa-circle-question"></i></div>
            <h1>This page isn't available</h1>
            <p>The link you followed may be broken, or the record may have been removed.</p>
            <a href="<?= ADMIN_URL ?>/audit_log.php" class="gp-btn gp-btn--forest">Go to Audit Log</a>
        </div>
        <?php else: ?>
        <div class="uv-topbar">
            <a class="uv-back" href="<?= ADMIN_URL ?>/audit_log.php">
                <i class="fa-solid fa-arrow-left"></i> Back to Audit Log
            </a>
        </div>

        <section class="uv-hero">
            <div class="uv-hero-id">
                <div class="uv-avatar"><i class="fa-solid <?= $esc($icon) ?>"></i></div>
                <div class="uv-hero-main">
                    <div class="uv-eyebrow">Audit entry · #<?= (int) $log['log_id'] ?></div>
                    <h1 class="uv-name"><?= $esc($actionLabel) ?></h1>
                    <div class="uv-id-line">
                        <?php if ($role !== ''): ?><span class="gp-hero-badge"><?= $esc($role) ?></span><?php endif; ?>
                        <span class="uv-mono"><?= $esc($actor) ?></span>
                        <?php if ($table !== ''): ?><span class="uv-status is-neutral"><?= $esc($table) ?></span><?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div><h3>Event details</h3><p>Who did what, and when.</p></div>
            </div>
            <div class="uv-fields">
                <?php
                $field('fa-solid fa-user', 'Actor', $actor);
                $field('fa-regular fa-envelope', 'Email', $actorEmail !== '' ? $actorEmail : '—');
                $field('fa-solid fa-id-badge', 'Role', $role !== '' ? $role : '—');
                $field('fa-solid fa-bolt', 'Action', $actionLabel);
                $field('fa-regular fa-clock', 'Recorded At', $recordedAt);
                $field('fa-solid fa-table', 'Affected Table', $table !== '' ? $table : '—');
                $field('fa-solid fa-network-wired', 'IP Address', $ip !== '' ? $ip : '—', true);
                $field('fa-solid fa-hashtag', 'Log ID', (string) (int) $log['log_id'], true);
                ?>
            </div>
        </section>

        <?php if ($hasOld || $hasNew): ?>
        <?php if ($hasOld && $hasNew): ?>
        <div class="uv-two">
            <section class="gp-card uv-card">
                <div class="gp-card-head"><div><h3>Before</h3><p>Prior recorded state.</p></div></div>
                <?= $auditKv($old) ?>
            </section>
            <section class="gp-card uv-card">
                <div class="gp-card-head"><div><h3>After</h3><p>New recorded state.</p></div></div>
                <?= $auditKv($new) ?>
            </section>
        </div>
        <?php else: ?>
        <section class="gp-card uv-card">
            <div class="gp-card-head"><div><h3>Record</h3><p>Details captured for this event.</p></div></div>
            <?= $auditKv($hasNew ? $new : $old) ?>
        </section>
        <?php endif; ?>
        <?php endif; ?>

        <details class="uv-raw">
            <summary>
                <span class="uv-raw-lead">
                    <i class="fa-solid fa-table-list"></i> Raw record
                    <small>(<?= count($rawRecord) ?> fields)</small>
                </span>
                <i class="fa-solid fa-chevron-down uv-raw-chev"></i>
            </summary>
            <div class="uv-raw-body table-responsive">
                <table class="table align-middle">
                    <tbody>
                        <?php foreach ($rawRecord as $key => $value): ?>
                        <tr>
                            <th><?= $esc(ucwords(str_replace('_', ' ', (string) $key))) ?></th>
                            <td><?= $esc(is_scalar($value) || $value === null ? (string) $value : json_encode($value)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>
    </div>
</body>

</html>
