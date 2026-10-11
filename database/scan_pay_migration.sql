-- ============================================================
--  Scan & Pay migration
--  Documents the merchant_qr_orders table (the payment-request
--  table used by student/scan.php + student/pay_qr.php) and adds
--  short_code: a human-typable fallback code shown beside the QR
--  on the merchant POS, for students whose camera is unavailable.
--
--  Idempotent and MySQL 8 compatible (MySQL 8 has no
--  "ADD COLUMN IF NOT EXISTS", so the ALTERs are guarded through
--  information_schema + prepared statements).
--
--  Run:  mysql -u root -p ewallet < database/scan_pay_migration.sql
--  (The app also self-heals this schema at runtime via
--   gjc_ensure_merchant_qr_orders_schema() in connection/app.php.)
-- ============================================================

CREATE TABLE IF NOT EXISTS merchant_qr_orders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    short_code VARCHAR(12) NULL,
    merchant_wallet_id INT UNSIGNED NOT NULL,
    description TEXT NULL,
    amount DECIMAL(15,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    paid_by INT UNSIGNED NULL,
    paid_ref VARCHAR(40) NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mqo_token (token),
    INDEX idx_mqo_status_expiry (status, expires_at),
    INDEX idx_mqo_merchant_wallet (merchant_wallet_id),
    UNIQUE INDEX uq_mqo_short_code (short_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── short_code column (pre-existing installs) ────────────────
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'merchant_qr_orders'
       AND COLUMN_NAME = 'short_code'
);
SET @ddl := IF(
    @col_exists = 0,
    'ALTER TABLE merchant_qr_orders ADD COLUMN short_code VARCHAR(12) NULL AFTER token',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── unique index on short_code (single-use lookups stay exact) ──
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'merchant_qr_orders'
       AND INDEX_NAME = 'uq_mqo_short_code'
);
SET @ddl := IF(
    @idx_exists = 0,
    'ALTER TABLE merchant_qr_orders ADD UNIQUE INDEX uq_mqo_short_code (short_code)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
