<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/MintingGuard.php';
require_once __DIR__ . '/../connection/CirculationEngine.php';

gjc_require_role(['finance']);
$currentUser = gjc_current_user($db);

$currentPage = 'settings';

// ── Read-only economy figures (panel 5) ──────────────────────────────────
// Wrapped: a settings page must still render if the economy tables hiccup.
$mintReport = null;
$snapshot   = null;
try {
    $mintReport = (new MintingGuard($db))->getMonthlyMintingReport();
    $snapshot   = (new CirculationEngine($db))->getCirculationSnapshot();
} catch (Throwable) {
}

// ── System information (real values, not hardcoded strings) ──────────────
$dbName = '(unknown)';
try {
    $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable) {
}
$serverTime = date('M d, Y g:i:s A');
$qrLibPath  = BASE_PATH . '/vendor/phpqrcode/qrlib.php';
$qrLibFound = is_file($qrLibPath);

$settings = gjc_settings_all($db);

// Mint PIN panel is only actionable for super-admins (MintingGuard enforces the
// same check server-side); everyone else sees why it's unavailable.
$isSuperAdmin = gjc_sub_role() === 'super_admin';
$hasMintPin = false;
try {
    $pinStmt = $db->prepare("SELECT mint_pin FROM users WHERE userID = ? LIMIT 1");
    $pinStmt->execute([(int) $currentUser['id']]);
    $hasMintPin = trim((string) $pinStmt->fetchColumn()) !== '';
} catch (Throwable) {
    // Column missing on an un-migrated DB — treat as unset.
}

$flash = $_SESSION['gjc_settings_flash'] ?? null;
unset($_SESSION['gjc_settings_flash']);

$csrf = gjc_csrf_token();
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Arrow fns capture by value, so $settings is bound here.
$amt = fn(string $key): string => number_format((float) ($settings[$key] ?? 0), 2, '.', '');
$whole = fn(string $key): string => (string) (int) round((float) ($settings[$key] ?? 0));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= CSS_URL ?>/settings.css?v=16">
    <link rel="stylesheet" href="<?= CSS_URL ?>/responsive.css?v=2">

    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
</head>

