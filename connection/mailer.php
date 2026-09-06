<?php
/**
 * GenPay — Mailer Helper
 * Wraps PHPMailer with project SMTP credentials.
 *
 * Sending is ASYNC by default: gjc_queue_email() drops the message into the
 * storage/mail_spool file queue and returns in ~1ms, then a detached
 * background worker (connection/mail_worker.php) delivers it — so submit /
 * award / reject respond instantly instead of waiting 2-5s for the Gmail
 * SMTP handshake. The worker retries transient failures (3 attempts, 15s
 * apart) and parks permanent failures in storage/mail_spool/failed.
 *
 * Usage (async, preferred):
 *   gjc_queue_email($email, $name, $subject, $htmlBody, $altBody);
 *
 * Usage (synchronous, only where blocking is acceptable):
 *   $mail = gjc_mailer(); ... $mail->send();
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

function gjc_mailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'daitodump@gmail.com';
    $mail->Password   = 'ogfj biau oeyr ntab';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->Timeout    = 10;
    $mail->setFrom('daitodump@gmail.com', 'GenPay');
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    return $mail;
}

// ────────────────────────────────────────────────────────────────
//  Async file-spool queue (no DB tables)
// ────────────────────────────────────────────────────────────────

/** Spool directory for queued emails; created (and Apache-denied) on demand. */
function gjc_mail_spool_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/mail_spool';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Spool files contain email bodies (including temp passwords) — the
    // project root is the webroot, so deny HTTP access to the folder.
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
    return $dir;
}

/** Fire-and-forget launch of the background sender; returns immediately. */
function gjc_spawn_mail_worker(): void
{
    $worker = __DIR__ . '/mail_worker.php';
    $php = PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
    if (!is_file($php)) {
        $php = 'php'; // not a standard layout — trust PATH
    }
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker);
    if (PHP_OS_FAMILY === 'Windows') {
        // start /B detaches without a console window; popen returns at once.
        pclose(popen('start /B "" ' . $cmd . ' >NUL 2>&1', 'r'));
    } else {
        exec($cmd . ' > /dev/null 2>&1 &');
    }
}

/**
 * Queue an email for background delivery and return immediately.
 * Falls back to a synchronous send if the spool cannot be written, so a
 * broken spool never loses an email. Returns true when the message was
 * queued (or sent by the fallback).
 */
