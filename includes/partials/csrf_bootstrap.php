<?php
// Partial: exposes the session CSRF token to JavaScript and attaches it to every
// same-origin POST (assets/js/gp_csrf.js). Include it once per page, before any
// script that calls fetch(). The server side is gjc_csrf_require_json().
$__csrf_token = function_exists('gjc_csrf_token') ? gjc_csrf_token() : '';
?>
<script>window.GJC_CSRF = <?= json_encode($__csrf_token) ?>;</script>
<script src="<?= JS_URL ?>/gp_csrf.js?v=1"></script>