<body class="gp-theme">

    <div class="admin-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>

        <main class="admin-main settings-page">

            <header class="topbar">
                <button class="menu-btn" aria-label="Toggle navigation" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>

                <div>
                    <h1>System Settings</h1>
                    <p>Configure financial controls and payment gateway options.</p>
                </div>

                <div class="admin-user">
                    <span><?= gjc_e($currentUser['name']) ?></span>
                    <div class="avatar">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                </div>
            </header>

            <?php if ($flash): ?>
            <div class="settings-flash is-<?= $h($flash['type']) ?>">
                <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : ($flash['type'] === 'error' ? 'fa-circle-exclamation' : 'fa-circle-info') ?>"></i>
                <span><?= $h($flash['message']) ?></span>
            </div>
            <?php endif; ?>

            <div class="settings-tabs" role="tablist" aria-label="Settings sections">
                <button type="button" class="settings-tab" role="tab"
                        id="tab-mintpin" data-tab="mintpin" aria-controls="mintpin"
                        aria-selected="false" tabindex="-1">Mint PIN</button>
                <button type="button" class="settings-tab" role="tab"
                        id="tab-limits" data-tab="limits" aria-controls="limits"
                        aria-selected="false" tabindex="-1">Money Limits</button>
                <button type="button" class="settings-tab" role="tab"
                        id="tab-merchant" data-tab="merchant" aria-controls="merchant"
                        aria-selected="false" tabindex="-1">Merchant Strikes</button>
                <button type="button" class="settings-tab" role="tab"
                        id="tab-waiver" data-tab="waiver" aria-controls="waiver"
                        aria-selected="false" tabindex="-1">Fee Waivers</button>
                <button type="button" class="settings-tab" role="tab"
                        id="tab-maintenance" data-tab="maintenance" aria-controls="maintenance"
                        aria-selected="false" tabindex="-1">Maintenance Fee</button>
                <span class="settings-tabs-split" aria-hidden="true"></span>
                <button type="button" class="settings-tab" role="tab"
                        id="tab-info" data-tab="info" aria-controls="info"
                        aria-selected="false" tabindex="-1">Info</button>
            </div>

            <section class="settings-panel settings-pane" id="mintpin" role="tabpanel"
                     aria-labelledby="tab-mintpin">

                <div class="settings-panel-header">
                    <h3>
                        <i class="fa-solid fa-shield-halved"></i>
                        Security
                    </h3>
                    <span class="settings-status <?= $hasMintPin ? 'is-set' : 'is-unset' ?>">
                        <i class="fa-solid <?= $hasMintPin ? 'fa-lock' : 'fa-lock-open' ?>"></i>
                        <?= $hasMintPin ? 'Mint PIN is set' : 'No Mint PIN set' ?>
                    </span>
                </div>

                <?php if ($isSuperAdmin): ?>
                <form action="<?= ADMIN_URL ?>/save_settings.php" method="POST" class="settings-form" autocomplete="off">
                    <input type="hidden" name="action" value="set_mint_pin">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

                    <h4>Mint PIN — <?= $h($currentUser['name']) ?></h4>

                    <div class="settings-note" style="margin-top:0">
                        Minting above <strong><?= gjc_money(MintingGuard::SOFT_LIMIT) ?> per month</strong> requires this
                        PIN. Without one set, any mint that crosses the soft limit is refused outright.
                        The hard ceiling of <?= gjc_money(MintingGuard::HARD_LIMIT) ?>/month cannot be overridden by PIN.
                    </div>

                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>New Mint PIN</label>
                            <small>4 to 12 digits</small>
                            <input type="password" name="new_pin" inputmode="numeric" pattern="\d{4,12}"
                                   maxlength="12" autocomplete="new-password" required>
                        </div>

                        <div class="settings-field">
                            <label>Confirm Mint PIN</label>
                            <small>Re-enter to confirm</small>
                            <input type="password" name="confirm_pin" inputmode="numeric" pattern="\d{4,12}"
                                   maxlength="12" autocomplete="new-password" required>
                        </div>

                        <div class="settings-field">
                            <label>Your Account Password</label>
                            <small>Confirms it's really you</small>
                            <input type="password" name="current_password" autocomplete="current-password" required>
                        </div>
                    </div>

                    <button type="submit" class="save-settings-btn">
                        <?= $hasMintPin ? 'Change Mint PIN' : 'Set Mint PIN' ?>
                    </button>
                </form>
                <?php else: ?>
                <div class="settings-form">
                    <div class="settings-note" style="margin-top:0">
                        The Mint PIN can only be set by a super-admin account. Your account is signed in as
                        <strong><?= $h(gjc_sub_role()) ?></strong>.
                    </div>
                </div>
                <?php endif; ?>

            </section>

            <section class="settings-panel settings-pane" id="limits" role="tabpanel"
                     aria-labelledby="tab-limits">

                <div class="settings-panel-header">
                    <h3>
                        <i class="fa-solid fa-sliders"></i>
                        Transaction Limits
                    </h3>
                </div>

                <form action="<?= ADMIN_URL ?>/save_settings.php" method="POST" class="settings-form">
                    <input type="hidden" name="action" value="save_limits">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

                    <h4>Student Transfers</h4>

                    <div class="settings-grid">
                        <div class="settings-field money-field">
                            <label>Daily Transfer Limit</label>
                            <small>Total a student may send per day</small>
                            <div class="input-with-prefix">
                                <span>₱</span>
                                <input type="number" name="transfer_daily_limit" step="0.01" min="1"
                                       value="<?= $h($amt('transfer_daily_limit')) ?>" required>
                            </div>
                        </div>

                        <div class="settings-field money-field">
                            <label>Minimum Transfer</label>
                            <small>Smallest single transfer allowed</small>
                            <div class="input-with-prefix">
                                <span>₱</span>
                                <input type="number" name="transfer_min_amount" step="0.01" min="0.01"
                                       value="<?= $h($amt('transfer_min_amount')) ?>" required>
                            </div>
                        </div>
                    </div>

                    <h4>Top-ups</h4>

                    <div class="settings-grid">
                        <div class="settings-field money-field">
                            <label>Max Top-Up Per Request</label>
                            <small>Ceiling on a single top-up</small>
                            <div class="input-with-prefix">
                                <span>₱</span>
                                <input type="number" name="topup_max_per_request" step="0.01" min="1"
                                       value="<?= $h($amt('topup_max_per_request')) ?>" required>
                            </div>
                        </div>
                    </div>

                    <h4>New Student Wallets</h4>

                    <div class="settings-grid">
                        <div class="settings-field money-field">
                            <label>Default Daily Spend Cap</label>
                            <small>0 = no cap. Parents can still override per student.</small>
                            <div class="input-with-prefix">
                                <span>₱</span>
                                <input type="number" name="wallet_default_spend_cap" step="0.01" min="0"
                                       value="<?= $h($amt('wallet_default_spend_cap')) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="settings-note">
                        These limits are enforced live on student transfers and top-ups.
                        Changing them takes effect on the next transaction — transactions already recorded are untouched.
                    </div>

                    <button type="submit" class="save-settings-btn">
                        Save Limits
                    </button>
                </form>

            </section>

            <section class="settings-panel settings-pane" id="merchant" role="tabpanel"
                     aria-labelledby="tab-merchant">

                <div class="settings-panel-header">
                    <h3>
                        <i class="fa-solid fa-store-slash"></i>
                        Merchant Policy
                    </h3>
                </div>

                <form action="<?= ADMIN_URL ?>/save_settings.php" method="POST" class="settings-form">
                    <input type="hidden" name="action" value="save_merchant_policy">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

                    <h4>Restricted Product Strikes</h4>

                    <div class="settings-note" style="margin-top:0">
                        A strike is one blocked attempt to list a restricted product. Reaching the suspension
                        threshold locks the <strong>entire stall</strong> — owner login, staff logins, and student
                        purchases — then resets the count to zero. Finance can lift a live suspension early from
                        the <a href="<?= ADMIN_URL ?>/restricted_products.php">Restricted Products</a> page.
                    </div>

                    <div class="settings-grid">
                        <div class="settings-field">
                            <label>Warn at Strike</label>
                            <small>Merchant gets a heads-up notification</small>
                            <input type="number" name="violation_warn_at" step="1" min="1" max="50"
                                   value="<?= $h($whole('violation_warn_at')) ?>" required>
                        </div>

                        <div class="settings-field">
                            <label>Suspend at Strike</label>
                            <small>Must be higher than the warning strike</small>
                            <input type="number" name="violation_risk_at" step="1" min="1" max="50"
                                   value="<?= $h($whole('violation_risk_at')) ?>" required>
                        </div>

                        <div class="settings-field">
                            <label>Suspension Length</label>
                            <small>In days</small>
                            <input type="number" name="violation_suspend_days" step="1" min="1" max="365"
                                   value="<?= $h($whole('violation_suspend_days')) ?>" required>
                        </div>
                    </div>

                    <button type="submit" class="save-settings-btn">
                        Save Merchant Policy
                    </button>
                </form>

            </section>

            <section class="settings-panel settings-pane" id="waiver" role="tabpanel"
                     aria-labelledby="tab-waiver">

                <div class="settings-panel-header">
                    <h3>
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                        Fee Waiver Credits
                    </h3>
                </div>

                <form action="<?= ADMIN_URL ?>/save_settings.php" method="POST" class="settings-form">
                    <input type="hidden" name="action" value="save_fee_waiver">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

                    <h4>Issuing Limit</h4>

                    <div class="settings-grid">
                        <div class="settings-field money-field">
                            <label>Maximum Waiver Amount</label>
                            <small>Ceiling on a single student's credit</small>
                            <div class="input-with-prefix">
                                <span>₱</span>
                                <input type="number" name="fee_waiver_max_amount" step="0.01" min="0.01"
                                       value="<?= $h($amt('fee_waiver_max_amount')) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="settings-note">
                        Fee waiver credits are a tuition-assessment line, not wallet money — they never touch
                        GenCoin or the circulation cap. This cap bounds both the
                        <a href="<?= ADMIN_URL ?>/maintenance.php">Maintenance</a> entry form and the API that
                        records the amount.
                    </div>

                    <button type="submit" class="save-settings-btn">
                        Save Waiver Limit
                    </button>
                </form>

            </section>

            <section class="settings-panel settings-pane" id="maintenance" role="tabpanel"
                     aria-labelledby="tab-maintenance">

                <div class="settings-panel-header">
                    <h3>
                        <i class="fa-solid fa-wallet"></i>
                        Maintenance Fee
                    </h3>
                </div>

                <form action="<?= ADMIN_URL ?>/save_settings.php" method="POST" class="settings-form">
                    <input type="hidden" name="action" value="save_maintenance_fee">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

                    <h4>Fee Rate Per Transaction</h4>

                    <div class="settings-grid">
                        <div class="settings-field money-field">
                            <label>Fee Rate</label>
                            <small>Percent of each student POS payment or transfer. 0 turns the fee off.</small>
                            <div class="input-with-prefix">
                                <span>%</span>
                                <input type="number" name="maintenance_fee_rate_pct" step="0.01" min="0" max="10"
                                       value="<?= $h($amt('maintenance_fee_rate_pct')) ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="settings-note">
                        The fee applies to every student <strong>POS payment</strong> and every <strong>transfer sent</strong>
                        (the sender is charged). It is never taken from the wallet: each fee is recorded against the student,
                        collected in the <a href="<?= ADMIN_URL ?>/wallet.php">Admin Wallet</a>, and billed with tuition.
                        Returned sales void their fee. Changing the rate only affects future transactions.
                    </div>

                    <?php $feeRate = (float) ($settings['maintenance_fee_rate_pct'] ?? 0); ?>
                    <div class="settings-note">
                        Each fee is the rate &times; the transaction amount, rounded to the centavo, and never less than
                        &#8369;0.01.
                        <?php if ($feeRate > 0): ?>
                        At <strong><?= gjc_fee_rate_label($feeRate) ?></strong>: a &#8369;100.00 purchase or transfer costs
                        <strong><?= gjc_money(max(0.01, round(100 * $feeRate / 100, 2))) ?></strong>, a &#8369;5,000.00 one
                        <strong><?= gjc_money(max(0.01, round(5000 * $feeRate / 100, 2))) ?></strong>.
                        <?php else: ?>
                        The fee is currently <strong>off</strong>.
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="save-settings-btn">
                        Save Maintenance Fee
                    </button>
                </form>

            </section>

            <div class="settings-pane" id="info" role="tabpanel"
                 aria-labelledby="tab-info">

                <section class="settings-panel" id="economy">

                    <div class="settings-panel-header">
                        <h3>
                            <i class="fa-solid fa-coins"></i>
                            Minting &amp; Circulation
                        </h3>
                        <span class="settings-status is-readonly">
                            <i class="fa-solid fa-eye"></i>
                            Read-only
                        </span>
                    </div>

                    <div class="settings-form">
                        <?php if ($mintReport === null || $snapshot === null): ?>
                            <div class="settings-note" style="margin-top:0">
                                Economy figures are unavailable right now. Check the
                                <a href="<?= ADMIN_URL ?>/economy.php">Economy</a> page.
                            </div>
                        <?php else:
                            $minted   = (float) $mintReport['minted_this_month'];
                            $soft     = (float) $mintReport['soft_limit'];
                            $hard     = (float) $mintReport['hard_limit'];
                            $pct      = max(0, min(100, (float) $mintReport['soft_limit_used_pct']));
                            $drift    = (float) ($snapshot['circulation_drift'] ?? 0);
                            $barState = $minted >= $soft ? 'is-over' : ($pct >= 75 ? 'is-near' : 'is-ok');
                        ?>
                        <h4>This Month's Minting</h4>

                        <div class="mint-meter <?= $barState ?>">
                            <div class="mint-meter-bar"><span style="width:<?= number_format($pct, 1, '.', '') ?>%"></span></div>
                            <div class="mint-meter-labels">
                                <strong><?= gjc_money($minted) ?> minted</strong>
                                <span>of <?= gjc_money($soft) ?> soft limit (<?= number_format($pct, 1) ?>%)</span>
                            </div>
                        </div>

                        <div class="settings-note" style="margin-top:14px">
                            <?php if ($minted >= $hard): ?>
                                The hard ceiling of <?= gjc_money($hard) ?>/month is reached — further minting is refused outright.
                            <?php elseif ($minted >= $soft): ?>
                                Past the soft limit. Further mints this month require the Mint PIN
                                <?= $hasMintPin ? 'set above' : '— which is <strong>not set yet</strong>, so they will be refused' ?>.
                            <?php else: ?>
                                Mints up to <?= gjc_money($soft) ?>/month need only a super-admin and a written reason.
                                Above that, the Mint PIN is required<?= $hasMintPin ? '' : ' — and none is set yet' ?>.
                                The hard ceiling is <?= gjc_money($hard) ?>/month.
                            <?php endif; ?>
                        </div>

                        <h4 style="margin-top:26px">Money Supply</h4>

                        <div class="system-info-list">
                            <div class="system-info-row">
                                <span>Circulation Cap</span>
                                <strong><?= gjc_money((float) ($snapshot['cap'] ?? 0)) ?></strong>
                            </div>
                            <div class="system-info-row">
                                <span>Cashier Vault</span>
                                <div>
                                    <strong><?= gjc_money((float) ($snapshot['vault'] ?? 0)) ?></strong>
                                    <small>Minted but not yet distributed.</small>
                                </div>
                            </div>
                            <div class="system-info-row">
                                <span>Wallet Pools</span>
                                <div>
                                    <strong><?= gjc_money(
                                        (float) ($snapshot['student_wallets_total'] ?? 0)
                                        + (float) ($snapshot['merchant_wallets_total'] ?? 0)
                                        + (float) ($snapshot['parent_wallets_total'] ?? 0)
                                    ) ?></strong>
                                    <small>Students <?= gjc_money((float) ($snapshot['student_wallets_total'] ?? 0)) ?>
                                         · Merchants <?= gjc_money((float) ($snapshot['merchant_wallets_total'] ?? 0)) ?>
                                         · Parents <?= gjc_money((float) ($snapshot['parent_wallets_total'] ?? 0)) ?></small>
                                </div>
                            </div>
                            <div class="system-info-row">
                                <span>Total in Circulation</span>
                                <strong><?= gjc_money((float) ($snapshot['total_in_circulation'] ?? 0)) ?></strong>
                            </div>
                            <div class="system-info-row">
                                <span>Drift</span>
                                <div>
                                    <strong class="<?= abs($drift) >= 0.01 ? 'warning-tag' : '' ?>"><?= gjc_money($drift) ?></strong>
                                    <small><?= abs($drift) < 0.01
                                        ? 'Balanced — the closed loop adds up.'
                                        : 'Non-zero drift means the loop does not balance. Investigate before minting.' ?></small>
                                </div>
                            </div>
                            <div class="system-info-row">
                                <span>Mint Events This Month</span>
                                <strong><?= (int) $mintReport['mint_events'] ?></strong>
                            </div>
                        </div>

                        <div class="settings-note">
                            Minting itself lives on the <a href="<?= ADMIN_URL ?>/economy.php">Economy</a> page —
                            these figures are shown here only so the limits above have context.
                        </div>
                        <?php endif; ?>
                    </div>

                </section>

                <section class="settings-panel" id="fees">

                    <div class="settings-panel-header">
                        <h3>
                            <i class="fa-solid fa-percent"></i>
                            Fee Structure
                        </h3>
                        <span class="settings-status is-readonly">
                            <i class="fa-solid fa-eye"></i>
                            Read-only
                        </span>
                    </div>

                    <div class="settings-form">
                        <?php
                        $sysRate    = CirculationEngine::FEE_SYSTEM_RATE;
                        $merRate    = CirculationEngine::FEE_MERCHANT_RATE;
                        $example    = 1000.00;
                        $exMerchant = CirculationEngine::feeBreakdown($example, true);
                        $exFinance  = CirculationEngine::feeBreakdown($example, false);
                        $exSys      = $exMerchant['system_fee'];
                        $exMer      = $exMerchant['merchant_fee'];
                        $exTotal    = $exMerchant['total'];
                        $exFinTotal = $exFinance['total'];
                        ?>

                        <h4>Top-up Fees — added on top</h4>

                        <div class="settings-note" style="margin-top:0">
                            Fees are <strong>added to</strong> the requested amount, never deducted from it. The
                            recipient is credited exactly what was entered; the payer hands over that plus the fees.
                        </div>

                        <div class="system-info-list">
                            <div class="system-info-row">
                                <span>Finance Fee</span>
                                <div>
                                    <strong><?= CirculationEngine::ratePct($sysRate) ?></strong>
                                    <small>School revenue, settles into the vault. Charged on every top-up route.</small>
                                </div>
                            </div>
                            <div class="system-info-row">
                                <span>Merchant Fee</span>
                                <div>
                                    <strong><?= CirculationEngine::ratePct($merRate) ?></strong>
                                    <small>Paid to the merchant wallet. Merchant-route top-ups only.</small>
                                </div>
                            </div>
                            <div class="system-info-row">
                                <span>Example — <?= gjc_money($example) ?> via merchant</span>
                                <div>
                                    <strong><?= gjc_money($example) ?> credited, <?= gjc_money($exTotal) ?> collected</strong>
                                    <small><?= gjc_money($exSys) ?> to vault, <?= gjc_money($exMer) ?> to the merchant.</small>
                                </div>
                            </div>
                            <div class="system-info-row">
                                <span>Example — <?= gjc_money($example) ?> via finance</span>
                                <div>
                                    <strong><?= gjc_money($example) ?> credited, <?= gjc_money($exFinTotal) ?> collected</strong>
                                    <small><?= gjc_money($exSys) ?> to vault. No merchant cut on the cashier route.</small>
                                </div>
                            </div>
                        </div>

                        <div class="settings-note">
                            Rates live in <code>CirculationEngine</code> and every fee label in the app is derived
                            from them, so a displayed percentage can never drift from what is actually charged.
                            They stay read-only here because changing a rate is a money decision, not a settings tweak.
                        </div>
                    </div>

                </section>

                <section class="settings-panel" id="system">

                    <div class="settings-panel-header">
                        <h3>
                            <i class="fa-solid fa-circle-info"></i>
                            System Information
                        </h3>
                    </div>

                    <div class="system-info-list">

                        <div class="system-info-row">
                            <span>Application</span>
                            <strong>GenPay v<?= $h(GJC_APP_VERSION) ?></strong>
                        </div>

                        <div class="system-info-row">
                            <span>Base URL</span>
                            <strong><?= $h(BASE_URL) ?></strong>
                        </div>

                        <div class="system-info-row">
                            <span>Database</span>
                            <strong><?= $h($dbName) ?></strong>
                        </div>

                        <div class="system-info-row">
                            <span>PHP Version</span>
                            <strong><?= $h(PHP_VERSION) ?></strong>
                        </div>

                        <div class="system-info-row">
                            <span>Server Time</span>
                            <strong><?= $h($serverTime) ?></strong>
                        </div>

                        <div class="system-info-row">
                            <span>QR Library</span>
                            <div>
                                <?php if ($qrLibFound): ?>
                                    <strong>Local (vendor/phpqrcode/qrlib.php)</strong>
                                    <small>Offline generation available.</small>
                                <?php else: ?>
                                    <strong class="warning-tag">Using CDN fallback</strong>
                                    <small>Place qrlib.php at vendor/phpqrcode/qrlib.php for offline generation</small>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>

                </section>

            </div>

        </main>

    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>

    <script>
    function toggleSidebar() {
        document.getElementById("sidebar").classList.toggle("collapsed");
    }

    // ── Section tabs ─────────────────────────────────────────────────────
    // One pane on screen at a time. Each of the four editable sections is its
    // own pane: they are independent forms with their own Save button, and
    // showing them together let an edit in one be silently discarded by a save
    // in another. The three read-only sections carry no form, so they share the
    // Info pane rather than spending three tabs.
    (function () {
        var page = document.querySelector(".settings-page");
        if (!page) return;

        var tabs  = [].slice.call(page.querySelectorAll(".settings-tab"));
        var panes = [].slice.call(page.querySelectorAll(".settings-pane"));
        if (!tabs.length || !panes.length) return;

        // Hiding is CSS-gated on this class, so if anything below throws the
        // page stays a plain stack of open panels rather than a blank column.
        page.classList.add("js-tabs");

        // Resolves a hash to the pane that should open. Accepts a pane id and
        // also a section id inside one, so #economy still lands on Info.
        function paneFor(id) {
            if (!id) return null;
            var el = document.getElementById(id);
            return el ? el.closest(".settings-pane") : null;
        }

        function select(pane, moveFocus) {
            var target = pane || panes[0];

            panes.forEach(function (other) {
                other.classList.toggle("is-active", other === target);
            });

            tabs.forEach(function (tab) {
                var on = tab.getAttribute("data-tab") === target.id;
                tab.classList.toggle("is-active", on);
                tab.setAttribute("aria-selected", on ? "true" : "false");

                // Roving tabindex: one stop for the whole bar, arrows move within.
                tab.tabIndex = on ? 0 : -1;
                if (on && moveFocus) tab.focus();
            });

            // Keep the URL on the open pane so it stays linkable. replaceState
            // rather than a real fragment navigation: that would fire popstate,
            // and the global back-guard in includes/partials/back_to_dashboard.php
            // answers popstate by replacing the page with the dashboard.
            history.replaceState(null, document.title, "#" + target.id);
        }

        var STEP = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };

        tabs.forEach(function (tab, index) {
            tab.addEventListener("click", function () {
                select(paneFor(tab.getAttribute("data-tab")), false);
            });

            tab.addEventListener("keydown", function (event) {
                var next;
                if (event.key === "Home") {
                    next = 0;
                } else if (event.key === "End") {
                    next = tabs.length - 1;
                } else if (STEP[event.key]) {
                    next = (index + STEP[event.key] + tabs.length) % tabs.length;
                } else {
                    return;
                }

                event.preventDefault();
                select(paneFor(tabs[next].getAttribute("data-tab")), true);
            });
        });

        // save_settings.php redirects with the section anchor, so a save reopens
        // the section it came from; anything else opens the first.
        select(paneFor(window.location.hash.slice(1)), false);

        // That anchor also made the browser jump past the flash banner on load.
        window.scrollTo(0, 0);
    })();
    </script>

</body>

</html>
