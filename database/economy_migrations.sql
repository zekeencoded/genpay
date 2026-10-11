-- ============================================================
--  GenPay — Token Economy Additions & Migrations
--  Run ONCE against the 'ewallet' database.
--  Safe to run on a database that already has the base schema.
-- ============================================================

USE ewallet;

-- ────────────────────────────────────────────────────────────
--  ADD mint_pin COLUMN to users table
--  Stores a bcrypt hash. NULL = not yet configured.
--  Required when a super-admin mints above ₱50,000/month.
-- ────────────────────────────────────────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS mint_pin VARCHAR(255) NULL
        COMMENT 'bcrypt hash of the super-admin mint PIN — required above monthly limit'
    AFTER password;


-- ────────────────────────────────────────────────────────────
--  ADD roleID COLUMN to users table (if not already present)
--  1 = Super-Admin, 2 = Admin/Cashier, 3 = Student, 4 = Merchant
-- ────────────────────────────────────────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS roleID TINYINT UNSIGNED NOT NULL DEFAULT 3
        COMMENT '1=SuperAdmin 2=Admin 3=Student 4=Merchant 5=Visitor'
    AFTER id;


-- ────────────────────────────────────────────────────────────
--  ENSURE student_wallets and merchant_wallets exist
--  (idempotent — CREATE TABLE IF NOT EXISTS)
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS student_wallets (
    id         INT           UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT           UNSIGNED NOT NULL UNIQUE,
    balance    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT pk_sw PRIMARY KEY (id),
    CONSTRAINT chk_sw_bal CHECK (balance >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS merchant_wallets (
    id         INT           UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT           UNSIGNED NOT NULL UNIQUE,
    balance    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT pk_mw PRIMARY KEY (id),
    CONSTRAINT chk_mw_bal CHECK (balance >= 0)
) ENGINE=InnoDB;


-- ────────────────────────────────────────────────────────────
--  TRIGGER: Prevent any transaction INSERT that would push
--  total_in_circulation above total_circulation_cap.
--  This is the DB-layer "last line of defence."
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_guard_transaction_cap;

DELIMITER $$

CREATE TRIGGER trg_guard_transaction_cap
BEFORE INSERT ON transactions
FOR EACH ROW
BEGIN
    DECLARE v_cap DECIMAL(15,2);
    SELECT total_circulation_cap INTO v_cap
    FROM system_settings WHERE id = 1;

    -- Allow a 1-cent floating-point tolerance
    IF NEW.total_in_circulation > v_cap + 0.01 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CAP_EXCEEDED: total_in_circulation would exceed total_circulation_cap. Transaction blocked.';
    END IF;
END$$

DELIMITER ;


-- ────────────────────────────────────────────────────────────
--  TRIGGER: Prevent direct balance manipulations that skip
--  the CirculationEngine on student_wallets.
--  Blocks any raw UPDATE that would make student totals
--  exceed the cap without going through the engine.
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_guard_student_balance;

DELIMITER $$

CREATE TRIGGER trg_guard_student_balance
BEFORE UPDATE ON student_wallets
FOR EACH ROW
BEGIN
    DECLARE v_total DECIMAL(15,2);
    DECLARE v_cap   DECIMAL(15,2);

    IF NEW.balance < 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NEGATIVE_BALANCE: student_wallets.balance cannot go below zero.';
    END IF;

    -- Live circulation check
    SELECT
        (SELECT cashier_vault_points FROM system_settings WHERE id = 1)
        + (SELECT COALESCE(SUM(balance), 0) FROM student_wallets WHERE id != NEW.id)
        + NEW.balance
        + COALESCE((SELECT SUM(balance) FROM merchant_wallets), 0)
        + COALESCE((SELECT SUM(remaining_balance) FROM vouchers WHERE status = 'active'), 0)
    INTO v_total;

    SELECT total_circulation_cap INTO v_cap FROM system_settings WHERE id = 1;

    IF v_total > v_cap + 0.01 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CAP_EXCEEDED: This student balance update would violate the circulation cap.';
    END IF;
END$$

DELIMITER ;


-- ────────────────────────────────────────────────────────────
--  TRIGGER: Same guard for merchant_wallets
-- ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS trg_guard_merchant_balance;

DELIMITER $$

CREATE TRIGGER trg_guard_merchant_balance
BEFORE UPDATE ON merchant_wallets
FOR EACH ROW
BEGIN
    DECLARE v_total DECIMAL(15,2);
    DECLARE v_cap   DECIMAL(15,2);

    IF NEW.balance < 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'NEGATIVE_BALANCE: merchant_wallets.balance cannot go below zero.';
    END IF;

    SELECT
        (SELECT cashier_vault_points FROM system_settings WHERE id = 1)
        + COALESCE((SELECT SUM(balance) FROM student_wallets), 0)
        + (SELECT COALESCE(SUM(balance), 0) FROM merchant_wallets WHERE id != NEW.id)
        + NEW.balance
        + COALESCE((SELECT SUM(remaining_balance) FROM vouchers WHERE status = 'active'), 0)
    INTO v_total;

    SELECT total_circulation_cap INTO v_cap FROM system_settings WHERE id = 1;

    IF v_total > v_cap + 0.01 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'CAP_EXCEEDED: This merchant balance update would violate the circulation cap.';
    END IF;
END$$

DELIMITER ;


-- ────────────────────────────────────────────────────────────
--  VIEW: v_circulation_health (replaces v_circulation_snapshot
--  with additional monthly minting metrics)
-- ────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW v_circulation_health AS
SELECT
    ss.total_circulation_cap                        AS cap,
    ss.cashier_vault_points                         AS vault,
    COALESCE(sw.student_total, 0)                   AS student_wallets_total,
    COALESCE(mw.merchant_total, 0)                  AS merchant_wallets_total,
    COALESCE(vo.voucher_total, 0)                   AS active_vouchers_total,

    -- Grand circulation total — must always equal cap
    (ss.cashier_vault_points
     + COALESCE(sw.student_total, 0)
     + COALESCE(mw.merchant_total, 0)
     + COALESCE(vo.voucher_total, 0))               AS total_in_circulation,

    -- Drift must be 0 at all times
    (ss.total_circulation_cap
     - ss.cashier_vault_points
     - COALESCE(sw.student_total, 0)
     - COALESCE(mw.merchant_total, 0)
     - COALESCE(vo.voucher_total, 0))               AS circulation_drift,

    -- Monthly minting stats
    COALESCE(cm.minted_this_month, 0)               AS minted_this_month,
    COALESCE(cm.mint_events, 0)                     AS mint_events_this_month,
    50000.00                                        AS monthly_soft_limit,
    GREATEST(0, 50000.00 - COALESCE(cm.minted_this_month, 0))
                                                    AS remaining_mint_budget,

    ss.updated_at                                   AS as_of

FROM system_settings ss
CROSS JOIN (SELECT SUM(balance)           AS student_total  FROM student_wallets)  sw
CROSS JOIN (SELECT SUM(balance)           AS merchant_total FROM merchant_wallets) mw
CROSS JOIN (SELECT SUM(remaining_balance) AS voucher_total
            FROM vouchers WHERE status = 'active')                                 vo
LEFT  JOIN (
    SELECT
        SUM(new_cap - old_cap) AS minted_this_month,
        COUNT(*)          AS mint_events
    FROM cap_increase_log
    WHERE MONTH(created_at) = MONTH(CURDATE())
      AND YEAR(created_at)  = YEAR(CURDATE())
) cm ON TRUE
WHERE ss.id = 1;
