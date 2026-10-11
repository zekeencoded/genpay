-- Normalized optional user addresses; existing users columns remain until CONTRACT.
-- Canonical PHP definition: gjc_ensure_user_address_schema().
CREATE TABLE IF NOT EXISTS address_locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    location_kind ENUM('province', 'city', 'barangay') NOT NULL,
    label VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_address_location_scope (location_kind, (COALESCE(parent_id, 0)), label),
    KEY idx_address_location_parent (parent_id),
    CONSTRAINT fk_address_location_parent FOREIGN KEY (parent_id)
        REFERENCES address_locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_address_province_root CHECK (location_kind <> 'province' OR parent_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS user_addresses (
    user_id INT NOT NULL,
    location_id INT UNSIGNED NULL,
    PRIMARY KEY (user_id),
    KEY idx_user_address_location (location_id),
    CONSTRAINT fk_user_address_user FOREIGN KEY (user_id)
        REFERENCES users(userID) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_user_address_location FOREIGN KEY (location_id)
        REFERENCES address_locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

