<?php

/**
 * Shown when a finance account that is not the super-admin opens the audit
 * trail. A plain redirect to login.php would be confusing for someone who IS
 * signed in and has every other finance page available, so this says plainly
 * what happened and why.
 *
 * Expects $currentUser from the caller.
 */

$fe = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $fe(ICONS_URL) ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restricted | GenPay</title>
    <link rel="stylesheet" href="<?= $fe(CSS_URL) ?>/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $fe(CSS_URL) ?>/admin.css?v=41">
    <link rel="stylesheet" href="<?= $fe(CSS_URL) ?>/responsive.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .af-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
        }

        .af-card {
            max-width: 520px;
            width: 100%;
            background: var(--gp-surface, #fff);
            border: 1px solid var(--gp-border, #e5e7eb);
            border-radius: 16px;
            padding: 40px 36px;
            text-align: center;
            box-shadow: var(--gjc-shadow-md, 0 8px 24px rgba(0, 0, 0, .07));
        }

        .af-ic {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--gp-warning-bg, #fef3c7);
            color: var(--gp-warning, #b45309);
            font-size: 26px;
        }

        .af-card h1 {
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 12px;
            color: var(--gp-ink, #111827);
        }

        .af-card p {
            color: var(--gp-ink-soft, #4b5563);
            line-height: 1.7;
            margin: 0 0 10px;
            font-size: 14px;
        }

        .af-actions {
            margin-top: 24px;
        }
    </style>
</head>

<body class="gp-theme">
    <div class="af-wrap">
        <div class="af-card">
            <div class="af-ic"><i class="fa-solid fa-shield-halved"></i></div>
            <h1>The audit trail is super-admin only</h1>
            <p>
                You are signed in as <strong><?= $fe($currentUser['name'] ?? 'a finance user') ?></strong>,
                but the systemic audit trail is restricted to the super-admin account.
            </p>
            <p>
                It records the actions of every administrator, and its entries contain
                student and guardian personal details — so it is deliberately not part
                of the general finance role.
            </p>
            <div class="af-actions">
                <a href="<?= $fe(ADMIN_URL) ?>/dashboard.php" class="gp-btn gp-btn--forest">
                    <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</body>

</html>
