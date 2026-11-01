<?php
    session_start();
    require_once __DIR__ . '/connection/config.php';

    $role = (int) ($_SESSION['roleID'] ?? 0);


    if ($role === 4) {
        header('Location: ' . DASHBOARD_URL);
        exit;
    } elseif ($role === 2) {
        header('Location: ' . DASHBOARD_URL);
        exit;
    } elseif ($role === 1) {
        header('Location: ' . DASHBOARD_URL);
        exit;
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
    <title>GenPay | General de Jesus College</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800&family=Oswald:wght@600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/public_shell.css?v=6">
    <link rel="stylesheet" href="<?= CSS_URL ?>/public_theme.css?v=1">
    <link rel="stylesheet" href="<?= CSS_URL ?>/index.css?v=35">
</head>
<body class="landing">

    <?php include __DIR__ . '/includes/partials/public_navbar.php'; ?>

    <header class="hero-simple">
        <div class="wrap hero-grid">
            <div class="hero-text">
                <h1>The Future of Campus <br><span>Cashless Payments</span></h1>
                <p>Experience seamless, secure, and instant transactions within General de Jesus College. Pay for meals, services, and manage your school finances with just a scan.</p>
                <a href="<?= BASE_URL ?>/login.php" class="cta">Get Started Now</a>
            </div>
            <!-- Animated scene, drawn in code: a phone scans a stall's QR bill,
                 the payment is confirmed, and GenCoin flies to the stall. -->
            <svg class="pay-scene" viewBox="0 0 560 480" role="img" aria-label="A phone scans a campus stall's QR code and GenCoin moves to the stall">
                <defs>
                    <symbol id="gp-qr" viewBox="0 0 21 21" shape-rendering="crispEdges">
                        <?php
                        // Decorative QR: three finder squares plus a fixed pseudo-random fill.
                        $finder = fn ($x, $y) => ($x < 7 && $y < 7) || ($x > 13 && $y < 7) || ($x < 7 && $y > 13);
                        mt_srand(2026);
                        for ($y = 0; $y < 21; $y++) {
                            for ($x = 0; $x < 21; $x++) {
                                if (!$finder($x, $y) && mt_rand(0, 99) < 48) {
                                    echo "<rect x=\"$x\" y=\"$y\" width=\"1\" height=\"1\" fill=\"currentColor\"/>";
                                }
                            }
                        }
                        foreach ([[0, 0], [14, 0], [0, 14]] as [$fx, $fy]) {
                            echo "<rect x=\"" . ($fx + 0.5) . "\" y=\"" . ($fy + 0.5) . "\" width=\"6\" height=\"6\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1\"/>";
                            echo "<rect x=\"" . ($fx + 2) . "\" y=\"" . ($fy + 2) . "\" width=\"3\" height=\"3\" fill=\"currentColor\"/>";
                        }
                        ?>
                    </symbol>
                </defs>

                <circle class="ps-glow" cx="290" cy="245" r="200"/>
                <circle class="ps-ring" cx="290" cy="245" r="226"/>

                <!-- The stall's bill with its QR code -->
                <g class="ps-bill">
                    <rect x="36" y="110" width="210" height="270" rx="20" fill="#fff"/>
                    <path d="M36 130a20 20 0 0 1 20-20h170a20 20 0 0 1 20 20v30H36z" fill="#f0a000"/>
                    <g fill="#f0a000">
                        <circle cx="51" cy="160" r="15"/><circle cx="81" cy="160" r="15"/><circle cx="111" cy="160" r="15"/>
                        <circle cx="141" cy="160" r="15"/><circle cx="171" cy="160" r="15"/><circle cx="201" cy="160" r="15"/>
                        <circle cx="231" cy="160" r="15"/>
                    </g>
                    <use href="#gp-qr" x="81" y="190" width="120" height="120" color="#003000"/>
                    <rect x="66" y="326" width="150" height="10" rx="5" fill="#dfe8dd"/>
                    <rect x="66" y="348" width="96" height="10" rx="5" fill="#dfe8dd"/>
                    <g class="ps-paid">
                        <circle cx="240" cy="116" r="26" fill="#138a36" stroke="#fff" stroke-width="4"/>
                        <path d="M228 116l8 8 16-17" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
                    </g>
                </g>

                <!-- The phone, gently floating -->
                <g class="ps-float">
                    <g transform="translate(320 60) rotate(7 90 170)">
                        <rect width="180" height="340" rx="30" fill="#fff"/>
                        <rect x="10" y="12" width="160" height="316" rx="22" fill="#eef4ef"/>
                        <rect x="66" y="22" width="48" height="8" rx="4" fill="#cdd9cf"/>
                        <image href="<?= ICONS_URL ?>/gp_logo_clear.png" x="70" y="38" width="40" height="40"/>
                        <use href="#gp-qr" x="40" y="104" width="100" height="100" color="#003000" opacity="0.3"/>
                        <path d="M32 116v-20h20M128 96h20v20M148 192v20h-20M52 212H32v-20" fill="none" stroke="#f0a000" stroke-width="6" stroke-linecap="round"/>
                        <rect class="ps-scan" x="36" y="102" width="108" height="5" rx="2.5" fill="#f0a000"/>
                        <g class="ps-check">
                            <circle cx="90" cy="154" r="40" fill="#138a36"/>
                            <path d="M71 155l13 13 26-28" fill="none" stroke="#fff" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/>
                        </g>
                        <rect class="ps-btn" x="30" y="252" width="120" height="40" rx="20" fill="#f0a000"/>
                    </g>
                </g>

                <!-- GenCoin on its way to the stall -->
                <?php foreach (['c1', 'c2', 'c3'] as $coin): ?>
                    <g class="ps-coin <?= $coin ?>">
                        <circle cx="408" cy="330" r="18" fill="#f0a000" stroke="#c98500" stroke-width="3"/>
                        <text x="408" y="337" text-anchor="middle" font-family="Poppins, Arial, sans-serif" font-size="19" font-weight="700" fill="#003000">G</text>
                    </g>
                <?php endforeach; ?>

                <g class="ps-spark" fill="#f0a000">
                    <path d="M60 60h6v-6h4v6h6v4h-6v6h-4v-6h-6z"/>
                    <path d="M520 120h6v-6h4v6h6v4h-6v6h-4v-6h-6z"/>
                    <path d="M500 430h6v-6h4v6h6v4h-6v6h-4v-6h-6z"/>
                </g>
            </svg>
        </div>
    </header>

    <section class="block features" aria-label="Features">
        <div class="wrap feature-grid">
            <article class="feature">
                <span class="feature-icon" aria-hidden="true"><i class="fa-solid fa-qrcode"></i></span>
                <h3>QR Code Payments</h3>
                <p>No need for cash. Just scan the merchant's QR code to pay instantly.</p>
            </article>
            <article class="feature">
                <span class="feature-icon" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
                <h3>Secure &amp; Reliable</h3>
                <p>Built with enterprise-grade token economy security to ensure your money and transactions are safe at all times.</p>
            </article>
            <article class="feature">
                <span class="feature-icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
                <h3>Real-time Tracking</h3>
                <p>Monitor your wallet balance, transaction history, encashments, and points flow effortlessly.</p>
            </article>
            <article class="feature">
                <span class="feature-icon" aria-hidden="true"><i class="fa-solid fa-store"></i></span>
                <h3>Stall Directory</h3>
                <p>Browse available stalls in real-time and submit your vendor application online - no visits required.</p>
                <a href="<?= BASE_URL ?>/stalls" class="text-link">View Stalls <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </article>
        </div>
    </section>

    <section class="block apply" aria-labelledby="apply-title">
        <div class="wrap">
            <h2 id="apply-title">How to Apply for a Stall</h2>
            <p class="block-sub">Follow these steps to submit your vendor application online. Review will take place after submission, and a stall will be assigned to approved applicants.</p>

            <ol class="steps">
                <li class="step">
                    <span class="step-head" aria-hidden="true"><span class="step-number">1</span><i class="fa-solid fa-folder-open"></i></span>
                    <h3>Prepare Your Documents</h3>
                    <p>Gather your business details and scan or photograph the required documents listed below before starting the form.</p>
                </li>
                <li class="step">
                    <span class="step-head" aria-hidden="true"><span class="step-number">2</span><i class="fa-solid fa-file-pen"></i></span>
                    <h3>Fill Out &amp; Submit</h3>
                    <p>Complete the online application form with your business information, upload your documents, and accept the Terms &amp; Conditions.</p>
                </li>
                <li class="step">
                    <span class="step-head" aria-hidden="true"><span class="step-number">3</span><i class="fa-solid fa-envelope-circle-check"></i></span>
                    <h3>Wait for Review</h3>
                    <p>The GJC administration will review your application and documents, then contact you via email regarding approval and stall assignment.</p>
                </li>
            </ol>

            <div class="requirements">
                <h3>Requirements Checklist</h3>
                <ul>
                    <li>Business name and proprietor / owner full name</li>
                    <li>Valid contact number (09XXXXXXXXX format) and email address</li>
                    <li>Profile picture of the proprietor (JPG or PNG, max 5 MB)</li>
                    <li>Business Permit (PDF, JPG, or PNG, max 5 MB)</li>
                    <li>Sanitary Permit (PDF, JPG, or PNG, max 5 MB)</li>
                    <li>GJC Requirements document (PDF, JPG, or PNG, max 5 MB)</li>
                    <li>Clearance document (PDF, JPG, or PNG, max 5 MB)</li>
                    <li>Acceptance of the Terms &amp; Conditions</li>
                </ul>
                <p class="requirements-note">All fields and documents above are required to submit an application. Submission does not guarantee approval - applications are reviewed at the institution's discretion, and a specific stall will be assigned later in the review process.</p>
            </div>

            <a href="<?= BASE_URL ?>/apply" class="cta">Start Your Application</a>
        </div>
    </section>

    <section class="block faq" id="faq" aria-labelledby="faq-title">
        <div class="wrap wrap-narrow">
            <h2 id="faq-title">Frequently Asked Questions</h2>
            <p class="block-sub">Everything you need to know about GenPay, the cashless wallet of General de Jesus College.</p>

            <div class="faq-list">
                <details>
                    <summary>What is GenPay?</summary>
                    <p>GenPay is the cashless wallet system of General de Jesus College. Your wallet holds GenCoin, which you load with cash and spend at campus stalls by scanning the QR code on the merchant's bill.</p>
                </details>
                <details>
                    <summary>Who can use GenPay?</summary>
                    <p>Students, parents and campus merchants. Students pay at stalls and send GenCoin to other students. Parents can pay at stalls too, send allowance to their child, and manage their child's wallet. Merchants accept payments, sell top-ups and cash out their earnings.</p>
                </details>
                <details>
                    <summary>Is my money safe in GenPay?</summary>
                    <p>Every top-up, payment and transfer is recorded and shows in your transaction history. A payment QR works only once and expires, and accounts are created and managed by GJC Finance staff.</p>
                </details>
                <details>
                    <summary>How do I add money to my wallet?</summary>
                    <p>Pay cash at the Finance counter or at a participating campus stall, and the GenCoin is loaded to your wallet right away. A service fee is added on top of the amount (1% at the Finance counter, 3% at a stall), so you receive the full amount you asked for.</p>
                </details>
                <details>
                    <summary>How do I pay at a stall?</summary>
                    <p>The merchant creates your bill and shows a payment QR code. Log in to GenPay, scan the code, check the items and amount, and confirm. The payment goes through instantly.</p>
                </details>
                <details>
                    <summary>I forgot my password. What should I do?</summary>
                    <p>Visit the Finance Office with your ID and request a password reset. A temporary password will be emailed to you, and you'll be asked to set a new one when you sign in.</p>
                </details>
                <details>
                    <summary>Can parents monitor their child's spending?</summary>
                    <p>Yes. A parent linked to a student can see the wallet balance and transaction history, set a daily spending limit, and freeze the wallet to block purchases and transfers.</p>
                </details>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/includes/partials/public_footer.php'; ?>

</body>
</html>
