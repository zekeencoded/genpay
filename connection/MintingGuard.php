<?php

require_once __DIR__ . '/CirculationEngine.php';

class MintingGuard
{
    
    public const SOFT_LIMIT = 50_000.00;

    
    public const HARD_LIMIT = 500_000.00;

    private CirculationEngine $engine;

    public function __construct(private PDO $db)
    {
        $this->engine = new CirculationEngine($db);
    }
    
    public function attemptMint(
        int $superAdminId,
        float $amount,
        string $reason,
        ?string $pin = null
    ): array {
        $this->assertSuperAdmin($superAdminId);

        if ($amount <= 0) {
            throw new RuntimeException('Mint amount must be greater than zero.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A justification reason is required for all minting operations.');
        }

        $mintedSoFar     = $this->getMintedThisMonth();
        $projectedTotal  = $mintedSoFar + $amount;

        if ($projectedTotal > self::HARD_LIMIT) {
            throw new RuntimeException(sprintf(
                'HARD_LIMIT_EXCEEDED: Monthly minting of Php %s would exceed the absolute ceiling ' .
                'of Php %s/month (already minted Php %s this month). ' .
                'Contact the Board of Administrators to authorize an exceptional increase.',
                number_format($amount, 2),
                number_format(self::HARD_LIMIT, 2),
                number_format($mintedSoFar, 2)
            ));
        }

        $softLimitExceeded = ($projectedTotal > self::SOFT_LIMIT);
        if ($softLimitExceeded) {
            $this->verifyMintPin($superAdminId, $pin, $mintedSoFar, $amount);
        }

        $result = $this->engine->increaseCirculationCap($amount, $superAdminId, $reason);

        return array_merge($result, [
            'success'               => true,
            'minted_this_month'     => $projectedTotal,
            'remaining_soft_limit'  => max(0, self::SOFT_LIMIT - $projectedTotal),
            'soft_limit_exceeded'   => $softLimitExceeded,
            'mint_events_this_month'=> $this->getMintEventCountThisMonth(),
        ]);
    }
    
    
    public function setMintPin(int $superAdminId, string $newPin, string $currentPassword): bool
    {
        $this->assertSuperAdmin($superAdminId);

        $stmt = $this->db->prepare("SELECT password FROM users WHERE userID = ?");
        $stmt->execute([$superAdminId]);
        $hash = $stmt->fetchColumn();

        
        $passwordValid = ($currentPassword === $hash) || password_verify($currentPassword, $hash);
        if (!$passwordValid) {
            throw new RuntimeException('INVALID_PASSWORD: Current account password is incorrect.');
        }

        if (strlen($newPin) < 4 || strlen($newPin) > 12) {
            throw new RuntimeException('PIN must be between 4 and 12 characters.');
        }

        $pinHash = password_hash($newPin, PASSWORD_BCRYPT);
        $this->db->prepare("UPDATE users SET mint_pin = ? WHERE userID = ?")->execute([$pinHash, $superAdminId]);

        return true;
    }
    
    
    public function getMonthlyMintingReport(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(amount_added), 0)  AS minted_this_month,
                COUNT(*)                         AS mint_events,
                MIN(created_at)                  AS first_mint_at,
                MAX(created_at)                  AS last_mint_at
            FROM cap_increase_log
            WHERE MONTH(created_at) = MONTH(CURDATE())
              AND YEAR(created_at)  = YEAR(CURDATE())
        ");
        $stmt->execute();
        $row = $stmt->fetch();

        $minted = (float)$row['minted_this_month'];

        return [
            'minted_this_month'      => $minted,
            'mint_events'            => (int)$row['mint_events'],
            'first_mint_at'          => $row['first_mint_at'],
            'last_mint_at'           => $row['last_mint_at'],
            'soft_limit'             => self::SOFT_LIMIT,
            'hard_limit'             => self::HARD_LIMIT,
            'remaining_soft_limit'   => max(0, self::SOFT_LIMIT - $minted),
            'soft_limit_used_pct'    => min(100, round(($minted / self::SOFT_LIMIT) * 100, 1)),
            'soft_limit_exceeded'    => $minted >= self::SOFT_LIMIT,
            'hard_limit_exceeded'    => $minted >= self::HARD_LIMIT,
            'requires_pin'           => $minted >= self::SOFT_LIMIT,
        ];
    }

    
    
    private function assertSuperAdmin(int $userId): void
    {
        $stmt = $this->db->prepare("SELECT sub_role FROM users WHERE userID = ?");
        $stmt->execute([$userId]);
        $subRole = $stmt->fetchColumn();
        if ($subRole !== 'super_admin') {
            throw new RuntimeException('ACCESS_DENIED: Only Super-Admins can mint points.');
        }
    }

    private function getMintedThisMonth(): float
    {
        $stmt = $this->db->query("
            SELECT COALESCE(SUM(amount_added), 0)
            FROM cap_increase_log
            WHERE MONTH(created_at) = MONTH(CURDATE())
              AND YEAR(created_at)  = YEAR(CURDATE())
        ");
        return (float)$stmt->fetchColumn();
    }

    private function getMintEventCountThisMonth(): int
    {
        $stmt = $this->db->query("
            SELECT COUNT(*)
            FROM cap_increase_log
            WHERE MONTH(created_at) = MONTH(CURDATE())
              AND YEAR(created_at)  = YEAR(CURDATE())
        ");
        return (int)$stmt->fetchColumn();
    }

    private function verifyMintPin(int $adminId, ?string $pin, float $minted, float $requested): void
    {
        if ($pin === null || trim($pin) === '') {
            throw new RuntimeException(sprintf(
                'PIN_REQUIRED: Monthly minting has reached Php %s. ' .
                'Your requested Php %s would exceed the soft limit of Php %s/month. ' .
                'Provide your Mint PIN to authorize this exceptional increase.',
                number_format($minted, 2),
                number_format($requested, 2),
                number_format(self::SOFT_LIMIT, 2)
            ));
        }

        $stmt = $this->db->prepare("SELECT mint_pin FROM users WHERE userID = ?");
        $stmt->execute([$adminId]);
        $hash = $stmt->fetchColumn();

        if (!$hash) {
            throw new RuntimeException(
                'NO_MINT_PIN_SET: You have not configured a Mint PIN yet. ' .
                'Set one in Super-Admin Settings before minting above the monthly limit.'
            );
        }

        if (!password_verify($pin, $hash)) {
            
            error_log("[MintingGuard] Failed PIN attempt by admin #{$adminId} at " . date('Y-m-d H:i:s'));
            throw new RuntimeException('MINT_PIN_INVALID: The Mint PIN you entered is incorrect.');
        }
    }
}

