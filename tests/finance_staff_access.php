<?php
// CLI regression: finance staff (roleID 8) map to the finance role but may only
// run the admin scripts on GJC_FINANCE_STAFF_PAGES.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

function staff_expect(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException("FAIL: {$label}"); }
    echo "PASS: {$label}\n";
}

staff_expect(gjc_role_name(8) === 'finance', 'roleID 8 is a finance role');
staff_expect(gjc_sub_role_for_role_id(8) === 'finance_staff', 'roleID 8 is the finance_staff sub-role');
staff_expect(gjc_sub_role_for_role_id(4) === 'super_admin', 'roleID 4 stays super_admin');

foreach (['/genpay/admin/cash_management.php', '/genpay/admin/api/leases.php', '/genpay/admin/users.php', '/genpay/dashboard.php', '/genpay/change_password.php'] as $ok) {
    staff_expect(gjc_finance_staff_may_open($ok), "staff may open {$ok}");
}
foreach (['/genpay/admin/economy.php', '/genpay/admin/api/mint.php', '/genpay/admin/api/users.php',
          '/genpay/admin/settings.php', '/genpay/admin/save_settings.php', '/genpay/admin/maintenance.php', '/genpay/admin/school_years.php',
          '/genpay/admin/audit_log.php', '/genpay/admin/backup.php', '/genpay/admin/api/maintenance_fees.php'] as $no) {
    staff_expect(!gjc_finance_staff_may_open($no), "staff blocked from {$no}");
}
