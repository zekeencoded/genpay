<?php
// Partial: shared feedback + loading-state scripts (footer).
//
// One include gives a page the whole "talk back to the user" layer:
//   gpToast(msg, ok)        — the single toast, styled from the palette
//   gpConfirm(opts)         — Promise<bool>; what / how much / to whom / undoable
//   gpFetchJson(url, body)  — never throws, never leaks a PHP fatal to the screen
//   gpMoney(pesos)          — the JS twin of gjc_money()
//   gpEsc(value)            — HTML escape
//   gpBusy / gpBusyDone     — the pre-existing loading-state helper
//
// Include just before the closing </body>, AFTER bootstrap.bundle.min.js
// (gpToast and gpConfirm construct Bootstrap Toast/Modal instances).
// The toast container is created on demand, so there is no markup to add.
//
// Styles live in assets/css/theme.css ("FEEDBACK — toast + confirm dialog"),
// which every page reaches through its role stylesheet's @import chain.
?>
<script src="<?= JS_URL ?>/gp_feedback.js?v=1"></script>
<script src="<?= JS_URL ?>/gp_form_busy.js?v=1"></script>
