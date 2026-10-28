<?php
// CLI regression: the stall application form rejects a submission with no
// reCAPTCHA token before any network call is made to Google.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/app.php';

function recaptcha_expect(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException("FAIL: {$label}"); }
    echo "PASS: {$label}\n";
}

recaptcha_expect(
    gjc_verify_recaptcha('') === 'Please confirm you are not a robot.',
    'an empty token is rejected without contacting Google'
);
recaptcha_expect(
    str_starts_with(GJC_RECAPTCHA_SITE_KEY, '6L'),
    'the site key constant is set'
);
