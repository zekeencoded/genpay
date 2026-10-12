<?php
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

gjc_require_role(['student']);
gjc_ensure_user_profile_schema($db);
gjc_enforce_student_lock($db);

$currentUser = gjc_current_user($db);
$wallet = gjc_student_wallet($db, $currentUser['id']);

$notice = '';
if (($_GET['updated'] ?? '') === '1') {
    $notice = 'Profile updated successfully!';
} elseif (($_GET['password_updated'] ?? '') === '1') {
    $notice = 'Password updated successfully!';
}

$rawUser = $currentUser['raw'] ?? [];
$studentName = $currentUser['name'];
$studentInitial = strtoupper(substr($studentName, 0, 1));
$email = (string) ($currentUser['email'] ?? '');
$walletBalance = (float) $wallet['balance'];
$createdAt = (string) ($rawUser['created_at'] ?? '');
$memberSince = $createdAt !== '' ? date('F Y', strtotime($createdAt)) : 'N/A';
$profileImg = (string) ($rawUser['profile_img'] ?? '');
$profilePhotoUrl = ($profileImg !== '') ? (BASE_URL . '/' . ltrim($profileImg, '/')) : '';

// Real school-issued ID (GJC2026-0001); the padded userID is only a fallback
// for accounts that never got a student_info row.
$studentID = 'GJC-' . str_pad((string) $currentUser['id'], 5, '0', STR_PAD_LEFT);
if (gjc_table_exists($db, 'student_info')) {
    $sidStmt = $db->prepare("SELECT studentID FROM student_info WHERE userID = ? LIMIT 1");
    $sidStmt->execute([(int) $currentUser['id']]);
    $realID = trim((string) $sidStmt->fetchColumn());
    if ($realID !== '') {
        $studentID = $realID;
    }
}

// Live wallet freeze status instead of hardcoded text.
$isFrozen = false;
if ($wallet['id'] > 0 && $wallet['source'] === 'student_wallets') {
    $wcStmt = $db->prepare("SELECT is_frozen FROM student_wallets WHERE id = ?");
    $wcStmt->execute([$wallet['id']]);
    if ($wc = $wcStmt->fetch(PDO::FETCH_ASSOC)) {
        $isFrozen = (int) $wc['is_frozen'] === 1;
    }
}
$accountStatus = $isFrozen ? 'Frozen' : ucfirst((string) ($rawUser['status'] ?? 'Active'));
$transactionsEnabled = $wallet['id'] > 0 && !$isFrozen;

// ── Hero figures ──────────────────────────────────────────────────────────
// Today's spending includes completed payments and outgoing peer transfers.
$spentMonth = 0.0;
$purchasesMonth = 0;
$spentToday = 0.0;
if ($wallet['id'] > 0 && gjc_table_exists($db, 'transactions')) {
    $figStmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN transaction_type IN ('payment', 'voucher_payment') THEN amount END), 0) AS spent_month,
            COUNT(CASE WHEN transaction_type IN ('payment', 'voucher_payment') THEN 1 END) AS purchases_month,
            COALESCE(SUM(CASE WHEN transaction_type IN ('payment', 'p2p_transfer') AND DATE(created_at) = CURDATE() THEN amount END), 0) AS spent_today
           FROM transactions
          WHERE student_wallet_id = ?
            AND status = 'completed'
            AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    );
    $figStmt->execute([$wallet['id']]);
    if ($fig = $figStmt->fetch(PDO::FETCH_ASSOC)) {
        $spentMonth = (float) $fig['spent_month'];
        $purchasesMonth = (int) $fig['purchases_month'];
        $spentToday = (float) $fig['spent_today'];
    }
}

