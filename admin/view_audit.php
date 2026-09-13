<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['finance']);
// Same restriction as admin/audit_log.php — a single entry can carry as much
// personal detail as the list does.
if (gjc_sub_role() !== 'super_admin') {
    http_response_code(403);
    $currentUser = gjc_current_user($db);
    require __DIR__ . '/../includes/partials/audit_forbidden.php';
    exit;
}

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
                UNIX_TIMESTAMP(sat.timestamp) AS ts_epoch,
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

/**
 * Render one side of a before/after pair, marking the keys that differ.
 * Reading two flat tables side by side and spotting the one changed field by
 * eye is exactly the job a diff should be doing.
 */
$auditDiff = static function (?string $json, ?string $otherJson, string $side) use ($esc): string {
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

    $other = json_decode(trim((string) $otherJson), true);
    $other = is_array($other) ? $other : [];

    $flat = static fn($v): string => is_scalar($v) || $v === null
        ? (string) $v
        : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $rows = '';
    foreach ($data as $k => $v) {
        $mine = $flat($v);
        $class = '';
        if (!array_key_exists($k, $other)) {
            $class = $side === 'after' ? ' uv-diff-added' : ' uv-diff-removed';
        } elseif ($flat($other[$k]) !== $mine) {
            $class = ' uv-diff-changed';
        }
        $rows .= '<tr class="' . trim($class) . '"><th>' . $esc(ucwords(str_replace('_', ' ', (string) $k))) . '</th>'
            . '<td>' . $esc($mine) . '</td></tr>';
    }

    return '<div class="table-responsive"><table class="table align-middle uv-kv uv-diff"><tbody>' . $rows . '</tbody></table></div>';
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
    // A NULL actor is meaningful, not missing: a pre-auth login attempt, or a
    // CLI/system event with no session behind it.
    if ($actorName !== '') {
        $actor = $actorName;
    } elseif ($actorEmail !== '') {
        $actor = $actorEmail;
    } elseif ($log['user_id'] === null) {
        $actor = ($log['user_role'] ?? '') === 'System' ? 'System (no session)' : 'Anonymous (not signed in)';
    } else {
        $actor = 'User #' . (int) $log['user_id'];
    }
    $role = trim((string) ($log['user_role'] ?? ''));
    $subRole = trim((string) ($log['sub_role'] ?? ''));
    $table = gjc_audit_table_label($log['affected_table'] ?? '');
    $ip = trim((string) ($log['ip_address'] ?? ''));
    $targetId = trim((string) ($log['target_id'] ?? ''));
    $outcome = (string) ($log['outcome'] ?? 'success');
    $reasonText = trim((string) ($log['reason'] ?? ''));
    $correlationId = trim((string) ($log['correlation_id'] ?? ''));
    $endpoint = trim((string) ($log['endpoint'] ?? ''));
    $httpMethod = trim((string) ($log['http_method'] ?? ''));
    $sessionRef = trim((string) ($log['session_ref'] ?? ''));
    $isLegacy = gjc_audit_is_legacy_row($log);
    $recordedAt = gjc_audit_manila(
        isset($log['ts_epoch']) ? (int) $log['ts_epoch'] : null,
        'M d, Y · g:i A'
    );

    $iconMap = [
        'LOGIN' => 'fa-right-to-bracket', 'LOGOUT' => 'fa-right-from-bracket',
        'PASSWORD_CHANGE' => 'fa-key', 'TRANSACTION' => 'fa-receipt',
        'MENU_MUTATION' => 'fa-utensils', 'STALL_UPDATE' => 'fa-store',
        'MERCHANT_PROFILE_UPDATE' => 'fa-id-card',
        'USER_IMPORT' => 'fa-file-import', 'MERCHANT_CREATE' => 'fa-store',
        'USER_ACCOUNT' => 'fa-user-gear', 'MERCHANT_ONBOARDING' => 'fa-store',
        'PRODUCT_RESTRICTION' => 'fa-ban', 'LOGIN_FAILED' => 'fa-triangle-exclamation',
        'TUITION_CREDIT' => 'fa-hand-holding-dollar', 'FEE_WAIVER_STATUS_CHANGE' => 'fa-hand-holding-dollar',
        'SCHOOL_YEAR_CREATED' => 'fa-calendar-plus', 'SCHOOL_YEAR_ROLLOVER' => 'fa-calendar-check',
        'STUDENT_GRADUATED' => 'fa-user-graduate', 'SY_TXN_BACKFILL' => 'fa-database',
        'SYSTEM_SETTINGS_CHANGE' => 'fa-sliders', 'MINT_PIN_SET' => 'fa-lock',
        'ENROLLMENT_STATUS_SET' => 'fa-user-check', 'ROSTER_UPLOADED' => 'fa-file-arrow-up',
        'ROSTER_APPLIED' => 'fa-list-check', 'LOGIN_BLOCKED' => 'fa-user-lock',
        'ACCESS_DENIED' => 'fa-shield-halved', 'MINT_ATTEMPT' => 'fa-coins',
        'PARENT_CONTROL' => 'fa-sliders', 'PARENT_LINK' => 'fa-link',
        'DOCUMENT_VIEW' => 'fa-file-lines', 'DATA_EXPORT' => 'fa-file-csv',
        'MIGRATION_REDACTION' => 'fa-eraser',
    ];
    $icon = $iconMap[$action] ?? 'fa-clipboard-list';

    // The other entries this request wrote. Most requests write exactly one, so
    // the card only renders when there is something to relate this entry to —
    // it used to appear on every entry and link to a list holding only itself.
    $relatedEntries = [];
    if ($correlationId !== '') {
        $relStmt = $db->prepare(
            "SELECT log_id, action_type, affected_table, target_id, outcome,
                    UNIX_TIMESTAMP(timestamp) AS ts_epoch
               FROM systemic_audit_trail
              WHERE correlation_id = ? AND log_id <> ?
              ORDER BY log_id
              LIMIT 50"
        );
        $relStmt->execute([$correlationId, (int) $log['log_id']]);
        $relatedEntries = $relStmt->fetchAll(PDO::FETCH_ASSOC);
    }

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
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=37">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/detail_view.css?v=13">
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
                        <span class="audit-outcome is-<?= $esc($outcome) ?>"><?= $esc($outcome) ?></span>
                        <?php if ($isLegacy): ?>
                        <span class="audit-legacy" title="Written before the hash chain existed — this entry cannot be cryptographically verified.">legacy (unchained)</span>
                        <?php endif; ?>
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
                $field('fa-solid fa-user-shield', 'Sub-role', $subRole !== '' ? $subRole : '—');
                $field('fa-solid fa-bolt', 'Action', $actionLabel);
                $field('fa-solid fa-circle-check', 'Outcome', ucfirst($outcome));
                $field('fa-regular fa-clock', 'Recorded At', $recordedAt . ' (Asia/Manila)');
                $field('fa-solid fa-table', 'Affected Table', $table !== '' ? $table : '—');
                $field('fa-solid fa-crosshairs', 'Target Record', $targetId !== '' ? $targetId : '—', true);
                $field('fa-solid fa-comment-dots', 'Reason', $reasonText !== '' ? $reasonText : '—');
                $field('fa-solid fa-network-wired', 'IP Address', $ip !== '' ? $ip : '—', true);
                $field('fa-solid fa-link', 'Endpoint', ($httpMethod !== '' ? $httpMethod . ' ' : '') . ($endpoint !== '' ? $endpoint : '—'), true);
                $field('fa-solid fa-fingerprint', 'Session', $sessionRef !== '' ? $sessionRef : '—', true);
                $field('fa-solid fa-hashtag', 'Log ID', (string) (int) $log['log_id'], true);
                ?>
            </div>
        </section>

        <?php if ($relatedEntries): ?>
        <section class="gp-card uv-card uv-related">
            <div class="gp-card-head">
                <div>
                    <h3>Related entries</h3>
                    <p>The same action wrote <?= count($relatedEntries) ?> other entr<?= count($relatedEntries) === 1 ? 'y' : 'ies' ?>; they share a correlation ID.</p>
                </div>
            </div>
            <div class="uv-fields">
                <?php $field('fa-solid fa-diagram-project', 'Correlation ID', $correlationId, true); ?>
            </div>
            <ul class="uv-item-list uv-related-list">
                <?php foreach ($relatedEntries as $rel): ?>
                <?php
                $relOutcome = (string) ($rel['outcome'] ?? 'success');
                $relTarget = trim((string) ($rel['target_id'] ?? ''));
                $relTable = gjc_audit_table_label($rel['affected_table'] ?? '');
                ?>
                <li class="uv-item">
                    <span class="uv-item-qty">#<?= (int) $rel['log_id'] ?></span>
                    <span class="uv-item-name">
                        <?= $esc(ucwords(strtolower(str_replace('_', ' ', (string) $rel['action_type'])))) ?>
                        <small>
                            <?= $esc($relTable !== '' ? $relTable : '—') ?><?= $relTarget !== '' ? ' · ' . $esc($relTarget) : '' ?>
                            · <?= $esc(gjc_audit_manila(isset($rel['ts_epoch']) ? (int) $rel['ts_epoch'] : null, 'M d, Y · g:i:s A')) ?>
                        </small>
                    </span>
                    <span class="uv-related-side">
                        <span class="audit-outcome is-<?= $esc($relOutcome) ?>"><?= $esc($relOutcome) ?></span>
                        <a class="uv-related-link"
                           href="<?= $esc(ADMIN_URL) ?>/view_audit.php?token=<?= urlencode(gjc_make_view_token((int) $rel['log_id'], 'audit')) ?>">
                            View <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="uv-related-actions">
                <a class="gp-btn gp-btn--outline"
                   href="<?= $esc(ADMIN_URL) ?>/audit_log.php?correlation_id=<?= urlencode($correlationId) ?>">
                    <i class="fa-solid fa-list"></i> Open all <?= count($relatedEntries) + 1 ?> in the Audit Log
                </a>
            </div>
        </section>
        <?php endif; ?>

        <section class="gp-card uv-card">
            <div class="gp-card-head">
                <div><h3>Integrity</h3><p>Each entry is hashed together with the one before it.</p></div>
            </div>
            <div class="uv-fields">
                <?php
                if ($isLegacy) {
                    $field('fa-solid fa-clock-rotate-left', 'Chain status',
                        'Legacy entry — written before the hash chain existed, so it cannot be verified.');
                } else {
                    $field('fa-solid fa-shield-halved', 'Chain status', 'Chained — covered by the tamper check.');
                    $field('fa-solid fa-fingerprint', 'Entry hash', (string) $log['entry_hash'], true);
                    $field('fa-solid fa-link', 'Previous hash',
                        $log['prev_hash'] !== null ? (string) $log['prev_hash'] : 'None — first chained entry.', true);
                }
                ?>
            </div>
        </section>

        <?php if ($hasOld || $hasNew): ?>
        <?php if ($hasOld && $hasNew): ?>
        <div class="uv-two">
            <section class="gp-card uv-card">
                <div class="gp-card-head"><div><h3>Before</h3><p>Prior recorded state. Changed fields are highlighted.</p></div></div>
                <?= $auditDiff($old, $new, 'before') ?>
            </section>
            <section class="gp-card uv-card">
                <div class="gp-card-head"><div><h3>After</h3><p>New recorded state. Changed fields are highlighted.</p></div></div>
                <?= $auditDiff($new, $old, 'after') ?>
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
