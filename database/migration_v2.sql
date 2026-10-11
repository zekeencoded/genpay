-- ============================================================
-- GenPay — Migration v2 (Capstone Refactor)
-- Generated: 2026-06-09
-- Run once on an existing `ewallet` database.
-- All statements are additive / idempotent (IF NOT EXISTS / IF NOT COLUMN EXISTS).
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- ============================================================
-- 1. EXPAND role TABLE — add Super Admin, Merchant Admin, Merchant Staff
-- ============================================================
INSERT IGNORE INTO `role` (`roleID`, `role_name`) VALUES
    (4, 'super_admin'),
    (5, 'merchant_admin'),
    (6, 'merchant_staff');

-- ============================================================
-- 2. ADD sub_role & merchant_owner_id to users
-- ============================================================
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `sub_role` VARCHAR(30) NULL DEFAULT NULL
        COMMENT 'Granular role: super_admin | merchant_admin | merchant_staff | student',
    ADD COLUMN IF NOT EXISTS `merchant_owner_id` INT(10) UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK -> users.userID — links Merchant Staff to their Merchant Admin';

-- Back-fill sub_role for existing users so the soft remap works immediately
UPDATE `users` SET `sub_role` = 'student'      WHERE `roleID` = 1 AND `sub_role` IS NULL;
UPDATE `users` SET `sub_role` = 'merchant_admin' WHERE `roleID` = 2 AND `sub_role` IS NULL;
UPDATE `users` SET `sub_role` = 'super_admin'   WHERE `roleID` = 3 AND `sub_role` IS NULL;

-- ============================================================
-- 3. ADD token_rate & service_fee to system_settings
-- ============================================================
ALTER TABLE `system_settings`
    ADD COLUMN IF NOT EXISTS `token_rate` DECIMAL(10,4) NOT NULL DEFAULT 0.1000
        COMMENT '1 PHP = 0.1 Tokens (₱10 per token). Cosmetic display only.',
    ADD COLUMN IF NOT EXISTS `service_fee` DECIMAL(10,2) NOT NULL DEFAULT 2.00
        COMMENT 'Fee deducted from credited amount on each top-up (₱2)';

-- ============================================================
-- 4. EXTEND transactions.transaction_type enum
-- ============================================================
ALTER TABLE `transactions`
    MODIFY COLUMN `transaction_type`
        ENUM(
            'cash_in','payment','voucher_payment','merchant_settle',
            'voucher_create','voucher_expire','cap_increase',
            'p2p_transfer','service_fee'
        ) NOT NULL;