// ── Linked guardians ──────────────────────────────────────────────────────
$guardians = [];
if (gjc_table_exists($db, 'parent_student_links') && gjc_table_exists($db, 'parents')) {
    $gStmt = $db->prepare(
        "SELECT u.first_name, u.last_name, psl.linked_at
           FROM parent_student_links psl
           JOIN parents p ON p.id = psl.parent_id
           JOIN users u ON u.userID = p.user_id
          WHERE psl.student_user_id = ?
          ORDER BY psl.linked_at, psl.id"
    );
    $gStmt->execute([(int) $currentUser['id']]);
    $guardians = $gStmt->fetchAll(PDO::FETCH_ASSOC);
}

$waiverCredit = gjc_student_waiver_credit($db, (int) $currentUser['id']);
$waiverPill = ['posted' => 'is-ok', 'pending' => 'is-warn', 'claimed' => 'is-ok'][$waiverCredit['status']] ?? '';
$waiverLabel = in_array($waiverCredit['status'], ['posted', 'claimed'], true)
    ? gjc_gc_amount($waiverCredit['amount']) . ' GC'
    : ($waiverCredit['status'] === 'pending' ? 'Pending' : 'None');

$maintFees = gjc_student_maintenance_fees($db, (int) $currentUser['id']);

// ── Personal details + completeness ───────────────────────────────────────
/** users.contact_number is a BIGINT, so a leading 0 comes back eaten. */
$phoneDigits = preg_replace('/\D+/', '', (string) ($rawUser['contact_number'] ?? ''));
if ($phoneDigits === '0') {
    $phoneDigits = '';
} elseif (strlen($phoneDigits) === 10 && $phoneDigits[0] === '9') {
    $phoneDigits = '0' . $phoneDigits;
}
$sexOnFile = trim((string) ($rawUser['sex'] ?? ''));
$addressOnFile = gjc_format_address($rawUser);
$dobOnFile = $rawUser['date_of_birth'] ?? null;
$dobAge = gjc_age_from_dob($dobOnFile);

