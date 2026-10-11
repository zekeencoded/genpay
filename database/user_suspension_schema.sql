-- Canonical PHP definition: gjc_ensure_user_suspensions_schema().
CREATE TABLE IF NOT EXISTS user_suspensions (
    user_id INT NOT NULL,
    suspended_until DATETIME NULL,
    suspension_reason VARCHAR(255) NULL,
    suspended_at DATETIME NULL,
    suspended_by INT NULL,
    suspension_prev_status VARCHAR(20) NULL,
    suspension_froze_wallet TINYINT(1) NOT NULL DEFAULT 0,
    lockout_kind VARCHAR(12) NULL,
    PRIMARY KEY (user_id),
    KEY idx_user_suspension_actor (suspended_by),
    CONSTRAINT fk_user_suspension_user FOREIGN KEY (user_id)
        REFERENCES users(userID) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_user_suspension_actor FOREIGN KEY (suspended_by)
        REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