-- ============================================================
-- 5. CREATE school_revenue_ledger
-- ============================================================
CREATE TABLE IF NOT EXISTS `school_revenue_ledger` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `topup_ref`     VARCHAR(40) NOT NULL COMMENT 'Reference from topup_requests',
    `user_id`       INT UNSIGNED NOT NULL COMMENT 'Student who topped up',
    `fee_amount`    DECIMAL(10,2) NOT NULL DEFAULT 2.00,
    `gross_amount`  DECIMAL(15,2) NOT NULL COMMENT 'Cash paid by student',
    `net_credited`  DECIMAL(15,2) NOT NULL COMMENT 'Tokens credited after fee',
    `credited_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rev_user` (`user_id`),
    KEY `idx_rev_date` (`credited_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Tracks service fee revenue per automated top-up';

-- ============================================================
-- 6. CREATE p2p_transfers
-- ============================================================
CREATE TABLE IF NOT EXISTS `p2p_transfers` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference_no`      VARCHAR(40) NOT NULL,
    `from_wallet_id`    INT UNSIGNED NOT NULL COMMENT 'FK -> student_wallets.id',
    `to_wallet_id`      INT UNSIGNED NOT NULL COMMENT 'FK -> student_wallets.id',
    `amount`            DECIMAL(15,2) NOT NULL,
    `message`           VARCHAR(255) NULL DEFAULT NULL,
    `status`            ENUM('completed','failed','reversed') NOT NULL DEFAULT 'completed',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reference_no` (`reference_no`),
    KEY `idx_p2p_from` (`from_wallet_id`),
    KEY `idx_p2p_to` (`to_wallet_id`),
    KEY `idx_p2p_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Peer-to-peer token transfers between students (atomic, transactionally safe)';

-- ============================================================
-- 7. CREATE merchant_leases
-- ============================================================
CREATE TABLE IF NOT EXISTS `merchant_leases` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `merchant_user_id`  INT UNSIGNED NOT NULL COMMENT 'FK -> users.userID (Merchant Admin)',
    `stall_number`      VARCHAR(30) NOT NULL,
    `stall_name`        VARCHAR(120) NOT NULL,
    `monthly_rent`      DECIMAL(15,2) NOT NULL,
    `deposit_amount`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `lease_start`       DATE NOT NULL,
    `lease_end`         DATE NOT NULL,
    `next_due_date`     DATE NOT NULL,
    `status`            ENUM('active','expired','terminated','pending') NOT NULL DEFAULT 'pending',
    `contract_notes`    TEXT NULL DEFAULT NULL,
    `created_by`        INT UNSIGNED NOT NULL COMMENT 'FK -> users.userID (admin who created)',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lease_merchant` (`merchant_user_id`),
    KEY `idx_lease_status` (`status`),
    KEY `idx_lease_due` (`next_due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Institutional vendor stall lease contracts';

-- ============================================================
-- 8. CREATE merchant_rent_payments
-- ============================================================
CREATE TABLE IF NOT EXISTS `merchant_rent_payments` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lease_id`      INT UNSIGNED NOT NULL COMMENT 'FK -> merchant_leases.id',
    `amount_paid`   DECIMAL(15,2) NOT NULL,
    `period_covered` VARCHAR(20) NOT NULL COMMENT 'e.g. 2026-06',
    `payment_date`  DATE NOT NULL,
    `received_by`   INT UNSIGNED NOT NULL COMMENT 'FK -> users.userID (admin)',
    `reference_no`  VARCHAR(40) NOT NULL,
    `notes`         TEXT NULL DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `reference_no` (`reference_no`),
    KEY `idx_rent_lease` (`lease_id`),
    KEY `idx_rent_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Audit trail of rent payments by merchants';

-- ============================================================
-- 9. CREATE restricted_products
-- ============================================================
CREATE TABLE IF NOT EXISTS `restricted_products` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_name`  VARCHAR(120) NOT NULL COMMENT 'Exact or partial name to match against inventory',
    `category`      VARCHAR(60) NOT NULL DEFAULT 'general'
        COMMENT 'e.g. beverage, snack, junk_food',
    `reason`        VARCHAR(255) NOT NULL COMMENT 'Nutritional / health policy reason',
    `match_type`    ENUM('exact','contains') NOT NULL DEFAULT 'contains'
        COMMENT 'exact=full name match, contains=substring match',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `flagged_by`    INT UNSIGNED NOT NULL COMMENT 'FK -> users.userID (admin)',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rp_name` (`product_name`),
    KEY `idx_rp_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Global nutritional compliance blacklist — blocks merchant inventory encoding';

-- Seed with common restricted items
INSERT IGNORE INTO `restricted_products`
    (`product_name`, `category`, `reason`, `match_type`, `flagged_by`, `created_at`)
SELECT 'Soda', 'beverage', 'High sugar content — DepEd nutritional guidelines', 'contains', userID, NOW()
FROM `users` WHERE `sub_role` = 'super_admin' LIMIT 1;

INSERT IGNORE INTO `restricted_products`
    (`product_name`, `category`, `reason`, `match_type`, `flagged_by`, `created_at`)
SELECT 'Energy Drink', 'beverage', 'High caffeine content — prohibited on campus', 'contains', userID, NOW()
FROM `users` WHERE `sub_role` = 'super_admin' LIMIT 1;

INSERT IGNORE INTO `restricted_products`
    (`product_name`, `category`, `reason`, `match_type`, `flagged_by`, `created_at`)
SELECT 'Junk Food', 'snack', 'Low nutritional value — institutional health guidelines', 'contains', userID, NOW()
FROM `users` WHERE `sub_role` = 'super_admin' LIMIT 1;

-- Extended prohibited items — specific Philippine brands (nutritional + campus-prohibited)
INSERT IGNORE INTO `restricted_products`
    (`product_name`, `category`, `reason`, `match_type`, `flagged_by`, `created_at`)
SELECT t.product_name, t.category, t.reason, t.match_type, u.userID, NOW()
FROM (
              SELECT 'Royal'                AS product_name, 'beverage'  AS category, 'Carbonated soft drink — high sugar content, DepEd nutritional guidelines'  AS reason, 'contains' AS match_type
    UNION ALL SELECT 'Sprite',                            'beverage',  'Carbonated soft drink — high sugar content, DepEd nutritional guidelines',   'contains'
    UNION ALL SELECT 'Mountain Dew',                      'beverage',  'Carbonated soft drink — high sugar content, DepEd nutritional guidelines',   'contains'
    UNION ALL SELECT 'RC Cola',                           'beverage',  'Carbonated soft drink — high sugar content, DepEd nutritional guidelines',   'contains'
    UNION ALL SELECT 'Red Bull',                          'beverage',  'Energy drink — high caffeine and sugar content, prohibited on campus',       'contains'
    UNION ALL SELECT 'Piattos',                           'junk_food', 'Salty snack — high sodium and low nutritional value, health guidelines',     'contains'
    UNION ALL SELECT 'Nova',                              'junk_food', 'Salty snack — high sodium and low nutritional value, health guidelines',     'contains'
    UNION ALL SELECT 'Chippy',                            'junk_food', 'Salty snack — high sodium and low nutritional value, health guidelines',     'contains'
    UNION ALL SELECT 'Nagaraya',                          'junk_food', 'Salty snack — high sodium and low nutritional value, health guidelines',     'contains'
    UNION ALL SELECT 'Lucky Me',                          'snack',     'Instant noodles — high sodium and low nutritional value, health guidelines', 'contains'
    UNION ALL SELECT 'Chocnut',                           'candy',     'Confectionery — high sugar content, DepEd nutritional guidelines',            'contains'
    UNION ALL SELECT 'Cloud 9',                           'candy',     'Chocolate bar — high sugar content, DepEd nutritional guidelines',            'contains'
    UNION ALL SELECT 'Marlboro',                          'general',   'Tobacco product — banned on campus and prohibited for minors (RA 9211)',      'contains'
    UNION ALL SELECT 'Winston',                           'general',   'Tobacco product — banned on campus and prohibited for minors (RA 9211)',      'contains'
    UNION ALL SELECT 'San Miguel Pale Pilsen',           'general',   'Alcoholic beverage — prohibited on campus',                                  'contains'
    UNION ALL SELECT 'Ginebra San Miguel',               'general',   'Alcoholic beverage — prohibited on campus',                                  'contains'
    UNION ALL SELECT 'Emperador',                         'general',   'Alcoholic beverage — prohibited on campus',                                  'contains'
    UNION ALL SELECT 'Tanduay',                           'general',   'Alcoholic beverage — prohibited on campus',                                  'contains'
) AS t
CROSS JOIN (SELECT userID FROM `users` WHERE `sub_role` = 'super_admin' LIMIT 1) AS u;

-- ============================================================
-- 10. CREATE merchant_inventory (detailed catalog)
-- ============================================================
CREATE TABLE IF NOT EXISTS `merchant_inventory` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `merchant_user_id`  INT UNSIGNED NOT NULL COMMENT 'FK -> users.userID (Merchant Admin)',
    `sku`               VARCHAR(50) NULL DEFAULT NULL COMMENT 'Optional stock-keeping unit code',
    `product_name`      VARCHAR(120) NOT NULL,
    `description`       TEXT NULL DEFAULT NULL,
    `category`          VARCHAR(60) NOT NULL DEFAULT 'general',
    `unit`              VARCHAR(30) NOT NULL DEFAULT 'piece'
        COMMENT 'piece, pack, bottle, kg, litre, etc.',
    `price`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `stock_qty`         INT NOT NULL DEFAULT 0,
    `min_stock_alert`   INT NOT NULL DEFAULT 5
        COMMENT 'Low-stock warning threshold',
    `is_available`      TINYINT(1) NOT NULL DEFAULT 1,
    `is_restricted`     TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Set to 1 if matched against restricted_products',
    `restriction_note`  VARCHAR(255) NULL DEFAULT NULL,
    `approved_by`       INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK -> users.userID (admin who cleared item)',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_inv_merchant` (`merchant_user_id`),
    KEY `idx_inv_category` (`category`),
    KEY `idx_inv_restricted` (`is_restricted`),
    KEY `idx_inv_available` (`is_available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Per-merchant detailed product catalog with restriction checking';

-- ============================================================
-- 11. CREATE merchant_applications (onboarding pipeline)
-- ============================================================
CREATE TABLE IF NOT EXISTS `merchant_applications` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `business_name`     VARCHAR(120) NOT NULL,
    `owner_name`        VARCHAR(120) NOT NULL,
    `owner_email`       VARCHAR(255) NOT NULL,
    `owner_contact`     VARCHAR(20) NOT NULL,
    `stall_number`      VARCHAR(30) NULL DEFAULT NULL,
    `stage`             ENUM(
                            'submitted',
                            'compliance_review',
                            'exec_review',
                            'approved',
                            'rejected'
                        ) NOT NULL DEFAULT 'submitted',
    `compliance_notes`  TEXT NULL DEFAULT NULL,
    `exec_notes`        TEXT NULL DEFAULT NULL,
    `compliance_by`     INT UNSIGNED NULL DEFAULT NULL COMMENT 'Admin who did compliance review',
    `compliance_at`     DATETIME NULL DEFAULT NULL,
    `exec_by`           INT UNSIGNED NULL DEFAULT NULL COMMENT 'Super Admin who did exec sign-off',
    `exec_at`           DATETIME NULL DEFAULT NULL,
    `approved_by`       INT UNSIGNED NULL DEFAULT NULL,
    `approved_at`       DATETIME NULL DEFAULT NULL,
    `rejected_by`       INT UNSIGNED NULL DEFAULT NULL,
    `rejected_at`       DATETIME NULL DEFAULT NULL,
    `rejection_reason`  TEXT NULL DEFAULT NULL,
    `generated_user_id` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK -> users.userID — set when account is auto-created on approval',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_app_stage` (`stage`),
    KEY `idx_app_email` (`owner_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Multi-stage merchant onboarding pipeline with compliance and exec sign-off';

-- ============================================================
-- 12. CREATE VIEW v_p2p_transfer_daily_totals (for P2P limit enforcement)
-- ============================================================
CREATE OR REPLACE VIEW `v_p2p_daily_totals` AS
SELECT
    sw.user_id AS `from_user_id`,
    DATE(p.created_at) AS `transfer_date`,
    SUM(p.amount) AS `daily_total`,
    COUNT(*) AS `transfer_count`
FROM `p2p_transfers` p
JOIN `student_wallets` sw ON sw.id = p.from_wallet_id
WHERE p.status = 'completed'
GROUP BY sw.user_id, DATE(p.created_at);

COMMIT;

-- ============================================================
-- END OF MIGRATION v2
-- ============================================================