function gjc_queue_email(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $altBody = ''
): bool {
    $payload = json_encode([
        'to'              => $toEmail,
        'to_name'         => $toName,
        'subject'         => $subject,
        'body'            => $htmlBody,
        'alt_body'        => $altBody,
        'attempts'        => 0,
        'next_attempt_at' => 0,
        'queued_at'       => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);

    $dir  = gjc_mail_spool_dir();
    $file = $dir . '/mail_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.json';
    if ($payload === false || @file_put_contents($file, $payload, LOCK_EX) === false) {
        return gjc_send_now($toEmail, $toName, $subject, $htmlBody, $altBody);
    }
    gjc_spawn_mail_worker();
    return true;
}

/** Synchronous send — used by the worker's spool-failure fallback only. */
function gjc_send_now(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $altBody = ''
): bool {
    try {
        $mail = gjc_mailer();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($htmlBody);
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('[mailer] send failed for ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Queues the one-stop stall application confirmation: the application was
 * received and a verification meeting has been auto-scheduled. Mirrors the
 * existing "Meeting Scheduled" template and adds the mandatory reminder to
 * bring the ORIGINAL documents to the meeting.
 *
 * Shared by the public submission form (apply.php). Never throws — returns a
 * ['sent' => bool, 'error' => string] result; 'sent' now means the email was
 * queued for immediate background delivery.
 *
 * @return array{sent:bool,error:string}
 */
function gjc_send_stall_meeting_email(
    string $toEmail,
    string $toName,
    string $businessName,
    DateTime $meetingAt,
    string $location,
    string $notes = ''
): array {
    $prettyDate = $meetingAt->format('l, F j, Y');
    $prettyTime = $meetingAt->format('g:i A');
    $safeName   = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $safeBiz    = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');
    $safePlace  = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');

    $body = '
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#f0fdf6;padding:28px;border-radius:14px">
            <h2 style="color:#0e6332;margin-top:0">Application Received - Meeting Scheduled</h2>
            <p style="color:#374151;line-height:1.7">Dear <strong>' . $safeName . '</strong>,</p>
            <p style="color:#374151;line-height:1.7">Your stall application for <strong>' . $safeBiz . '</strong> has been received. A verification meeting has been scheduled for you. Everything - document verification, contract signing, and payment - will be handled in this one meeting.</p>
            <div style="background:#fff;border:1px solid #bbf7d4;border-radius:10px;padding:16px;margin:16px 0">
                <p style="margin:0 0 8px;color:#147b3a;font-weight:700;text-transform:uppercase;font-size:12px">Meeting Details</p>
                <p style="margin:0;color:#111827"><strong>Date:</strong> ' . htmlspecialchars($prettyDate, ENT_QUOTES, 'UTF-8') . '</p>
                <p style="margin:4px 0 0;color:#111827"><strong>Time:</strong> ' . htmlspecialchars($prettyTime, ENT_QUOTES, 'UTF-8') . '</p>
                <p style="margin:4px 0 0;color:#111827"><strong>Location:</strong> ' . $safePlace . '</p>
            </div>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px;margin:16px 0">
                <p style="margin:0;color:#92400e;line-height:1.6"><strong>Important:</strong> Please bring the <strong>original copies</strong> of all documents you uploaded (business permit, sanitary permit, GJC requirements, and clearance) so they can be verified against your submission.</p>
            </div>
            ' . ($notes !== '' ? '<p style="color:#374151;line-height:1.7"><strong>Notes:</strong> ' . nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8')) . '</p>' : '') . '
            <p style="color:#374151;line-height:1.7">If you cannot attend, your application will be cancelled and you are welcome to submit a new one.</p>
            <p style="font-size:12px;color:#6b7280">GenPay Team</p>
        </div>';
    $altBody = "Dear {$toName},\n\n"
        . "Your stall application for {$businessName} has been received and a verification meeting has been scheduled.\n\n"
        . "Meeting schedule:\nDate: {$prettyDate}\nTime: {$prettyTime}\nLocation: {$location}\n\n"
        . "IMPORTANT: Please bring the ORIGINAL copies of all uploaded documents (business permit, sanitary permit, GJC requirements, and clearance) for verification."
        . ($notes !== '' ? "\n\nNotes: {$notes}" : '')
        . "\n\nIf you cannot attend, your application will be cancelled and you may submit a new one.\n\nGenPay Team";

    $queued = gjc_queue_email(
        $toEmail,
        $toName,
        'GenPay - Stall Application Received & Meeting Scheduled',
        $body,
        $altBody
    );
    return ['sent' => $queued, 'error' => $queued ? '' : 'Could not queue the confirmation email.'];
}

/**
 * Queues the "your verification meeting was moved" notice, sent when finance
 * reschedules an applicant's auto-assigned slot. Shows the previous schedule
 * struck out next to the new one and repeats the bring-the-originals reminder.
 *
 * @return array{sent:bool,error:string}
 */
function gjc_send_stall_meeting_reschedule_email(
    string $toEmail,
    string $toName,
    string $businessName,
    DateTime $newMeetingAt,
    DateTime $oldMeetingAt,
    string $location
): array {
    $newDate = $newMeetingAt->format('l, F j, Y');
    $newTime = $newMeetingAt->format('g:i A');
    $oldPretty = $oldMeetingAt->format('l, F j, Y \a\t g:i A');
    $safeName  = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $safeBiz   = htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8');
    $safePlace = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');

    $body = '
        <div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;background:#f0fdf6;padding:28px;border-radius:14px">
            <h2 style="color:#0e6332;margin-top:0">Your Verification Meeting Was Rescheduled</h2>
            <p style="color:#374151;line-height:1.7">Dear <strong>' . $safeName . '</strong>,</p>
            <p style="color:#374151;line-height:1.7">The verification meeting for your stall application for <strong>' . $safeBiz . '</strong> has been moved to a new schedule by our finance office. Everything - document verification, contract signing, and payment - will still be handled in this one meeting.</p>
            <div style="background:#fff;border:1px solid #bbf7d4;border-radius:10px;padding:16px;margin:16px 0">
                <p style="margin:0 0 8px;color:#147b3a;font-weight:700;text-transform:uppercase;font-size:12px">New Meeting Details</p>
                <p style="margin:0;color:#111827"><strong>Date:</strong> ' . htmlspecialchars($newDate, ENT_QUOTES, 'UTF-8') . '</p>
                <p style="margin:4px 0 0;color:#111827"><strong>Time:</strong> ' . htmlspecialchars($newTime, ENT_QUOTES, 'UTF-8') . '</p>
                <p style="margin:4px 0 0;color:#111827"><strong>Location:</strong> ' . $safePlace . '</p>
                <p style="margin:10px 0 0;color:#6b7280;font-size:13px">Previous schedule: <s>' . htmlspecialchars($oldPretty, ENT_QUOTES, 'UTF-8') . '</s></p>
            </div>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px;margin:16px 0">
                <p style="margin:0;color:#92400e;line-height:1.6"><strong>Important:</strong> Please bring the <strong>original copies</strong> of all documents you uploaded (business permit, sanitary permit, GJC requirements, and clearance) so they can be verified against your submission.</p>
            </div>
            <p style="color:#374151;line-height:1.7">If you cannot attend the new schedule, your application will expire and you are welcome to submit a new one.</p>
            <p style="font-size:12px;color:#6b7280">GenPay Team</p>
        </div>';
    $altBody = "Dear {$toName},\n\n"
        . "The verification meeting for your stall application for {$businessName} has been rescheduled.\n\n"
        . "New meeting schedule:\nDate: {$newDate}\nTime: {$newTime}\nLocation: {$location}\n\n"
        . "Previous schedule: {$oldPretty}\n\n"
        . "IMPORTANT: Please bring the ORIGINAL copies of all uploaded documents (business permit, sanitary permit, GJC requirements, and clearance) for verification."
        . "\n\nIf you cannot attend the new schedule, your application will expire and you may submit a new one.\n\nGenPay Team";

    $queued = gjc_queue_email(
        $toEmail,
        $toName,
        'GenPay - Verification Meeting Rescheduled',
        $body,
        $altBody
    );
    return ['sent' => $queued, 'error' => $queued ? '' : 'Could not queue the reschedule email.'];
}

/**
 * Queues the "your application didn't go through, please re-apply" email —
 * shared by an admin's explicit reject and the automatic no-show expiry.
 * Moved here (from admin/api/stall_applications.php) so both call sites use
 * one template instead of two copies drifting apart.
 */
function gjc_send_stall_application_termination_email(array $app, string $heading, string $intro, string $reason): void
{
    $safeName   = htmlspecialchars($app['proprietor_name'], ENT_QUOTES, 'UTF-8');
    $safeReason = htmlspecialchars($reason, ENT_QUOTES, 'UTF-8');
    $applyUrl   = BASE_URL . '/apply';
    $body = '
        <div style="font-family:Arial,sans-serif;max-width:540px;margin:0 auto;padding:28px;background:#fef2f2;border-radius:14px">
            <h3 style="color:#b91c1c;margin-top:0">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h3>
            <p style="color:#374151;line-height:1.7">Dear <strong>' . $safeName . '</strong>,</p>
            <p style="color:#374151;line-height:1.7">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>
            <div style="background:#fff;border:1px solid #fecaca;border-radius:10px;padding:14px;margin:14px 0">
                <p style="margin:0 0 4px;font-size:11px;font-weight:700;color:#b91c1c;text-transform:uppercase">Reason</p>
                <p style="margin:0;color:#374151">' . $safeReason . '</p>
            </div>
            <p style="color:#374151;line-height:1.7">You are welcome to submit a brand-new application anytime. A new verification meeting will be scheduled automatically at submission.</p>
            <p style="color:#374151"><a href="' . $applyUrl . '" style="color:#147b3a;font-weight:700">Submit a new application</a></p>
            <p style="font-size:12px;color:#637168">GenPay Team</p>
        </div>';
    $altBody = "Dear {$app['proprietor_name']},\n\n{$intro}\n\nReason: {$reason}\n\n"
        . "You may submit a brand-new application anytime at {$applyUrl}. A new meeting will be auto-scheduled.\n\nGenPay Team";

    gjc_queue_email($app['email'], $app['proprietor_name'], 'GenPay - Stall Application Update', $body, $altBody);
}
