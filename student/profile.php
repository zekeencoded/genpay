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

// Live wallet controls (parent freeze / daily limit) instead of hardcoded text.
$isFrozen = false;
$dailyLimit = 0.0;
if ($wallet['id'] > 0 && $wallet['source'] === 'student_wallets') {
    $wcStmt = $db->prepare("SELECT is_frozen, daily_spend_limit FROM student_wallets WHERE id = ?");
    $wcStmt->execute([$wallet['id']]);
    if ($wc = $wcStmt->fetch(PDO::FETCH_ASSOC)) {
        $isFrozen = (int) $wc['is_frozen'] === 1;
        $dailyLimit = (float) $wc['daily_spend_limit'];
    }
}
$accountStatus = $isFrozen ? 'Frozen' : ucfirst((string) ($rawUser['status'] ?? 'Active'));
$transactionsEnabled = $wallet['id'] > 0 && !$isFrozen;

$waiverCredit = gjc_student_waiver_credit($db, (int) $currentUser['id']);
$waiverPill = ['posted' => 'green', 'pending' => 'gold', 'claimed' => 'green'][$waiverCredit['status']] ?? 'gray';
$waiverLabel = in_array($waiverCredit['status'], ['posted', 'claimed'], true)
    ? gjc_money($waiverCredit['amount'])
    : ($waiverCredit['status'] === 'pending' ? 'Pending' : 'None');

$maintFees = gjc_student_maintenance_fees($db, (int) $currentUser['id']);

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
    <link rel="stylesheet" href="<?= CSS_URL ?>/student_profile.css?v=14">
</head>

