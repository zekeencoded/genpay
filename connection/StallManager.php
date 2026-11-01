<?php
/** Stall data access: map status, stall details, and 15-minute pending locks. */
class StallManager
{
    private PDO $db;

    /** Number of minutes a pending_application lock holds before auto-expiry */
    const PENDING_MINUTES = 15;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** SQL for a stall's map status; occupancy comes from merchant.stall_id. */
    public static function statusSql(string $stall = 's', string $merchant = 'm'): string
    {
        return "(CASE WHEN {$merchant}.merchantID IS NOT NULL THEN 'occupied' ELSE {$stall}.status END)";
    }

    /** Release expired pending_application locks. Call before rendering stalls.php/apply.php. */
    public function flushExpiredPending(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE stalls
             SET status              = 'vacant',
                 pending_expires_at  = NULL
             WHERE status = 'pending_application'
               AND pending_expires_at IS NOT NULL
               AND pending_expires_at < NOW()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** All stalls in grid order, with occupant names. */
    public function allStalls(): array
    {
        $rows = $this->db->query(
            "SELECT
                s.stall_id,
                s.label,
                s.row_label,
                s.col_number,
                s.area_sqm,
                s.monthly_rate,
                " . self::statusSql('s', 'm') . " AS status,
                m.merchantID   AS merchant_id,
                s.pending_expires_at,
                m.stall_name   AS merchant_stall_name,
                u.profile_img  AS merchant_logo
             FROM stalls s
             LEFT JOIN merchant m ON m.stall_id = s.stall_id
             LEFT JOIN users    u ON u.userID      = m.userID
             ORDER BY s.row_label ASC, s.col_number ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'normaliseStall'], $rows);
    }

    // ── Private helpers ──

    private function normaliseStall(array $row): array
    {
        $expiresAt   = $row['pending_expires_at'] ?? null;
        $secondsLeft = null;

        if ($expiresAt && $row['status'] === 'pending_application') {
            $secondsLeft = max(0, strtotime($expiresAt) - time());
        }

        return [
            'stall_id'             => (string) $row['stall_id'],
            'label'                => (string) $row['label'],
            'row_label'            => (string) $row['row_label'],
            'col_number'           => (int)    $row['col_number'],
            'area_sqm'             => $row['area_sqm'] !== null ? (float) $row['area_sqm'] : null,
            'monthly_rate'         => $row['monthly_rate'] !== null ? (float) $row['monthly_rate'] : null,
            'status'               => (string) $row['status'],
            'merchant_id'          => $row['merchant_id'] !== null ? (int) $row['merchant_id'] : null,
            'pending_expires_at'   => $expiresAt,
            'pending_seconds_left' => $secondsLeft,
            'merchant_stall_name'  => (string) ($row['merchant_stall_name'] ?? ''),
            'merchant_logo'        => (string) ($row['merchant_logo'] ?? ''),
        ];
    }
}
