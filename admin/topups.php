<?php
// Top-ups now live on the Cash Management page (Cash In tab).
// Kept as a redirect so bookmarks and old links still land somewhere useful.
require_once __DIR__ . '/../connection/config.php';
header('Location: ' . ADMIN_URL . '/cash_management.php#cash-in', true, 301);
exit;
