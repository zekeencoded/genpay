<?php
// ============================================================
//  Google reCAPTCHA v2 (checkbox) for the public stall application form.
// ============================================================

// Public by design: the site key ships in the page. The secret key stays in
// connection/local_config.php as 'recaptcha_secret_key'.
const GJC_RECAPTCHA_SITE_KEY = '6LfaOwMuAAAAAH8DHWuNQTKE0D8Djgx-QsOaqlCu';

/**
 * Checks a reCAPTCHA response token with Google. Returns null when it verifies,
 * otherwise the message to show the applicant. Fails closed: a missing secret
 * or an unreachable Google blocks the submission.
 */
function gjc_verify_recaptcha(string $token): ?string
{
    if ($token === '') {
        return "Please confirm you are not a robot.";
    }
    try {
        $secret = gjc_local_config('recaptcha_secret_key', 'GJC_RECAPTCHA_SECRET');
    } catch (RuntimeException $e) {
        return "Verification is unavailable right now. Please try again later.";
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['secret' => $secret, 'response' => $token, 'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $result = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($result)) {
        error_log('[recaptcha] siteverify request failed');
        return "Could not verify the reCAPTCHA check. Please try again.";
    }
    return empty($result['success']) ? "reCAPTCHA verification failed. Please try again." : null;
}
