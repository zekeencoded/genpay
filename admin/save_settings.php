<?php

/**
 * Settings write handler for admin/settings.php.
 *
 * Plain form POST + redirect-with-flash (not fetch/JSON) — a settings page has
 * no partial-update UI to keep in sync, and a redirect makes a double-submit
 * harmless. Two actions: `save_limits` and `set_mint_pin`.
 */

require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/audit_logger.php';
require_once __DIR__ . '/../connection/MintingGuard.php';

gjc_require_role(['finance']);

/** Stash a flash message and bounce back to the settings page. */
function settings_redirect(string $type, string $message, string $anchor = ''): never
{
    $_SESSION['gjc_settings_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . ADMIN_URL . '/settings.php' . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    settings_redirect('error', 'Invalid request.');
}

if (!gjc_csrf_verify()) {
    settings_redirect('error', 'Security check failed. Please reload the page and try again.');
}

$userId = gjc_user_id();
$action = trim((string) ($_POST['action'] ?? ''));

try {
    // ── Panel 2: Transaction limits ──────────────────────────────────────
    if ($action === 'save_limits') {
        // key => [label, min, max]. Bounds are sanity rails, not policy.
        $fields = [
            'transfer_daily_limit'     => ['Daily transfer limit',            1.00, 1000000.00],
            'transfer_min_amount'      => ['Minimum transfer amount',         0.01, 100000.00],
            'topup_max_per_request'    => ['Max top-up per request',          1.00, 1000000.00],
            'wallet_default_spend_cap' => ['Default daily spend cap',         0.00, 1000000.00],
        ];

        $before  = gjc_settings_all($db);
        $updates = [];

        foreach ($fields as $key => [$label, $min, $max]) {
            $raw = $_POST[$key] ?? null;
            if ($raw === null || trim((string) $raw) === '') {
                settings_redirect('error', "{$label} is required.", 'limits');
            }

            $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
            if ($value === false) {
                settings_redirect('error', "{$label} must be a number.", 'limits');
            }

            $value = round((float) $value, 2);
            if ($value < $min || $value > $max) {
                settings_redirect('error', sprintf(
                    '%s must be between %s and %s.',
                    $label,
                    number_format($min, 2),
                    number_format($max, 2)
                ), 'limits');
            }

            $updates[$key] = $value;
        }

        // Cross-field sanity: a minimum above the daily ceiling would make every
        // transfer impossible — the two fields are individually valid but the
        // combination is not.
        if ($updates['transfer_min_amount'] > $updates['transfer_daily_limit']) {
            settings_redirect(
                'error',
                'Minimum transfer amount cannot exceed the daily transfer limit — no transfer would ever be possible.',
                'limits'
            );
        }

        $changed = [];
        foreach ($updates as $key => $value) {
            if (abs(((float) ($before[$key] ?? 0)) - $value) >= 0.005) {
                $changed[$key] = ['from' => (float) ($before[$key] ?? 0), 'to' => $value];
            }
            gjc_setting_set($db, $key, $value, $userId);
        }

        if ($changed === []) {
            settings_redirect('info', 'No changes — the limits already match those values.', 'limits');
        }

        logAudit(
            $db,
            $userId,
            'finance',
            'SYSTEM_SETTINGS_CHANGE',
            'app_settings',
            array_map(static fn(array $c): float => $c['from'], $changed),
            array_map(static fn(array $c): float => $c['to'], $changed),
            null,
            ['target_id' => null]
        );

        settings_redirect('success', count($changed) === 1
            ? '1 limit updated.'
            : count($changed) . ' limits updated.', 'limits');
    }

    // ── Panel 3: Merchant restricted-product policy ──────────────────────
    // ── Panel 4: Fee waiver cap ──────────────────────────────────────────
    // Both are plain numeric groups, so they share one validator; the two forms
    // differ only in which keys they submit.
    if (in_array($action, ['save_merchant_policy', 'save_fee_waiver', 'save_maintenance_fee'], true)) {
        $groups = [
            'save_maintenance_fee' => [
                'anchor' => 'maintenance',
                'fields' => [
                    'maintenance_fee_rate_pct' => ['Maintenance fee rate (%)', 0.00, 10.00, false],
                ],
            ],
            'save_merchant_policy' => [
                'anchor' => 'merchant',
                'fields' => [
                    // key => [label, min, max, whole-number?]
                    'violation_warn_at'      => ['Warn at strike', 1, 50, true],
                    'violation_risk_at'      => ['Suspend at strike', 1, 50, true],
                    'violation_suspend_days' => ['Suspension length (days)', 1, 365, true],
                ],
            ],
            'save_fee_waiver' => [
                'anchor' => 'waiver',
                'fields' => [
                    'fee_waiver_max_amount' => ['Maximum waiver amount', 0.01, 1000000.00, false],
                ],
            ],
        ];

        $anchor = $groups[$action]['anchor'];
        $fields = $groups[$action]['fields'];
        $before = gjc_settings_all($db);
        $updates = [];

        foreach ($fields as $key => [$label, $min, $max, $isWhole]) {
            $raw = $_POST[$key] ?? null;
            if ($raw === null || trim((string) $raw) === '') {
                settings_redirect('error', "{$label} is required.", $anchor);
            }

            $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
            if ($value === false) {
                settings_redirect('error', "{$label} must be a number.", $anchor);
            }

            if ($isWhole && floor((float) $value) != (float) $value) {
                settings_redirect('error', "{$label} must be a whole number.", $anchor);
            }

            $value = $isWhole ? (float) (int) $value : round((float) $value, 2);
            if ($value < $min || $value > $max) {
                settings_redirect('error', sprintf(
                    '%s must be between %s and %s.',
                    $label,
                    $isWhole ? (string) (int) $min : number_format((float) $min, 2),
                    $isWhole ? (string) (int) $max : number_format((float) $max, 2)
                ), $anchor);
            }

            $updates[$key] = $value;
        }

        // Cross-field: a warn threshold at or above the suspend threshold means
        // the warning can never fire — suspension lands first, with no notice.
        if ($action === 'save_merchant_policy'
            && $updates['violation_warn_at'] >= $updates['violation_risk_at']) {
            settings_redirect(
                'error',
                'The warning strike must be lower than the suspension strike — otherwise merchants get suspended with no warning first.',
                $anchor
            );
        }

        $changed = [];
        foreach ($updates as $key => $value) {
            if (abs(((float) ($before[$key] ?? 0)) - $value) >= 0.005) {
                $changed[$key] = ['from' => (float) ($before[$key] ?? 0), 'to' => $value];
            }
            gjc_setting_set($db, $key, $value, $userId);
        }

        if ($changed === []) {
            settings_redirect('info', 'No changes — those values are already in effect.', $anchor);
        }

        logAudit(
            $db,
            $userId,
            'finance',
            'SYSTEM_SETTINGS_CHANGE',
            'app_settings',
            array_map(static fn(array $c): float => $c['from'], $changed),
            array_map(static fn(array $c): float => $c['to'], $changed),
            null,
            ['target_id' => null]
        );

        settings_redirect('success', count($changed) === 1
            ? '1 setting updated.'
            : count($changed) . ' settings updated.', $anchor);
    }

    // ── Panel 1: Mint PIN ────────────────────────────────────────────────
    if ($action === 'set_mint_pin') {
        $newPin          = (string) ($_POST['new_pin'] ?? '');
        $confirmPin      = (string) ($_POST['confirm_pin'] ?? '');
        $currentPassword = (string) ($_POST['current_password'] ?? '');

        if ($newPin === '' || $confirmPin === '' || $currentPassword === '') {
            settings_redirect('error', 'All three fields are required to set a Mint PIN.', 'mintpin');
        }
        if ($newPin !== $confirmPin) {
            settings_redirect('error', 'The two PIN entries do not match.', 'mintpin');
        }
        if (!preg_match('/^\d{4,12}$/', $newPin)) {
            settings_redirect('error', 'The Mint PIN must be 4 to 12 digits.', 'mintpin');
        }

        // MintingGuard re-checks sub_role, PIN length, and the password itself,
        // and throws with a specific code on any failure.
        $guard = new MintingGuard($db);
        $guard->setMintPin($userId, $newPin, $currentPassword);

        // Never log the PIN or the password — only that a change happened.
        logAudit($db, $userId, 'finance', 'MINT_PIN_SET', 'users', null, ['user_id' => $userId], null, ['target_id' => $userId]);

        settings_redirect('success', 'Mint PIN saved. You can now authorize mints above the monthly soft limit.', 'mintpin');
    }

    settings_redirect('error', 'Unknown action.');
} catch (RuntimeException $e) {
    // MintingGuard messages are prefixed with a machine code (INVALID_PASSWORD:,
    // ACCESS_DENIED:, ...). Strip it for display; the audit trail keeps the rest.
    $message = $e->getMessage();
    if (preg_match('/^[A-Z_]+:\s*(.+)$/s', $message, $m)) {
        $message = $m[1];
    }
    settings_redirect('error', $message, match ($action) {
        'set_mint_pin'          => 'mintpin',
        'save_merchant_policy'  => 'merchant',
        'save_fee_waiver'       => 'waiver',
        'save_maintenance_fee'  => 'maintenance',
        default                => 'limits',
    });
} catch (Throwable $e) {
    error_log('[save_settings.php] ' . $e->getMessage());
    settings_redirect('error', 'A server error occurred. Nothing was saved.');
}
