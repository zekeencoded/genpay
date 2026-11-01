<?php
// Background sender for the email spool (CLI only, single instance).
// Retries 3 times, 15s apart; failures go to storage/mail_spool/failed/.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/mailer.php';

const MAIL_MAX_ATTEMPTS = 3;
const MAIL_RETRY_DELAY  = 15;   // seconds between attempts on one message
const MAIL_WORKER_TTL   = 120;  // max seconds one worker instance lives

$spool     = gjc_mail_spool_dir();
$failedDir = $spool . '/failed';
if (!is_dir($failedDir)) {
    @mkdir($failedDir, 0755, true);
}

function mail_worker_log(string $msg): void
{
    @file_put_contents(
        gjc_mail_spool_dir() . '/mail.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// Single instance: the running worker picks up new files.
$lock = fopen($spool . '/worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

// Requeue stale *.json.sending files from a crash.
foreach (glob($spool . '/mail_*.json.sending') ?: [] as $stale) {
    if ((int) @filemtime($stale) < time() - 300) {
        @rename($stale, substr($stale, 0, -8));
    }
}

// Reuse one SMTP connection for the whole run.
$mailer = gjc_mailer();
$mailer->SMTPKeepAlive = true;

$deadline = time() + MAIL_WORKER_TTL;
while (time() < $deadline) {
    $pending = glob($spool . '/mail_*.json') ?: [];
    if (!$pending) {
        // Catch files spooled while finishing up.
        sleep(2);
        if (!glob($spool . '/mail_*.json')) {
            break;
        }
        continue;
    }

    $sentSomething = false;
    $waitingOnRetry = false;
    foreach ($pending as $file) {
        $job = json_decode((string) @file_get_contents($file), true);
        if (!is_array($job) || empty($job['to'])) {
            @rename($file, $failedDir . '/' . basename($file));
            mail_worker_log('unreadable job parked: ' . basename($file));
            continue;
        }
        if ((int) ($job['next_attempt_at'] ?? 0) > time()) {
            $waitingOnRetry = true;
            continue;
        }

        // Atomic claim so two workers can never double-send one message.
        $claimed = $file . '.sending';
        if (!@rename($file, $claimed)) {
            continue;
        }

        try {
            $mailer->clearAllRecipients();
            $mailer->addAddress((string) $job['to'], (string) ($job['to_name'] ?? ''));
            $mailer->Subject = (string) ($job['subject'] ?? '');
            $mailer->Body    = (string) ($job['body'] ?? '');
            $mailer->AltBody = (string) ($job['alt_body'] ?? '');
            $mailer->send();
            @unlink($claimed);
            mail_worker_log('sent to ' . $job['to'] . ' — ' . $job['subject']);
            $sentSomething = true;
        } catch (Throwable $e) {
            $job['attempts']   = (int) ($job['attempts'] ?? 0) + 1;
            $job['last_error'] = $e->getMessage();
            if ($job['attempts'] >= MAIL_MAX_ATTEMPTS) {
                @file_put_contents($failedDir . '/' . basename($file), json_encode($job, JSON_UNESCAPED_UNICODE));
                @unlink($claimed);
                mail_worker_log('FAILED after ' . $job['attempts'] . ' attempts: ' . $job['to'] . ' — ' . $e->getMessage());
            } else {
                $job['next_attempt_at'] = time() + MAIL_RETRY_DELAY;
                @file_put_contents($file, json_encode($job, JSON_UNESCAPED_UNICODE));
                @unlink($claimed);
                $waitingOnRetry = true;
                mail_worker_log('retry ' . $job['attempts'] . '/' . MAIL_MAX_ATTEMPTS . ' for ' . $job['to'] . ' — ' . $e->getMessage());
            }
            // The shared connection may be wedged after an error — start clean.
            try { $mailer->smtpClose(); } catch (Throwable $ignored) {}
        }
    }

    if (!$sentSomething && $waitingOnRetry) {
        sleep(3); // everything pending is backing off — idle briefly
    }
}

try { $mailer->smtpClose(); } catch (Throwable $ignored) {}
flock($lock, LOCK_UN);
