<?php
// Google reCAPTCHA v2 for the public stall application form.

// Site key is public; the secret is 'recaptcha_secret_key' in local_config.php.
const GJC_RECAPTCHA_SITE_KEY = '6LfaOwMuAAAAAH8DHWuNQTKE0D8Djgx-QsOaqlCu';

/** Verify a reCAPTCHA token. Returns null on success or an error message. Fails closed. */
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
