<?php
// Encashments now live on the Cash Management page (Cash Out tab).
// Kept as a redirect so bookmarks and old links still land somewhere useful.
require_once __DIR__ . '/../connection/config.php';
header('Location: ' . ADMIN_URL . '/cash_management.php#cash-out', true, 301);
exit;
