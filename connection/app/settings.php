<?php

// App settings stored in app_settings.
// Loaded by connection/app.php; defines functions and constants only.

// ── App settings: finance-editable policy knobs ────────────────────────────
// Deliberately NOT the `system_settings` table — that one is the economy
// singleton (circulation cap + cashier vault), guarded by CHECK constraints and
// DB triggers, and writable only by CirculationEngine. These are plain policy
// numbers finance can retune without a deploy.

/**
 * Canonical keys + fallbacks. A key absent from the DB reads as its default.
 * The GJC_VIOLATION_* constants stay the single source of truth for their own
 * defaults — the settings row only overrides them at runtime.
 */
function gjc_app_setting_defaults(): array
{
    return [
        // Transaction limits (panel 2)
        'transfer_daily_limit'     => 5000.00,
        'transfer_min_amount'      => 1.00,
        'topup_max_per_request'    => 5000.00,
        'wallet_default_spend_cap' => 0.00, // 0 = no default cap on new wallets

        // Merchant restricted-product policy (panel 3) — whole numbers
        'violation_warn_at'      => (float) GJC_VIOLATION_WARN_AT,
        'violation_risk_at'      => (float) GJC_VIOLATION_RISK_AT,
        'violation_suspend_days' => (float) GJC_VIOLATION_SUSPEND_DAYS,

        // Fee waiver credits (panel 4)
        'fee_waiver_max_amount' => 50000.00,

        // Maintenance fee (panel 5) — a percentage of each student POS payment /
        // P2P transfer, billed to tuition (0.1 = 0.1%, not 10%). 0 switches it off.
        'maintenance_fee_rate_pct' => 0.10,
    ];
}

function gjc_ensure_app_settings_schema(PDO $db): void
{
    if (gjc_table_exists($db, 'app_settings')) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException('App settings schema must precede a transaction.');
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key   VARCHAR(64)   NOT NULL,
            setting_value DECIMAL(15,2) NOT NULL,
            updated_by    INT           NULL,
            updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
}

/**
 * All settings, defaults merged under whatever the DB overrides. Cached per
 * request. If the table is unreachable the defaults still come back, so a
 * schema problem loosens nothing — enforcement keeps running on the built-ins.
 */
function gjc_settings_all(PDO $db, bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }

    $cache = gjc_app_setting_defaults();
    try {
        gjc_ensure_app_settings_schema($db);
        $rows = $db->query("SELECT setting_key, setting_value FROM app_settings")
                   ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            if (array_key_exists($key, $cache)) {
                $cache[$key] = (float) $row['setting_value'];
            }
        }
    } catch (Throwable) {
        // Defaults already loaded above.
    }

    return $cache;
}

function gjc_setting(PDO $db, string $key): float
{
    return (float) (gjc_settings_all($db)[$key] ?? gjc_app_setting_defaults()[$key] ?? 0.0);
}

/** Whole-number settings (strike counts, day spans) stored in the same column. */
function gjc_setting_int(PDO $db, string $key): int
{
    return (int) round(gjc_setting($db, $key));
}

function gjc_setting_set(PDO $db, string $key, float $value, int $userId): void
{
    if (!array_key_exists($key, gjc_app_setting_defaults())) {
        throw new RuntimeException("Unknown setting key: {$key}");
    }
    gjc_ensure_app_settings_schema($db);
    $db->prepare(
        "INSERT INTO app_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by    = VALUES(updated_by)"
    )->execute([$key, $value, $userId]);

    gjc_settings_all($db, true); // bust the per-request cache
}
