<?php
// gjc_e() must HTML-escape: 148 templates print user data through it.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/app/format.php';

assert(gjc_e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');
assert(gjc_e('" onclick="x') === '&quot; onclick=&quot;x');
assert(gjc_e("O'Brien & Co") === 'O&#039;Brien &amp; Co');
assert(gjc_e(42) === '42' && gjc_e(null) === '');
echo "ok\n";
