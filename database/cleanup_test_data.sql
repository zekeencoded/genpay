-- Wipe test data from ewalletV2, keep setup + admin accounts.
-- Keeps: users with roleID 4 (finance) / 8 (finance_staff), role, app_settings, course,
--        school_years, stalls, address_locations, restricted_products, meeting_settings/holidays.
-- Wipes: every student, parent, merchant (+ staff), wallet, transaction, order, fee,
--        stall application, notification, and the audit trail.
--
-- BACK UP FIRST — TRUNCATE auto-commits, there is no rollback:
--   mysqldump -uroot -p ewalletV2 > ewalletV2_before_cleanup.sql
--
-- Note: genpayV2 and older copies also use ewalletV2, so they get wiped too.
-- Uploaded files (profile images, merchant/stall documents) stay on disk.

USE ewalletV2;
SET FOREIGN_KEY_CHECKS = 0;

-- Orders & transactions
TRUNCATE TABLE cart_order_items;
TRUNCATE TABLE cart_orders;
TRUNCATE TABLE qr_order_items;
TRUNCATE TABLE merchant_qr_orders;
TRUNCATE TABLE transaction_items;
TRUNCATE TABLE p2p_transfers;
TRUNCATE TABLE transactions;
TRUNCATE TABLE encashment_requests;
TRUNCATE TABLE sales_remittances;

-- Fees
TRUNCATE TABLE maintenance_fees;
TRUNCATE TABLE maintenance_fee_collections;
TRUNCATE TABLE fee_waiver_credit_logs;
TRUNCATE TABLE fee_waiver_credits;
TRUNCATE TABLE student_fees;

-- Wallets
TRUNCATE TABLE student_wallets;
TRUNCATE TABLE parent_wallets;
TRUNCATE TABLE merchant_wallets;
TRUNCATE TABLE school_year_balances;

-- Students & parents
TRUNCATE TABLE student_info;
TRUNCATE TABLE student_enrollments;
TRUNCATE TABLE enrollment_roster_staging;
TRUNCATE TABLE imported_student_registry;
TRUNCATE TABLE parents;
TRUNCATE TABLE parent_student_links;
TRUNCATE TABLE parent_link_requests;
TRUNCATE TABLE parent_alerts;

-- Merchants & stall applications
TRUNCATE TABLE merchant;
TRUNCATE TABLE merchant_inventory;
TRUNCATE TABLE merchant_card_views;
TRUNCATE TABLE merchant_leases;
TRUNCATE TABLE merchant_rent_payments;
TRUNCATE TABLE stall_application_addresses;
TRUNCATE TABLE stall_application_applicants;
TRUNCATE TABLE stall_application_contracts;
TRUNCATE TABLE stall_application_decisions;
TRUNCATE TABLE stall_application_documents;
TRUNCATE TABLE stall_application_meetings;
TRUNCATE TABLE stall_application_payments;
TRUNCATE TABLE stall_application_rejections;
TRUNCATE TABLE stall_applications;
UPDATE stalls SET status = 'vacant', pending_expires_at = NULL;

-- Account activity & logs (TRUNCATE skips the audit trail's no-delete trigger)
TRUNCATE TABLE notifications;
TRUNCATE TABLE auth_remember_tokens;
TRUNCATE TABLE login_attempts;
TRUNCATE TABLE user_suspensions;
TRUNCATE TABLE user_violations;
TRUNCATE TABLE systemic_audit_trail;

-- Users: keep admins only
DELETE FROM user_addresses WHERE user_id IN (SELECT userID FROM users WHERE roleID NOT IN (4, 8));
DELETE FROM users WHERE roleID NOT IN (4, 8);

-- Economy: no coins in circulation, so vault = cap. Back to the original 200,000 cap
-- (first old_cap in cap_increase_log) since the increases were test mints/fee waivers.
TRUNCATE TABLE cap_increase_log;
UPDATE system_settings SET total_circulation_cap = 200000.00, cashier_vault_points = 200000.00 WHERE id = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- Sanity check: expect 2 users, 0 everywhere else, vault = cap
SELECT (SELECT COUNT(*) FROM users) AS users,
       (SELECT COUNT(*) FROM transactions) AS transactions,
       (SELECT COUNT(*) FROM student_wallets) AS student_wallets,
       total_circulation_cap, cashier_vault_points
FROM system_settings WHERE id = 1;
