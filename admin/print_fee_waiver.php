<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

// Finance prints/downloads this for any student (blank or with the pending
// amount filled in). Parents may only reach their own linked student's form,
// and only while it is 'pending' — the form exists to collect their signature
// for an amount that is still awaiting one.
gjc_require_role(['finance', 'parent']);
gjc_ensure_fee_waiver_credits_schema($db);

$role = gjc_current_role();
$studentUserId = (int) ($_GET['student_user_id'] ?? 0);

$studentName = '';
$studentId   = '';
$amount      = null;

if ($studentUserId > 0) {
    if ($role === 'parent') {
        $linkStmt = $db->prepare(
            "SELECT 1 FROM parents p
               JOIN parent_student_links psl ON psl.parent_id = p.id
              WHERE p.user_id = ? AND psl.student_user_id = ?
              LIMIT 1"
        );
        $linkStmt->execute([gjc_user_id(), $studentUserId]);
        if (!$linkStmt->fetchColumn()) {
            http_response_code(403);
            exit('Access denied.');
        }
    }

    $credit = gjc_student_waiver_credit($db, $studentUserId);

    if ($role === 'parent' && $credit['status'] !== 'pending') {
        http_response_code(403);
        exit('This waiver is not currently awaiting a signature.');
    }

    $stmt = $db->prepare(
        "SELECT u.first_name, u.last_name, si.studentID
           FROM users u
           LEFT JOIN student_info si ON si.userID = u.userID
          WHERE u.userID = ?
          LIMIT 1"
    );
    $stmt->execute([$studentUserId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($student) {
        $studentName = trim($student['first_name'] . ' ' . $student['last_name']);
        $studentId   = (string) ($student['studentID'] ?? '');
    }

    if ($credit['status'] === 'pending') {
        $amount = $credit['amount'];
    }
}

$blankLine = '_________________________';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="<?= ICONS_URL ?>/gp_logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Waiver Credit Agreement | GenPay</title>
    <link rel="stylesheet" href="<?= CSS_URL ?>/print_fee_waiver.css?v=5">
</head>
<body>
    <div class="print-actions">
        <button type="button" onclick="window.print()">Print</button>
    </div>

    <main class="waiver">
        <div class="waiver-letterhead">
            <img class="waiver-logo" src="<?= ICONS_URL ?>/GenDeJesusFavicon.png" alt="General de Jesus College Seal">
            <div class="waiver-letterhead-text">
                <h1>General de Jesus College</h1>
                <p>Office of Finance &amp; Accounting &mdash; GenPay</p>
            </div>
        </div>

        <div class="waiver-title-bar">
            <h2>Fee Waiver Credit Agreement</h2>
        </div>

        <div class="waiver-body">
            <p class="waiver-intro">
                This Agreement is made and entered into by <strong>General de Jesus College</strong>, through its
                Finance &amp; Accounting Office, and the parent/guardian of the student named below, governing the
                Fee Waiver Credit described herein.
            </p>

            <section class="waiver-section">
                <h3>I. Particulars of the Credit</h3>
                <div class="field-grid">
                    <div class="field">
                        <span>Student Name</span>
                        <strong><?= $studentName !== '' ? gjc_e($studentName) : $blankLine ?></strong>
                    </div>
                    <div class="field">
                        <span>Student ID</span>
                        <strong><?= $studentId !== '' ? gjc_e($studentId) : $blankLine ?></strong>
                    </div>
                    <div class="field">
                        <span>Amount</span>
                        <strong><?= $amount !== null ? gjc_money($amount) : $blankLine ?></strong>
                    </div>
                    <div class="field">
                        <span>Date</span>
                        <strong><?= gjc_e(date('F j, Y')) ?></strong>
                    </div>
                </div>
            </section>

            <section class="waiver-section">
                <h3>II. Terms and Conditions</h3>
                <ol class="waiver-terms">
                    <li><strong>Nature of the Credit.</strong> The Fee Waiver Credit described herein is a school-approved credit recorded by the Finance &amp; Accounting Office to be applied toward the student's tuition. It is not a cash payment, is not convertible to cash, and does not form part of the student's GenPay/GenCoin digital wallet balance.</li>
                    <li><strong>Effectivity.</strong> This credit shall take effect only upon (a) execution of this Agreement by the parent/guardian, and (b) verification and confirmation of the signed Agreement by the Finance &amp; Accounting Office. Prior to such confirmation, the credit remains pending and is not reflected in the student's account.</li>
                    <li><strong>Application.</strong> The confirmed amount shall be applied solely toward the tuition obligations of the student named above and may not be reassigned, transferred, or applied to the account of any other student.</li>
                    <li><strong>Modification and Revocation.</strong> The School reserves the right to revoke, adjust, or cancel this credit at any time prior to confirmation, or thereafter in the event of error, misrepresentation, or a determination that the student is ineligible.</li>
                    <li><strong>Acknowledgment.</strong> By signing below, the parent/guardian acknowledges having read and understood the foregoing terms and agrees to them on behalf of the student.</li>
                    <li><strong>Inquiries.</strong> Questions concerning this Agreement may be directed to the Finance &amp; Accounting Office.</li>
                </ol>
            </section>

            <section class="waiver-section">
                <h3>III. Signatures</h3>
                <div class="signatures">
                    <div class="sig-block">
                        <span class="sig-rule"></span>
                        <span class="sig-label">Parent / Guardian Signature over Printed Name</span>
                        <div class="sig-meta">
                            <span>Relationship to Student: <i class="sig-blank"></i></span>
                            <span>Date Signed: <i class="sig-blank"></i></span>
                        </div>
                    </div>
                    <div class="sig-block">
                        <span class="sig-rule"></span>
                        <span class="sig-label">Finance / Accounting Signature over Printed Name</span>
                        <div class="sig-meta">
                            <span>Position: <i class="sig-blank"></i></span>
                            <span>Date Signed: <i class="sig-blank"></i></span>
                        </div>
                    </div>
                </div>
            </section>

            <p class="waiver-footer">
                This is a system-generated document from the GenPay platform.
            </p>
        </div>
    </main>
</body>
</html>