$editUrl = STUDENT_URL . '/profile_edit.php';
$profileChecks = [
    ['done' => $profilePhotoUrl !== '', 'label' => 'Add a photo',        'photo' => true],
    ['done' => $phoneDigits !== '',     'label' => 'Add mobile number'],
    ['done' => $sexOnFile !== '',       'label' => 'Add sex'],
    ['done' => $dobAge !== null,        'label' => 'Add date of birth'],
    ['done' => $addressOnFile !== '',   'label' => 'Add address'],
];
$checksDone = count(array_filter($profileChecks, static fn($c) => $c['done']));
$checksTotal = count($profileChecks);
$checksPct = (int) round($checksDone / $checksTotal * 100);

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$csrfToken = gjc_csrf_token();
$currentPage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= ICONS_URL ?>/gp_logo.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | GenPay</title>

    <link rel="stylesheet" href="<?= CSS_URL ?>/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_dashboard.css?v=32">
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=15">
    <link rel="stylesheet" href="<?= CSS_URL ?>/profile_hub.css?v=2">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'My Profile';
            $topbarSubtitle = 'Your account, wallet and security in one place.';
            $topbarAvatarPhotoUrl = $profilePhotoUrl;
            require __DIR__ . '/../includes/partials/topbar_student.php';
            ?>

            <div class="sd-content ph">

                <?php if ($notice): ?>
                <div class="pf-alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <?= $e($notice) ?>
                </div>
                <?php endif; ?>

                <!-- Hero: who you are, and the three figures you check most -->
                <section class="ph-hero" aria-label="Account summary">
                    <div class="ph-hero-top">
                        <div class="ph-avatar" id="avatarCircle">
                            <?php if ($profilePhotoUrl): ?>
                                <img id="avatarImg" src="<?= $e($profilePhotoUrl) ?>" alt="Profile Photo">
                            <?php else: ?>
                                <span id="avatarInitial"><?= $e($studentInitial) ?></span>
                            <?php endif; ?>
                            <label for="photoInput" role="button" tabindex="0" class="ph-avatar-edit" title="Change photo" aria-label="Change photo">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" hidden>
                        </div>

                        <div class="ph-identity">
                            <span class="ph-eyebrow">Student account</span>
                            <h2 class="ph-name"><?= $e($studentName) ?></h2>
                            <p class="ph-meta"><?= $e($studentID) ?> &middot; <?= $e($email) ?> &middot; Member since <?= $e($memberSince) ?></p>
                            <div class="ph-photo-msg" id="photoMsg"></div>
                        </div>

                        <div class="ph-hero-actions">
                            <a href="<?= $e($editUrl) ?>" class="ph-btn-ghost">
                                <i class="fa-solid fa-pen"></i> Edit profile
                            </a>
                        </div>
                    </div>

                    <div class="ph-stats is-odd">
                        <div class="ph-stat">
                            <span class="ph-stat-k">Wallet balance</span>
                            <span class="ph-stat-v"><?= gjc_gc_amount($walletBalance) ?> <small>GC</small></span>
                            <span class="ph-stat-n">&#8776; <?= gjc_money($walletBalance) ?></span>
                        </div>
                        <div class="ph-stat">
                            <span class="ph-stat-k">Spent this month</span>
                            <span class="ph-stat-v"><?= gjc_gc_amount($spentMonth) ?> <small>GC</small></span>
                            <span class="ph-stat-n">&#8776; <?= gjc_money($spentMonth) ?> &middot; <?= $purchasesMonth ?> purchase<?= $purchasesMonth === 1 ? '' : 's' ?> in <?= date('F') ?></span>
                        </div>
                        <div class="ph-stat">
                            <?php if ($isFrozen): ?>
                                <span class="ph-stat-k">Spending today</span>
                                <span class="ph-stat-v">Paused</span>
                                <span class="ph-stat-n">Your wallet is frozen by your guardian</span>
                            <?php else: ?>
                                <span class="ph-stat-k">Spent today</span>
                                <span class="ph-stat-v"><?= gjc_gc_amount($spentToday) ?> <small>GC</small></span>
                                <span class="ph-stat-n">&#8776; <?= gjc_money($spentToday) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <?php if ($checksDone < $checksTotal): ?>
                <!-- Completeness nudge: disappears once everything is on file -->
                <section class="ph-card ph-nudge" aria-label="Profile completeness">
                    <div class="ph-ring" style="--ph-pct: <?= $checksPct ?>%"><span><?= $checksPct ?>%</span></div>
                    <div class="ph-grow">
                        <span class="ph-t">Your profile is <?= $checksDone ?> of <?= $checksTotal ?> complete</span>
                        <span class="ph-s">The Finance Office uses these details to verify you at the counter.</span>
                    </div>
                    <div class="ph-chips">
                        <?php foreach ($profileChecks as $check): if ($check['done']) continue; ?>
                            <?php if (!empty($check['photo'])): ?>
                                <label for="photoInput" role="button" tabindex="0" class="ph-chip"><i class="fa-solid fa-plus"></i><?= $e($check['label']) ?></label>
                            <?php else: ?>
                                <a href="<?= $e($editUrl) ?>" class="ph-chip"><i class="fa-solid fa-plus"></i><?= $e($check['label']) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <div class="ph-grid">
                <div class="ph-col">

                <!-- Personal details (editable from Edit Profile) -->
                <section class="ph-card">
                    <div class="ph-card-head">
                        <div>
                            <h3>Personal details</h3>
                            <p>Your details on record</p>
                        </div>
                        <a class="ph-link" href="<?= $e($editUrl) ?>"><i class="fa-solid fa-pen"></i>Edit</a>
                    </div>

                    <?php
                    $notSet = static fn(): string => 'Not set <a class="ph-link" href="' . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . '">Add</a>';
                    ?>
                    <dl class="ph-dl">
                        <div>
                            <dt>Sex</dt>
                            <dd class="<?= $sexOnFile === '' ? 'is-empty' : '' ?>"><?= $sexOnFile !== '' ? $e($sexOnFile) : $notSet() ?></dd>
                        </div>
                        <div>
                            <dt>Mobile number</dt>
                            <dd class="<?= $phoneDigits === '' ? 'is-empty' : '' ?>"><?= $phoneDigits !== '' ? $e($phoneDigits) : $notSet() ?></dd>
                        </div>
                        <div>
                            <dt>Date of birth</dt>
                            <dd class="<?= $dobAge === null ? 'is-empty' : '' ?>"><?= $dobAge !== null ? $e(gjc_dob_label($dobOnFile)) : $notSet() ?></dd>
                        </div>
                        <div>
                            <dt>Age</dt>
                            <!-- Derived on read, so it is right on every birthday
                                 without anyone having to update it. -->
                            <dd class="<?= $dobAge === null ? 'is-empty' : '' ?>"><?= $dobAge !== null ? $e(gjc_age_label($dobOnFile)) : 'Worked out from date of birth' ?></dd>
                        </div>
                        <div class="is-wide">
                            <dt>Address</dt>
                            <dd class="<?= $addressOnFile === '' ? 'is-empty' : '' ?>"><?= $addressOnFile !== '' ? $e($addressOnFile) : $notSet() ?></dd>
                        </div>
                    </dl>
                </section>

                <!-- Linked guardian(s) -->
                <section class="ph-card">
                    <div class="ph-card-head">
                        <div>
                            <h3>Linked guardian<?= count($guardians) > 1 ? 's' : '' ?></h3>
                            <p>Can send allowance and freeze your wallet</p>
                        </div>
                    </div>
                    <?php if ($guardians): ?>
                        <?php foreach ($guardians as $g):
                            $gName = trim(($g['first_name'] ?? '') . ' ' . ($g['last_name'] ?? '')) ?: 'Guardian'; ?>
                        <div class="ph-row">
                            <span class="ph-ini"><?= $e(strtoupper(substr($gName, 0, 1))) ?></span>
                            <span class="ph-grow">
                                <span class="ph-t"><?= $e($gName) ?></span>
                                <span class="ph-s">Parent / guardian<?= !empty($g['linked_at']) ? ' &middot; linked ' . $e(date('F Y', strtotime($g['linked_at']))) : '' ?></span>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="ph-row">
                            <span class="ph-ic"><i class="fa-solid fa-link"></i></span>
                            <span class="ph-grow">
                                <span class="ph-t">No guardian linked yet</span>
                                <span class="ph-s">Send your parent your Student ID, <strong><?= $e($studentID) ?></strong>. They link you from the Parent Portal.</span>
                            </span>
                            <button type="button" class="ph-mini-btn" id="copyStudentId" data-id="<?= $e($studentID) ?>">
                                <i class="fa-regular fa-copy me-1"></i>Copy ID
                            </button>
                        </div>
                    <?php endif; ?>
                </section>

                </div><!-- /.ph-col -->
                <div class="ph-col">

                <!-- Account status (read-only — managed by admin/parent, not the student) -->
                <section class="ph-card">
                    <div class="ph-card-head">
                        <div>
                            <h3>Account status</h3>
                            <p>Access and school credits</p>
                        </div>
                    </div>

                    <div class="ph-kv">
                        <span>Account</span>
                        <div><span class="ph-pill has-dot <?= $isFrozen ? 'is-bad' : 'is-ok' ?>"><?= $e($accountStatus) ?></span></div>
                    </div>
                    <div class="ph-kv">
                        <span>Payments &amp; transfers</span>
                        <div><span class="ph-pill has-dot <?= $transactionsEnabled ? 'is-ok' : 'is-warn' ?>"><?= $transactionsEnabled ? 'Enabled' : ($isFrozen ? 'Paused' : 'Wallet pending') ?></span></div>
                    </div>
                    <div class="ph-kv">
                        <span>Fee waiver credit</span>
                        <div>
                        <?php if (in_array($waiverCredit['status'], ['posted', 'claimed'], true) && $waiverCredit['waiver_file']): ?>
                            <button type="button" class="ph-pill <?= $waiverPill ?>" title="View the signed waiver" onclick="gjcViewWaiver('<?= ADMIN_URL ?>/doc.php?f=<?= urlencode($waiverCredit['waiver_file']) ?>')">
                                <?= $waiverLabel ?><?= $waiverCredit['status'] === 'claimed' ? ' &middot; Claimed' : '' ?>
                            </button>
                        <?php else: ?>
                            <span class="ph-pill <?= $waiverPill ?>" <?= $waiverCredit['status'] === 'pending' ? 'title="Awaiting signed waiver upload by finance."' : '' ?>>
                                <?= $waiverLabel ?>
                            </span>
                        <?php endif; ?>
                        <?php if (in_array($waiverCredit['status'], ['posted', 'claimed'], true)): ?>
                            <span class="gc-inline-peso">&asymp; <?= gjc_money($waiverCredit['amount']) ?></span>
                        <?php endif; ?>
                        <?php if ($waiverCredit['status'] === 'posted'): ?>
                            <button type="button" class="ph-mini-btn" id="fwcClaimOpen" data-amount="<?= (float) $waiverCredit['amount'] ?>">Add to wallet</button>
                        <?php endif; ?>
                        </div>
                    </div>
                    <div class="ph-kv">
                        <span>Maintenance fees</span>
                        <div>
                            <span class="ph-pill <?= $maintFees['accrued'] > 0 ? 'is-warn' : '' ?>"
                                  title="<?= $e($maintFees['rate_pct'] > 0 ? gjc_fee_rate_label($maintFees['rate_pct']) . ' of each payment or transfer (min ₱0.01), billed with your tuition — never taken from your wallet.' : 'No maintenance fee is currently charged.') ?>">
                                <?= $maintFees['accrued'] > 0 ? gjc_gc_amount($maintFees['accrued']) . ' GC due' : 'None due' ?>
                            </span>
                            <?php if ($maintFees['accrued'] > 0): ?>
                                <span class="gc-inline-peso">&asymp; <?= gjc_money($maintFees['accrued']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ph-note">
                        <i class="fa-solid fa-lock"></i>
                        <span>Limits and freezes are set by your guardian or the Finance Office. Maintenance fees are billed with tuition, never taken from your wallet.</span>
                    </div>
                </section>

                <!-- Settings -->
                <section class="ph-card">
                    <div class="ph-card-head">
                        <div><h3>Settings</h3></div>
                    </div>

                    <nav class="ph-menu" aria-label="Profile settings">
                        <a href="<?= $e($editUrl) ?>" class="ph-menu-item">
                            <span class="ph-ic"><i class="fa-solid fa-user-pen"></i></span>
                            <span class="ph-grow">
                                <span class="ph-t">Edit profile</span>
                                <span class="ph-s">Name, mobile number, address and personal details</span>
                            </span>
                            <i class="fa-solid fa-chevron-right ph-chev"></i>
                        </a>
                        <a href="<?= STUDENT_URL ?>/security.php" class="ph-menu-item">
                            <span class="ph-ic"><i class="fa-solid fa-shield-halved"></i></span>
                            <span class="ph-grow">
                                <span class="ph-t">Change password</span>
                                <span class="ph-s">Update your login password</span>
                            </span>
                            <i class="fa-solid fa-chevron-right ph-chev"></i>
                        </a>
                        <button type="button" class="ph-menu-item" data-bs-toggle="modal" data-bs-target="#phHelpModal">
                            <span class="ph-ic"><i class="fa-solid fa-circle-question"></i></span>
                            <span class="ph-grow">
                                <span class="ph-t">Help &amp; FAQs</span>
                                <span class="ph-s">GenCoin, top-ups and passwords</span>
                            </span>
                            <i class="fa-solid fa-chevron-right ph-chev"></i>
                        </button>
                        <button type="button" class="ph-menu-item is-danger" onclick="openLogoutModal(event);">
                            <span class="ph-ic is-bad"><i class="fa-solid fa-arrow-right-from-bracket"></i></span>
                            <span class="ph-grow"><span class="ph-t">Log out</span></span>
                        </button>
                    </nav>
                </section>

                <div class="ph-foot">GenPay v<?= $e(GJC_APP_VERSION) ?></div>

                </div><!-- /.ph-col -->
                </div><!-- /.ph-grid -->

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

    <!-- Help & FAQs (a bottom sheet on phones) -->
    <div class="modal fade ph-sheet" id="phHelpModal" tabindex="-1" aria-labelledby="phHelpTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="phHelpTitle">Help &amp; FAQs</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <details class="ph-faq" open>
                        <summary>What is GenCoin (GC)?</summary>
                        <p>GenCoin is GenPay's in-app currency, fixed at &#8369;10 = 1 GC. It's used to pay at the canteen, send money to other students, and top up your wallet &mdash; no separate balance is stored, it's just a display conversion of your peso balance.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>How do I top up my wallet?</summary>
                        <p>Hand your cash to the <strong>Finance Office</strong> or any participating canteen merchant. They load it straight into your wallet at the counter &mdash; there is nothing to submit beforehand, and the credit shows up in your History right away.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>How do I send GenCoin to another student?</summary>
                        <p>Go to <strong>Send GenCoin</strong>, enter their Student ID to look them up, then the amount. Transfers are instant and cannot be undone, so double-check the recipient before confirming.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>Why is my wallet frozen?</summary>
                        <p>A parent or guardian linked to your account can freeze your wallet from the Parent Portal. Check <strong>Account status</strong> on your profile, or ask them directly if you think this is a mistake.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>What is the Fee Waiver Credit?</summary>
                        <p>It's a school-managed credit that Finance applies toward your tuition once your signed waiver is on file &mdash; separate from your GenCoin wallet. Its status appears in <strong>Account status</strong> on your profile.</p>
                    </details>
                    <details class="ph-faq">
                        <summary>I forgot my password. What do I do?</summary>
                        <p>Visit the Finance Office with a valid ID and request a password reset. If you're already logged in and just want to change it, use <strong>Change password</strong> in Settings instead.</p>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <!-- Signed Waiver Viewer (inline, no new tab/window) -->
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

    <!-- Fee Waiver Credit claim confirmation -->
    <div class="modal fade" id="fwcClaimModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:380px">
            <div class="modal-content" style="border-radius:16px;border:none;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" style="font-size:15px">Add to My Wallet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-2">
                    <p style="font-size:13px;color:var(--sd-muted);margin-bottom:16px;">
                        Your posted Fee Waiver Credit will be added to your GenPay wallet as GenCoin.
                    </p>
                    <div style="text-align:center;margin-bottom:16px;">
                        <strong id="fwcClaimAmount" style="font-size:22px;color:var(--gp-success);">--</strong>
                    </div>
                    <div class="pf-note" id="fwcClaimError" style="display:none;color:var(--gp-danger);"></div>
                    <p style="font-size:12px;color:var(--sd-muted);text-align:center;margin:0 0 16px;">
                        This is final and cannot be undone.
                    </p>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="pf-btn" id="fwcClaimConfirm">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?= JS_URL ?>/bootstrap.bundle.min.js"></script>

    <script>
    // Show the signed waiver inline in a modal instead of opening a new tab/window.
    function gjcViewWaiver(url) {
        document.getElementById('gjcWaiverFrame').src = url;
        new bootstrap.Modal(document.getElementById('gjcWaiverModal')).show();
        return false;
    }
    document.getElementById('gjcWaiverModal').addEventListener('hidden.bs.modal', function () {
        document.getElementById('gjcWaiverFrame').src = '';
    });
    </script>

    <script>
    /* ── Fee Waiver Credit claim ──────────────────────────────── */
    (function() {
        const openBtn = document.getElementById('fwcClaimOpen');
        if (!openBtn) return;

        const CSRF = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
        const modalEl = document.getElementById('fwcClaimModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const amountEl = document.getElementById('fwcClaimAmount');
        const errorEl = document.getElementById('fwcClaimError');
        const confirmBtn = document.getElementById('fwcClaimConfirm');

        function fmt(n) {
            // GenCoin first, the peso amount as its conversion.
            const gc = (+n / <?= (int) GJC_PESOS_PER_GC ?>).toLocaleString('en-PH', { maximumFractionDigits: 2 });
            const php = (+n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return gc + ' GC (≈ ₱' + php + ')';
        }

        openBtn.addEventListener('click', () => {
            amountEl.textContent = fmt(openBtn.dataset.amount || 0);
            errorEl.style.display = 'none';
            errorEl.textContent = '';
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Confirm';
            modal.show();
        });

        confirmBtn.addEventListener('click', async () => {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Adding…';
            errorEl.style.display = 'none';

            try {
                const res = await fetch('<?= STUDENT_URL ?>/api/fee_waiver_claim.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'claim', csrf_token: CSRF }),
                });
                const data = await res.json();
                if (data.success) {
                    location.reload();
                } else {
                    errorEl.textContent = data.error || 'Could not add the credit to your wallet.';
                    errorEl.style.display = 'block';
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Confirm';
                }
            } catch (e) {
                errorEl.textContent = 'Network error. Please try again.';
                errorEl.style.display = 'block';
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Confirm';
            }
        });
    })();
    </script>

    <script>
    /* ── Profile photo upload ─────────────────────────────────── */
    const PHOTO_API = '<?= BASE_URL ?>/api/profile_photo.php';

    document.getElementById('photoInput').addEventListener('change', async function() {
        const file = this.files[0];
        if (!file) return;

        const msg = document.getElementById('photoMsg');
        msg.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';
        msg.style.color = 'rgba(255,255,255,.7)';

        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('photo', file);

        try {
            const res  = await fetch(PHOTO_API, {method: 'POST', body: fd, credentials: 'same-origin'});
            const data = await res.json();
            if (data.success) {
                // A new photo can complete the profile, which changes the
                // nudge — simplest honest refresh is a reload.
                location.href = location.pathname + '?updated=1';
                return;
            } else {
                msg.textContent = data.error || 'Upload failed.';
                msg.style.color = '#fca5a5';
            }
        } catch(err) {
            msg.textContent = 'Network error. Please try again.';
            msg.style.color = '#fca5a5';
        }
        this.value = '';
    });
    </script>

    <script>
    // "Copy ID" on the empty guardian card: the ID a parent needs to link.
    (function () {
        const btn = document.getElementById('copyStudentId');
        if (!btn) return;
        btn.addEventListener('click', async function () {
            const id = btn.dataset.id;
            let ok = false;
            try {
                await navigator.clipboard.writeText(id);
                ok = true;
            } catch (e) {
                // Clipboard API needs a secure context; fall back for plain http.
                const tmp = document.createElement('textarea');
                tmp.value = id;
                tmp.setAttribute('readonly', '');
                tmp.style.position = 'fixed';
                tmp.style.opacity = '0';
                document.body.appendChild(tmp);
                tmp.select();
                ok = document.execCommand('copy');
                tmp.remove();
            }
            btn.innerHTML = ok
                ? '<i class="fa-solid fa-check me-1"></i>Copied'
                : 'Copy failed';
            setTimeout(() => { btn.innerHTML = '<i class="fa-regular fa-copy me-1"></i>Copy ID'; }, 2000);
        });
    })();
    </script>

    <script>
    // Keyboard access for the <label> buttons that open a hidden file picker.
    document.querySelectorAll('label[role="button"][for="photoInput"]').forEach(function (lbl) {
        lbl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); document.getElementById('photoInput').click(); }
        });
    });
    </script>

</body>

</html>
