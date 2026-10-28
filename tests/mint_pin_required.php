<?php
// CLI regression: every mint needs the super-admin's Mint PIN, including amounts
// under the soft limit, and repeated wrong PINs lock the gate. Temporary tables
// stand in for users, settings, the cap log and login_attempts. Each case runs
// inside a transaction so audit entries are queued and discarded, not written.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';
require_once __DIR__ . '/../connection/MintingGuard.php';

function mint_expect(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException("FAIL: {$label}"); }
    echo "PASS: {$label}\n";
}

// Returns the error code of a refused call, or 'accepted'.
function mint_code(callable $call): string
{
    try {
        $call();
        return 'accepted';
    } catch (RuntimeException $e) {
        return explode(':', $e->getMessage(), 2)[0];
    }
}

// Runs $body inside a transaction that is always rolled back.
function mint_isolated(PDO $db, callable $body): void
{
    $db->beginTransaction();
    try {
        $body();
    } finally {
        $db->rollBack();
        $queues = &gjc_audit_queue();
        unset($queues[spl_object_id($db)]);
    }
}

$db->exec('CREATE TEMPORARY TABLE users (
    userID INT PRIMARY KEY, roleID INT NOT NULL, password VARCHAR(255) NULL, mint_pin VARCHAR(255) NULL)');
$db->exec('CREATE TEMPORARY TABLE system_settings (
    id INT PRIMARY KEY, total_circulation_cap DECIMAL(15,2), cashier_vault_points DECIMAL(15,2))');
$db->exec('CREATE TEMPORARY TABLE cap_increase_log (
    id INT AUTO_INCREMENT PRIMARY KEY, super_admin_id INT, old_cap DECIMAL(15,2),
    new_cap DECIMAL(15,2), reason TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TEMPORARY TABLE login_attempts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, login_key CHAR(64) NOT NULL,
    failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (login_key, failed_at)) ENGINE=InnoDB');
// Runs the schema DDL now, outside any test transaction (DDL would commit it).
gjc_ensure_login_attempts_schema($db);

try {
    $pinHash = password_hash('4321', PASSWORD_BCRYPT);
    $passwordHash = password_hash('right-password', PASSWORD_BCRYPT);
    $db->exec('INSERT INTO system_settings VALUES (1, 10000, 10000)');
    $seed = $db->prepare('INSERT INTO users VALUES (?, ?, ?, ?)');
    $seed->execute([1, 3, $passwordHash, $pinHash]);   // super-admin with a Mint PIN
    $seed->execute([2, 3, $passwordHash, null]);       // super-admin with no Mint PIN
    $seed->execute([3, 2, $passwordHash, $pinHash]);   // merchant admin, not allowed to mint

    $mint = static fn(int $adminId, ?string $pin) => static fn() =>
        (new MintingGuard($db))->attemptMint($adminId, 100.0, 'PIN regression', $pin);

    $cases = [
        'no PIN under the soft limit' => [1, null, 'PIN_REQUIRED'],
        'blank PIN'                   => [1, '   ', 'PIN_REQUIRED'],
        'wrong PIN'                   => [1, '0000', 'MINT_PIN_INVALID'],
        'no PIN configured'           => [2, '4321', 'NO_MINT_PIN_SET'],
        'not a super-admin'           => [3, '4321', 'ACCESS_DENIED'],
    ];
    foreach ($cases as $label => [$adminId, $pin, $code]) {
        mint_isolated($db, static function () use ($mint, $adminId, $pin, $code, $label): void {
            mint_expect(mint_code($mint($adminId, $pin)) === $code, "refused: {$label} ({$code})");
        });
    }

    $cap = (float) $db->query('SELECT total_circulation_cap FROM system_settings WHERE id = 1')->fetchColumn();
    mint_expect($cap === 10000.0, 'refused mints leave the circulation cap unchanged');
    mint_expect((int) $db->query('SELECT COUNT(*) FROM cap_increase_log')->fetchColumn() === 0,
        'refused mints write no cap log row');

    // The success path needs the full ledger; this checks the gate alone.
    $verify = new ReflectionMethod(MintingGuard::class, 'verifyMintPin');
    $verify->setAccessible(true);
    $passGate = static fn() => $verify->invoke(new MintingGuard($db), 1, '4321', 0.0, 100.0);
    mint_isolated($db, static function () use ($passGate): void {
        mint_expect(mint_code($passGate) === 'accepted', 'correct Mint PIN passes the gate');
    });

    $lockKey = gjc_throttle_key('mint', '1');
    $failures = static fn(): int => (int) $db->query(
        "SELECT COUNT(*) FROM login_attempts WHERE login_key = " . $db->quote($lockKey))->fetchColumn();

    mint_isolated($db, static function () use ($mint, $passGate, $failures): void {
        for ($i = 0; $i < 4; $i++) { mint_code($mint(1, '0000')); }
        mint_expect($failures() === 4, 'each wrong PIN is counted');
        mint_code($passGate);
        mint_expect($failures() === 0, 'a correct PIN clears earlier wrong PINs');

        for ($i = 0; $i < 5; $i++) { mint_code($mint(1, '0000')); }
        mint_expect(mint_code($mint(1, '4321')) === 'PIN_LOCKED', 'five wrong PINs lock the gate, even for the correct PIN');
        mint_expect(mint_code($mint(2, '4321')) === 'NO_MINT_PIN_SET', 'the lock is per admin: other admins are unaffected');
    });

    mint_isolated($db, static function () use ($db, $mint, $failures): void {
        for ($i = 0; $i < 5; $i++) {
            $code = mint_code(static fn() => (new MintingGuard($db))->setMintPin(1, '5678', 'wrong-password'));
            if ($code !== 'INVALID_PASSWORD') { mint_expect(false, "wrong password refused (got {$code})"); }
        }
        mint_expect($failures() === 5, 'wrong passwords when setting a new PIN are counted');
        mint_expect(mint_code($mint(1, '4321')) === 'PIN_LOCKED', 'password guessing on setMintPin locks minting too');
        mint_expect(
            mint_code(static fn() => (new MintingGuard($db))->setMintPin(1, '5678', 'right-password')) === 'PIN_LOCKED',
            'a locked admin cannot set a new PIN either'
        );
    });

    echo "Mint PIN checks passed.\n";
} finally {
    $db->exec('DROP TEMPORARY TABLE IF EXISTS login_attempts');
    $db->exec('DROP TEMPORARY TABLE IF EXISTS cap_increase_log');
    $db->exec('DROP TEMPORARY TABLE IF EXISTS system_settings');
    $db->exec('DROP TEMPORARY TABLE IF EXISTS users');
}
