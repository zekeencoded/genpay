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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="<?= CSS_URL ?>/gjc-clear.css?v=23">
    <link rel="stylesheet" href="<?= CSS_URL ?>/public_shell.css?v=4">
    <link rel="stylesheet" href="<?= CSS_URL ?>/index.css?v=18">
</head>
<body>

    <?php include __DIR__ . '/includes/partials/public_navbar.php'; ?>

    <div class="hero">
        <div class="hero-content">
            <h1>The Future of Campus <br><span>Cashless Payments</span></h1>
            <p>Experience seamless, secure, and instant transactions within General de Jesus College. Pay for meals, services, and manage your school finances with just a scan.</p>
            <a href="<?= BASE_URL ?>/login.php" class="btn-primary-custom">Get Started Now</a>
        </div>
    </div>

    <div class="features container">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fa-solid fa-qrcode"></i>
                    </div>
                    <h3>QR Code Payments</h3>
                    <p>No need for cash. Just scan the merchant's QR code to pay instantly.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h3>Secure & Reliable</h3>
                    <p>Built with enterprise-grade token economy security to ensure your money and transactions are safe at all times.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                    <h3>Real-time Tracking</h3>
                    <p>Monitor your wallet balance, transaction history, encashments, and points flow effortlessly.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="feature-card">
                    <div class="feature-icon">
                        <i class="fa-solid fa-store"></i>
                    </div>
                    <h3>Stall Directory</h3>
                    <p>Browse available stalls in real-time and submit your vendor application online - no visits required.</p>
                    <a href="<?= BASE_URL ?>/stalls" style="display:inline-block;margin-top:14px;font-weight:700;color:#0e6332;text-decoration:none;font-size:14px;">View Stalls <i class="fa-solid fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </div>

    <div class="how-to-apply">
        <div class="container">
            <div class="section-title">How to Apply for a Stall</div>
            <p class="section-sub">Follow these steps to submit your vendor application online. Review will take place after submission, and a stall will be assigned to approved applicants.</p>

            <div class="row g-4">
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <h4>Prepare Your Documents</h4>
                        <p>Gather your business details and scan or photograph the required documents listed below before starting the form.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <h4>Fill Out & Submit</h4>
                        <p>Complete the online application form with your business information, upload your documents, and accept the Terms & Conditions.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <h4>Wait for Review</h4>
                        <p>The GJC administration will review your application and documents, then contact you via email regarding approval and stall assignment.</p>
                    </div>
                </div>
            </div>

            <div class="requirements-box">
                <h4>Requirements Checklist</h4>
                <ul class="requirements-list">
                    <li><i class="fa-solid fa-circle-check"></i> Business name and proprietor / owner full name</li>
                    <li><i class="fa-solid fa-circle-check"></i> Valid contact number (09XXXXXXXXX format) and email address</li>
                    <li><i class="fa-solid fa-circle-check"></i> Profile picture of the proprietor (JPG or PNG, max 5 MB)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Business Permit (PDF, JPG, or PNG, max 5 MB)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Sanitary Permit (PDF, JPG, or PNG, max 5 MB)</li>
                    <li><i class="fa-solid fa-circle-check"></i> GJC Requirements document (PDF, JPG, or PNG, max 5 MB)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Clearance document (PDF, JPG, or PNG, max 5 MB)</li>
                    <li><i class="fa-solid fa-circle-check"></i> Acceptance of the Terms & Conditions</li>
                </ul>
                <div class="requirements-note">
                    All fields and documents above are required to submit an application. Submission does not guarantee approval - applications are reviewed at the institution's discretion, and a specific stall will be assigned later in the review process.
                </div>
            </div>

            <div class="how-to-apply-cta">
                <a href="<?= BASE_URL ?>/apply" class="btn-primary-custom">Start Your Application</a>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/partials/public_footer.php'; ?>

</body>
</html>
