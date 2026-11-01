<?php
// Partial: CSRF token for JS (gp_csrf.js). Include once, before scripts that fetch().
$__csrf_token = function_exists('gjc_csrf_token') ? gjc_csrf_token() : '';
?>
<script>window.GJC_CSRF = <?= json_encode($__csrf_token) ?>;</script>
<script src="<?= JS_URL ?>/gp_csrf.js?v=1"></script>