<body class="sd-body">

    <div class="sd-layout">

        <?php require __DIR__ . '/../includes/partials/sidebar_student.php'; ?>

        <main class="sd-main">

            <?php
            $topbarTitle = 'My Profile';
            $topbarSubtitle = 'Manage your student account details, status, and password security.';
            $topbarAvatarPhotoUrl = $profilePhotoUrl;
            require __DIR__ . '/../includes/partials/topbar_student.php';
            ?>

            <div class="sd-content">

                <?php if ($notice): ?>
                <div class="pf-alert">
                    <i class="fa-solid fa-circle-check"></i>
                    <?= $e($notice) ?>
                </div>
                <?php endif; ?>

                <!-- Hero -->
                <section class="pf-hero">
                    <div class="pf-hero-left">
                        <div class="pf-avatar-wrap">
                            <div class="pf-avatar" id="avatarCircle">
                                <?php if ($profilePhotoUrl): ?>
                                    <img id="avatarImg" src="<?= $e($profilePhotoUrl) ?>" alt="Profile Photo">
                                <?php else: ?>
                                    <span id="avatarInitial"><?= $e($studentInitial) ?></span>
                                <?php endif; ?>
                            </div>
                            <label for="photoInput" class="pf-avatar-edit" title="Change photo">
                                <i class="fa-solid fa-camera"></i>
                            </label>
                            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
                        </div>

                        <div class="pf-identity">
                            <span>Student Account</span>
                            <h2><?= $e($studentName) ?></h2>
                            <p><?= $e($email) ?> &middot; <?= $e($studentID) ?></p>
                            <span class="sd-role-badge">STUDENT</span>
                            <br>
                            <a href="<?= STUDENT_URL ?>/profile_edit.php" class="pf-edit-btn">
                                <i class="fa-solid fa-pen"></i> Edit Profile
                            </a>
                            <div class="pf-photo-msg" id="photoMsg"></div>
                        </div>
                    </div>

                    <div class="pf-wallet-box">
                        <span>Wallet Balance</span>
                        <h3><?= gjc_gc_amount($walletBalance) ?> GC</h3>
                        <p>&#8776; <?= gjc_money($walletBalance) ?> &middot; Member since <?= $e($memberSince) ?></p>
                    </div>
                </section>

                <!-- Account status (read-only — managed by admin/parent, not the student) -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Account Status</h3>
                            <p>Current access and transaction settings.</p>
                        </div>
                    </div>

                    <div class="pf-status-list">
                        <div>
                            <span>Account Status</span>
                            <strong class="pf-pill <?= $isFrozen ? 'red' : 'green' ?>"><?= $e($accountStatus) ?></strong>
                        </div>

                        <div>
                            <span>Transactions</span>
                            <strong class="pf-pill <?= $transactionsEnabled ? 'green' : 'gold' ?>">
                                <?= $transactionsEnabled ? 'Enabled' : ($isFrozen ? 'Paused' : 'Wallet Pending') ?>
                            </strong>
                        </div>

                        <div>
                            <span>Spending Limit</span>
                            <strong class="pf-pill <?= $dailyLimit > 0 ? 'gold' : 'gray' ?>">
                                <?= $dailyLimit > 0 ? '&#8369;' . number_format($dailyLimit, 2) . ' / day' : 'No Limit' ?>
                            </strong>
                        </div>

                        <div>
                            <span>Fee Waiver Credit</span>
                            <div style="display:flex;align-items:center;gap:8px;">
                            <?php if (in_array($waiverCredit['status'], ['posted', 'claimed'], true) && $waiverCredit['waiver_file']): ?>
                                <button type="button" class="pf-pill <?= $waiverPill ?>" style="border:none;cursor:pointer;" onclick="gjcViewWaiver('<?= ADMIN_URL ?>/doc.php?f=<?= urlencode($waiverCredit['waiver_file']) ?>')">
                                    <?= $waiverLabel ?><?= $waiverCredit['status'] === 'claimed' ? ' &middot; Claimed' : '' ?>
                                </button>
                            <?php else: ?>
                                <strong class="pf-pill <?= $waiverPill ?>" <?= $waiverCredit['status'] === 'pending' ? 'title="Awaiting signed waiver upload by finance."' : '' ?>>
                                    <?= $waiverLabel ?>
                                </strong>
                            <?php endif; ?>
                            <?php if ($waiverCredit['status'] === 'posted'): ?>
                                <button type="button" class="pf-pill green" id="fwcClaimOpen" style="border:none;cursor:pointer;" data-amount="<?= (float) $waiverCredit['amount'] ?>">
                                    <i class="fa-solid fa-wallet me-1"></i>Add to My Wallet
                                </button>
                            <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <span>Maintenance Fees</span>
                            <strong class="pf-pill <?= $maintFees['accrued'] > 0 ? 'gold' : 'gray' ?>"
                                    title="<?= $e($maintFees['rate_pct'] > 0 ? gjc_fee_rate_label($maintFees['rate_pct']) . ' of each payment or transfer (min ₱0.01), billed with your tuition — never taken from your wallet.' : 'No maintenance fee is currently charged.') ?>">
                                <?= $maintFees['accrued'] > 0 ? gjc_money($maintFees['accrued']) . ' due' : 'None due' ?>
                            </strong>
                        </div>
                    </div>

                    <div class="pf-note">
                        Some account settings are managed by the system administrator or your parent/guardian.
                    </div>
                </section>

                <!-- Personal details (editable from Edit Profile) -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Personal Details</h3>
                            <p>Your demographics on record.</p>
                        </div>
                    </div>

                    <div class="pf-status-list">
                        <?php
                        $sexOnFile = trim((string) ($rawUser['sex'] ?? ''));
                        $addressOnFile = gjc_format_address($rawUser);
                        $dobOnFile = $rawUser['date_of_birth'] ?? null;
                        $dobAge = gjc_age_from_dob($dobOnFile);
                        foreach ([
                            'Sex'           => $sexOnFile !== '' ? $sexOnFile : 'Not set',
                            // Derived on read, so it is right on every birthday
                            // without anyone having to update it.
                            'Age'           => $dobAge !== null ? gjc_age_label($dobOnFile) : 'Not set',
                            'Date of Birth' => $dobAge !== null ? gjc_dob_label($dobOnFile) : 'Not set',
                            'Address'       => $addressOnFile !== '' ? $addressOnFile : 'Not set',
                        ] as $pdLabel => $pdValue): ?>
                        <div>
                            <span><?= $e($pdLabel) ?></span>
                            <!-- Plain text, not a pf-pill: an address is a value,
                                 not a status, and a pill neither wraps nor fits one. -->
                            <strong class="pf-detail-value <?= $pdValue === 'Not set' ? 'is-empty' : '' ?>"><?= $e($pdValue) ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="pf-note">
                        Keep these up to date from <a href="<?= STUDENT_URL ?>/profile_edit.php">Edit Profile</a>.
                        Your age is worked out from your date of birth, so it is never entered by hand.
                    </div>
                </section>

                <!-- Settings menu -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>Settings</h3>
                            <p>Manage your account and security.</p>
                        </div>
                    </div>

                    <div class="pf-menu">
                        <a href="<?= STUDENT_URL ?>/profile_edit.php" class="pf-menu-item">
                            <span class="pf-menu-item-icon"><i class="fa-solid fa-user-pen"></i></span>
                            <span class="pf-menu-item-text">
                                <strong>Edit Profile</strong>
                                <span>Update your name and phone number</span>
                            </span>
                            <i class="fa-solid fa-chevron-right pf-menu-item-chevron"></i>
                        </a>

                        <a href="<?= STUDENT_URL ?>/security.php" class="pf-menu-item">
                            <span class="pf-menu-item-icon"><i class="fa-solid fa-shield-halved"></i></span>
                            <span class="pf-menu-item-text">
                                <strong>Change Password</strong>
                                <span>Update your login password</span>
                            </span>
                            <i class="fa-solid fa-chevron-right pf-menu-item-chevron"></i>
                        </a>
                    </div>
                </section>

                <!-- FAQs -->
                <section class="sd-panel">
                    <div class="sd-panel-head">
                        <div>
                            <h3>FAQs</h3>
                            <p>Quick answers to common questions.</p>
                        </div>
                    </div>

                    <div class="pf-faq accordion" id="pfFaqAccordion">
                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq1">
                                    What is GenCoin (GC)?
                                </button>
                            </h2>
                            <div id="pfFaq1" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    GenCoin is GenPay's in-app currency, fixed at &#8369;10 = 1 GC. It's used to pay at the canteen, send money to other students, and top up your wallet &mdash; no separate balance is stored, it's just a display conversion of your peso balance.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq2">
                                    How do I top up my wallet?
                                </button>
                            </h2>
                            <div id="pfFaq2" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    Hand your cash to the <strong>Finance Office</strong> or any participating canteen merchant. They load it straight into your wallet at the counter &mdash; there is nothing to submit beforehand, and the credit shows up in your History right away.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq3">
                                    How do I send GenCoin to another student?
                                </button>
                            </h2>
                            <div id="pfFaq3" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    Go to <strong>Send GenCoin</strong>, enter their Student ID to look them up, then the amount. Transfers are instant and cannot be undone, so double-check the recipient before confirming.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq4">
                                    Why is my wallet frozen or spending-limited?
                                </button>
                            </h2>
                            <div id="pfFaq4" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    A parent or guardian linked to your account can freeze your wallet or set a daily spending limit from the Parent Portal. Check your <strong>Account Status</strong> above, or ask them directly if you think this is a mistake.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq5">
                                    What is the Fee Waiver Credit?
                                </button>
                            </h2>
                            <div id="pfFaq5" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    It's a school-managed credit that Finance applies toward your tuition once your signed waiver is on file &mdash; separate from your GenCoin wallet. Its status appears in <strong>Account Status</strong> above.
                                </div>
                            </div>
                        </div>

                        <div class="pf-faq-item accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#pfFaq6">
                                    I forgot my password. What do I do?
                                </button>
                            </h2>
                            <div id="pfFaq6" class="accordion-collapse collapse" data-bs-parent="#pfFaqAccordion">
                                <div class="accordion-body">
                                    Visit the Finance Office with a valid ID and request a password reset. If you're already logged in and just want to change it, use <strong>Change Password</strong> in Settings above instead.
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <button type="button" class="pf-btn pf-btn--block is-danger" onclick="openLogoutModal(event);">
                    <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Log Out
                </button>

                <div class="pf-version">GenPay v<?= $e(GJC_APP_VERSION) ?></div>

            </div>

        </main>

    </div>

    <?php require __DIR__ . '/../includes/partials/bottom_nav_student.php'; ?>

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
            return '₱' + (+n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
                const circle = document.getElementById('avatarCircle');
                let img = document.getElementById('avatarImg');
                const initial = document.getElementById('avatarInitial');
                if (initial) initial.remove();
                if (!img) {
                    img = document.createElement('img');
                    img.id = 'avatarImg';
                    img.alt = 'Profile Photo';
                    circle.appendChild(img);
                }
                img.src = data.photo_url;

                // Also sync topbar avatar
                const topbar = document.getElementById('topbarAvatar');
                if (topbar) {
                    topbar.style.overflow = 'hidden';
                    let tImg = document.getElementById('topbarAvatarImg');
                    if (!tImg) {
                        topbar.textContent = '';
                        tImg = document.createElement('img');
                        tImg.id = 'topbarAvatarImg';
                        tImg.alt = '';
                        tImg.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';
                        topbar.appendChild(tImg);
                    }
                    tImg.src = data.photo_url;
                }

                msg.innerHTML = '<i class="fa-solid fa-check"></i> Photo updated.';
                msg.style.color = '#4ade80';
                setTimeout(() => { msg.innerHTML = ''; }, 3000);
            } else {
                msg.innerHTML = data.error || 'Upload failed.';
                msg.style.color = '#fca5a5';
            }
        } catch(err) {
            msg.innerHTML = 'Network error. Please try again.';
            msg.style.color = '#fca5a5';
        }
        this.value = '';
    });
    </script>

</body>

</html>
