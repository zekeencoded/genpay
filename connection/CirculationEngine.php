<?php

require_once __DIR__ . "/pdo.php";
require_once __DIR__ . "/audit_logger.php";

class CirculationEngine
{
    private PDO $db;

    const TXN_CASH_IN = "cash_in";
    const TXN_PAYMENT = "payment";
    const TXN_MERCHANT_SETTLE = "merchant_settle";
    const TXN_STUDENT_WITHDRAW = "student_withdraw";
    const TXN_CAP_INCREASE = "cap_increase";
    const TXN_ALLOWANCE = "allowance";
    const TXN_FEE_WAIVER_CLAIM = "fee_waiver_claim";

    // Read-only history. The visitor voucher feature was removed, so nothing
    // writes these any more, but rows carrying them stay in the ledger forever
    // — merchant/dashboard.php and merchant/api/pos.php still count
    // TXN_VOUCHER_PAYMENT toward historical earnings, and the labels in
    // connection/app.php still render them in transaction views. Do not reuse
    // these strings for anything else.
    const TXN_VOUCHER_PAYMENT = "voucher_payment";
    const TXN_VOUCHER_CREATE = "voucher_create";
    const TXN_VOUCHER_EXPIRE = "voucher_expire";

    // ── Service fees ──────────────────────────────────────────────────────
    // Fees are ADDED ON TOP of the requested amount, never deducted from it.
    // The amount passed to the fee-charging methods is the amount the RECIPIENT
    // RECEIVES; the payer hands over that base plus both fees.
    //
    //   base ₱1,000  →  finance fee ₱10 (1%) + merchant fee ₱20 (2%)
    //                →  recipient is credited ₱1,000
    //                →  payer is charged     ₱1,030
    const FEE_SYSTEM_RATE = 0.01; // 1 % → school revenue, settles into the vault
    const FEE_MERCHANT_RATE = 0.02; // 2 % → merchant wallet (merchant route only)

    public function __construct(PDO $pdo)
    {
        $this->db = $pdo;
    }

    /**
     * Claims only an unfrozen student-wallet flag for the account-lockout caller.
     * Uses the caller's PDO/transaction; changes no balance or ledger.
     */
    public function freezeStudentWalletForLockout(int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE student_wallets SET is_frozen = 1 WHERE user_id = ? AND is_frozen = 0");
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    /** Caller must have established suspension freeze ownership before release. */
    public function unfreezeStudentWalletForLockout(int $userId): void
    {
        $this->db->prepare("UPDATE student_wallets SET is_frozen = 0 WHERE user_id = ?")->execute([$userId]);
    }

    /**
     * "1%" / "2%" straight off the rate constant. Every user-facing fee label
     * goes through this so a label can never drift from the maths again — the
     * old hardcoded "2%"/"1%" strings were both wrong by a factor of ten.
     */
    public static function ratePct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2, ".", ""), "0"), ".") . "%";
    }

    /**
     * Fee breakdown for a base amount. `total` is what the payer hands over.
     * Single source of truth for the arithmetic in all four fee-charging paths.
     */
    public static function feeBreakdown(float $baseAmount, bool $withMerchantFee): array
    {
        $systemFee = round($baseAmount * self::FEE_SYSTEM_RATE, 2);
        $merchantFee = $withMerchantFee
            ? round($baseAmount * self::FEE_MERCHANT_RATE, 2)
            : 0.0;

        return [
            "base" => round($baseAmount, 2),
            "system_fee" => $systemFee,
            "merchant_fee" => $merchantFee,
            "fee_total" => round($systemFee + $merchantFee, 2),
            "total" => round($baseAmount + $systemFee + $merchantFee, 2),
        ];
    }

    /**
     * Top-up with tiered service fee.
     *
     * source = 'finance'  → 2 % system fee, 0 % merchant fee
     * source = 'merchant' → 2 % system fee, 1 % merchant fee (credited to merchant wallet)
     *
     * Circulation stays balanced: vault drains by (credited + merchantFee);
     * systemFee remains in vault as accumulated revenue.
     */
    public function cashInWithFee(
        int $studentWalletId,
        float $cashAmount,
        string $source,
        int $initiatedBy,
        int $merchantWalletId = 0,
        string $notes = "",
    ): array {
        $this->assertPositive($cashAmount);

        if (!in_array($source, ["finance", "merchant"], true)) {
            throw new \InvalidArgumentException(
                "Invalid top-up source: {$source}",
            );
        }

        // Fees ride on top: $cashAmount is what the student receives, and the
        // cashier collects $totalCollected from them.
        $fees = self::feeBreakdown($cashAmount, $source === "merchant");
        $systemFee = $fees["system_fee"];
        $merchantFee = $fees["merchant_fee"];
        $credited = $fees["base"];
        $totalCollected = $fees["total"];
        // Vault pays out: student credit + merchant cut. The system fee is cash
        // the school keeps — no points move for it.
        $vaultDrain = round($credited + $merchantFee, 2);

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();

            if ($settings["cashier_vault_points"] < $vaultDrain) {
                throw new \RuntimeException(
                    "VAULT_INSUFFICIENT: Vault has ₱" .
                        number_format($settings["cashier_vault_points"], 2) .
                        " — cannot credit ₱" .
                        number_format($credited, 2) .
                        " (needs ₱" .
                        number_format($vaultDrain, 2) .
                        " including the merchant cut).",
                );
            }

            // 1. Vault drains by (credited + merchantFee); systemFee stays in vault
            $this->db
                ->prepare(
                    "UPDATE system_settings
                    SET cashier_vault_points = cashier_vault_points - ?
                  WHERE id = 1",
                )
                ->execute([$vaultDrain]);

            // 2. Credit student wallet
            $this->db
                ->prepare(
                    "UPDATE student_wallets SET balance = balance + ? WHERE id = ?",
                )
                ->execute([$credited, $studentWalletId]);

            // 3. Credit merchant wallet (1 % cut on merchant route)
            if ($merchantFee > 0 && $merchantWalletId > 0) {
                $this->db
                    ->prepare(
                        "UPDATE merchant_wallets SET balance = balance + ? WHERE id = ?",
                    )
                    ->execute([$merchantFee, $merchantWalletId]);
            }

            $vaultAfter = $settings["cashier_vault_points"] - $vaultDrain;

            $this->validateCirculation($settings["total_circulation_cap"]);

            $feeNote =
                "Top-up via {$source}. Credited: ₱{$credited}, " .
                "System fee (" . self::ratePct(self::FEE_SYSTEM_RATE) . "): ₱{$systemFee}" .
                ($merchantFee > 0
                    ? ", Merchant fee (" . self::ratePct(self::FEE_MERCHANT_RATE) . "): ₱{$merchantFee}"
                    : "") .
                ", Collected: ₱{$totalCollected}." .
                ($notes !== "" ? " Note: {$notes}" : "");

            $ref = $this->logTransaction(
                self::TXN_CASH_IN,
                $initiatedBy,
                $credited,
                $settings["cashier_vault_points"],
                $vaultAfter,
                studentWalletId: $studentWalletId,
                merchantWalletId: $merchantFee > 0 ? $merchantWalletId : null,
                notes: $feeNote,
                topUpSource: $source,
                baseAmount: $credited,
                feeAmount: $fees["fee_total"],
                creditedAmount: $credited,
            );

            $this->logFeeRevenue(
                $ref,
                $source,
                $totalCollected,
                $systemFee,
                $merchantFee,
                $merchantFee > 0 ? $merchantWalletId : null,
                $initiatedBy,
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return [
                "success" => true,
                "reference" => $ref,
                "vault_after" => $vaultAfter,
                "cash_amount" => $credited,
                "total_collected" => $totalCollected,
                "system_fee" => $systemFee,
                "merchant_fee" => $merchantFee,
                "fee_amount" => $fees["fee_total"],
                "credited_amount" => $credited,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Parent-wallet top-up with the same tiered service fee as cashInWithFee().
     * Reuses TXN_CASH_IN (not a new enum value) — ownership is disambiguated
     * by parent_wallet_id being set instead of student_wallet_id on the row,
     * same convention as the existing merchant-fee attribution.
     */
    public function cashInParent(
        int $parentWalletId,
        float $cashAmount,
        string $source,
        int $initiatedBy,
        int $merchantWalletId = 0,
        string $notes = "",
    ): array {
        $this->assertPositive($cashAmount);

        if (!in_array($source, ["finance", "merchant"], true)) {
            throw new \InvalidArgumentException(
                "Invalid top-up source: {$source}",
            );
        }

        // Fees ride on top — see cashInWithFee().
        $fees = self::feeBreakdown($cashAmount, $source === "merchant");
        $systemFee = $fees["system_fee"];
        $merchantFee = $fees["merchant_fee"];
        $credited = $fees["base"];
        $totalCollected = $fees["total"];
        $vaultDrain = round($credited + $merchantFee, 2);

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();

            if ($settings["cashier_vault_points"] < $vaultDrain) {
                throw new \RuntimeException(
                    "VAULT_INSUFFICIENT: Vault has ₱" .
                        number_format($settings["cashier_vault_points"], 2) .
                        " — cannot credit ₱" .
                        number_format($credited, 2) .
                        " (needs ₱" .
                        number_format($vaultDrain, 2) .
                        " including the merchant cut).",
                );
            }

            $this->db
                ->prepare(
                    "UPDATE system_settings
                    SET cashier_vault_points = cashier_vault_points - ?
                  WHERE id = 1",
                )
                ->execute([$vaultDrain]);

            $this->db
                ->prepare(
                    "UPDATE parent_wallets SET balance = balance + ? WHERE id = ?",
                )
                ->execute([$credited, $parentWalletId]);

            if ($merchantFee > 0 && $merchantWalletId > 0) {
                $this->db
                    ->prepare(
                        "UPDATE merchant_wallets SET balance = balance + ? WHERE id = ?",
                    )
                    ->execute([$merchantFee, $merchantWalletId]);
            }

            $vaultAfter = $settings["cashier_vault_points"] - $vaultDrain;

            $this->validateCirculation($settings["total_circulation_cap"]);

            $feeNote =
                "Parent top-up via {$source}. Credited: ₱{$credited}, " .
                "System fee (" . self::ratePct(self::FEE_SYSTEM_RATE) . "): ₱{$systemFee}" .
                ($merchantFee > 0
                    ? ", Merchant fee (" . self::ratePct(self::FEE_MERCHANT_RATE) . "): ₱{$merchantFee}"
                    : "") .
                ", Collected: ₱{$totalCollected}." .
                ($notes !== "" ? " Note: {$notes}" : "");

            $ref = $this->logTransaction(
                self::TXN_CASH_IN,
                $initiatedBy,
                $credited,
                $settings["cashier_vault_points"],
                $vaultAfter,
                parentWalletId: $parentWalletId,
                merchantWalletId: $merchantFee > 0 ? $merchantWalletId : null,
                notes: $feeNote,
                topUpSource: $source,
                baseAmount: $credited,
                feeAmount: $fees["fee_total"],
                creditedAmount: $credited,
            );

            $this->logFeeRevenue(
                $ref,
                $source,
                $totalCollected,
                $systemFee,
                $merchantFee,
                $merchantFee > 0 ? $merchantWalletId : null,
                $initiatedBy,
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return [
                "success" => true,
                "reference" => $ref,
                "vault_after" => $vaultAfter,
                "cash_amount" => $credited,
                "total_collected" => $totalCollected,
                "system_fee" => $systemFee,
                "merchant_fee" => $merchantFee,
                "fee_amount" => $fees["fee_total"],
                "credited_amount" => $credited,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Pay one server-recorded POS QR bill atomically. Client amounts are never used.
     */
    public function payPosQr(string $payerRole, int $payerUserId, string $token): array
    {
        if (!in_array($payerRole, ['student', 'parent'], true) || $payerUserId <= 0
            || !preg_match('/^[a-f0-9]{32,64}$/i', $token) || $this->db->inTransaction()) {
            throw new RuntimeException('Invalid POS payment request.');
        }
        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();
            $stmt = $this->db->prepare("SELECT id, merchant_wallet_id, amount, status, expires_at,
                (expires_at <= NOW()) AS has_expired FROM merchant_qr_orders WHERE token = ? FOR UPDATE");
            $stmt->execute([strtolower($token)]);
            $order = $stmt->fetch();
            if (!$order || $order['status'] !== 'pending' || (int) $order['has_expired'] === 1) {
                throw new RuntimeException('This POS payment QR is unknown, expired, voided, or already paid.');
            }
            $merchantStmt = $this->db->prepare('SELECT id, user_id FROM merchant_wallets WHERE id = ? FOR UPDATE');
            $merchantStmt->execute([(int) $order['merchant_wallet_id']]);
            $merchant = $merchantStmt->fetch();
            if (!$merchant || gjc_merchant_sales_blocked($this->db, (int) $merchant['user_id'])) {
                throw new RuntimeException('This merchant cannot accept payments right now.');
            }
            $lines = gjc_order_item_lines($this->db, 'qr', (int) $order['id']);
            if (!$lines) {
                throw new RuntimeException('This POS bill has no recorded items.');
            }
            $amount = gjc_order_item_total($lines);
            $this->assertPositive($amount);
            if (abs($amount - (float) $order['amount']) > 0.000001) {
                throw new RuntimeException('POS bill items do not match its recorded amount.');
            }
            if ($payerRole === 'student') {
                $payerStmt = $this->db->prepare('SELECT id, balance, is_frozen, daily_spend_limit
                    FROM student_wallets WHERE user_id = ? FOR UPDATE');
            } else {
                $payerStmt = $this->db->prepare('SELECT pw.id, pw.balance FROM parent_wallets pw
                    JOIN parents p ON p.id = pw.parent_id WHERE p.user_id = ? FOR UPDATE');
            }
            $payerStmt->execute([$payerUserId]);
            $payer = $payerStmt->fetch();
            if (!$payer || (float) $payer['balance'] < $amount) {
                throw new RuntimeException('Insufficient wallet balance.');
            }
            if ($payerRole === 'student') {
                if ((int) $payer['is_frozen'] === 1) {
                    throw new RuntimeException('Your wallet is frozen by a parent or guardian.');
                }
                if ((float) $payer['daily_spend_limit'] > 0) {
                    $spent = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions
                        WHERE student_wallet_id = ? AND transaction_type = 'payment'
                          AND status = 'completed' AND DATE(created_at) = CURDATE()");
                    $spent->execute([(int) $payer['id']]);
                    if ((float) $spent->fetchColumn() + $amount > (float) $payer['daily_spend_limit']) {
                        throw new RuntimeException('Your daily spending limit would be exceeded.');
                    }
                }
            }
            $quantities = [];
            foreach ($lines as $line) {
                $quantities[$line['id']] = ($quantities[$line['id']] ?? 0) + $line['qty'];
            }
            ksort($quantities, SORT_NUMERIC);
            foreach ($quantities as $itemId => $qty) {
                $itemStmt = $this->db->prepare('SELECT stock_qty FROM merchant_inventory
                    WHERE id = ? AND merchant_user_id = ? AND is_available = 1 AND is_restricted = 0 FOR UPDATE');
                $itemStmt->execute([(int) $itemId, (int) $merchant['user_id']]);
                $stock = $itemStmt->fetchColumn();
                if ($stock === false || (int) $stock < $qty) {
                    throw new RuntimeException('An item is unavailable or has insufficient stock. Ask for a new POS bill.');
                }
            }
            if ($payerRole === 'student') {
                $debit = $this->db->prepare('UPDATE student_wallets SET balance = balance - ? WHERE id = ? AND balance >= ?');
            } else {
                $debit = $this->db->prepare('UPDATE parent_wallets SET balance = balance - ? WHERE id = ? AND balance >= ?');
            }
            $debit->execute([$amount, (int) $payer['id'], $amount]);
            if ($debit->rowCount() !== 1) {
                throw new RuntimeException('Wallet balance changed. Payment was not completed.');
            }
            $this->db->prepare('UPDATE merchant_wallets SET balance = balance + ? WHERE id = ?')
                ->execute([$amount, (int) $merchant['id']]);
            foreach ($quantities as $itemId => $qty) {
                $stock = $this->db->prepare('UPDATE merchant_inventory SET stock_qty = stock_qty - ?
                    WHERE id = ? AND merchant_user_id = ? AND stock_qty >= ? AND is_available = 1 AND is_restricted = 0');
                $stock->execute([$qty, (int) $itemId, (int) $merchant['user_id'], $qty]);
                if ($stock->rowCount() !== 1) {
                    throw new RuntimeException('Stock changed. Payment was not completed.');
                }
            }
            $this->validateCirculation((float) $settings['total_circulation_cap']);
            $notes = 'POS Sale: ' . implode(', ', array_map(static fn($line) =>
                $line['qty'] . 'x ' . $line['name'], $lines));
            $txnId = null;
            $reference = $this->logTransaction(
                self::TXN_PAYMENT, $payerUserId, $amount,
                (float) $settings['cashier_vault_points'], (float) $settings['cashier_vault_points'],
                studentWalletId: $payerRole === 'student' ? (int) $payer['id'] : null,
                merchantWalletId: (int) $merchant['id'],
                notes: $notes,
                parentWalletId: $payerRole === 'parent' ? (int) $payer['id'] : null,
                audit: ['new' => ['pos_order_id' => (int) $order['id']]],
                transactionId: $txnId,
            );
            gjc_store_transaction_items($this->db, $txnId, $lines);
            $paid = $this->db->prepare("UPDATE merchant_qr_orders SET status = 'paid',
                paid_by = ?, paid_ref = ?, paid_at = NOW() WHERE id = ? AND status = 'pending'");
            $paid->execute([$payerUserId, $reference, (int) $order['id']]);
            if ($paid->rowCount() !== 1) {
                throw new RuntimeException('This POS bill was already settled.');
            }
            if ($payerRole === 'student') {
                gjc_record_maintenance_fee($this->db, $payerUserId, $reference, 'payment', $amount);
            }
            $this->db->commit();
            gjc_audit_commit($this->db);
            return ['success' => true, 'reference' => $reference, 'amount' => $amount,
                'student_wallet_id' => $payerRole === 'student' ? (int) $payer['id'] : null,
                'merchant_user_id' => (int) $merchant['user_id']];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            gjc_audit_rollback($this->db, $error->getMessage());
            throw $error;
        }
    }

    public function studentPay(
        int $studentWalletId,
        int $merchantWalletId,
        float $amount,
        int $initiatedBy,
    ): array {
        $this->assertPositive($amount);

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();

            $student = $this->db->prepare(
                "SELECT balance FROM student_wallets WHERE id = ? FOR UPDATE",
            );
            $student->execute([$studentWalletId]);
            $studentRow = $student->fetch();

            if (!$studentRow || $studentRow["balance"] < $amount) {
                throw new RuntimeException(
                    "STUDENT_INSUFFICIENT_BALANCE: Student balance Php " .
                        number_format($studentRow["balance"] ?? 0, 2) .
                        " is below the required Php " .
                        number_format($amount, 2) .
                        ".",
                );
            }

            $this->db
                ->prepare(
                    "UPDATE student_wallets SET balance = balance - ? WHERE id = ?",
                )
                ->execute([$amount, $studentWalletId]);

            $this->db
                ->prepare(
                    "UPDATE merchant_wallets SET balance = balance + ? WHERE id = ?",
                )
                ->execute([$amount, $merchantWalletId]);

            $this->validateCirculation($settings["total_circulation_cap"]);

            $ref = $this->logTransaction(
                self::TXN_PAYMENT,
                $initiatedBy,
                $amount,
                $settings["cashier_vault_points"],
                $settings["cashier_vault_points"],
                studentWalletId: $studentWalletId,
                merchantWalletId: $merchantWalletId,
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return ["success" => true, "reference" => $ref];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Merchant sends GenCoins from their own wallet to a student.
     *
     * Fees ride on top: $cashAmount is what the STUDENT RECEIVES in full. The
     * merchant is debited that base plus both fees, then credited their own cut
     * back, so their net cost is base + systemFee.
     */
    public function merchantSendToStudent(
        int $merchantWalletId,
        int $studentWalletId,
        float $cashAmount,
        int $initiatedBy,
        string $notes = "",
    ): array {
        $this->assertPositive($cashAmount);

        $fees = self::feeBreakdown($cashAmount, true);
        $systemFee = $fees["system_fee"];
        $merchantFee = $fees["merchant_fee"];
        $credited = $fees["base"];
        $totalDebit = $fees["total"];

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();

            // Lock and verify merchant balance
            $mStmt = $this->db->prepare(
                "SELECT balance FROM merchant_wallets WHERE id = ? FOR UPDATE",
            );
            $mStmt->execute([$merchantWalletId]);
            $mRow = $mStmt->fetch();

            // Gross, not net: the debit below lands before the cut is returned,
            // so the wallet must cover the full amount at that moment.
            if (!$mRow || (float) $mRow["balance"] < $totalDebit) {
                throw new \RuntimeException(
                    sprintf(
                        "MERCHANT_INSUFFICIENT_BALANCE: Wallet has ₱%s — sending ₱%s costs ₱%s with fees.",
                        number_format((float) ($mRow["balance"] ?? 0), 2),
                        number_format($credited, 2),
                        number_format($totalDebit, 2),
                    ),
                );
            }

            // 1. Debit merchant wallet by base + both fees
            $this->db
                ->prepare(
                    "UPDATE merchant_wallets SET balance = balance - ? WHERE id = ?",
                )
                ->execute([$totalDebit, $merchantWalletId]);

            // 2. Credit student wallet
            $this->db
                ->prepare(
                    "UPDATE student_wallets SET balance = balance + ? WHERE id = ?",
                )
                ->execute([$credited, $studentWalletId]);

            // 3. Return the merchant's own cut
            $this->db
                ->prepare(
                    "UPDATE merchant_wallets SET balance = balance + ? WHERE id = ?",
                )
                ->execute([$merchantFee, $merchantWalletId]);

            // 4. System fee goes to vault as revenue
            $this->db
                ->prepare(
                    "UPDATE system_settings SET cashier_vault_points = cashier_vault_points + ? WHERE id = 1",
                )
                ->execute([$systemFee]);

            $vaultAfter = $settings["cashier_vault_points"] + $systemFee;

            $this->validateCirculation($settings["total_circulation_cap"]);

            $feeNote =
                "Merchant send to student. Credited: ₱{$credited}, " .
                "System fee (" . self::ratePct(self::FEE_SYSTEM_RATE) . "): ₱{$systemFee}, " .
                "Merchant cut (" . self::ratePct(self::FEE_MERCHANT_RATE) . "): ₱{$merchantFee}, " .
                "Charged to merchant: ₱{$totalDebit}." .
                ($notes !== "" ? " Note: {$notes}" : "");

            $ref = $this->logTransaction(
                self::TXN_CASH_IN,
                $initiatedBy,
                $credited,
                $settings["cashier_vault_points"],
                $vaultAfter,
                studentWalletId: $studentWalletId,
                merchantWalletId: $merchantWalletId,
                notes: $feeNote,
                topUpSource: "merchant",
                baseAmount: $credited,
                feeAmount: $fees["fee_total"],
                creditedAmount: $credited,
            );

            $this->logFeeRevenue(
                $ref,
                "merchant",
                $totalDebit,
                $systemFee,
                $merchantFee,
                $merchantWalletId,
                $initiatedBy,
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return [
                "success" => true,
                "reference" => $ref,
                "cash_amount" => $credited,
                "total_collected" => $totalDebit,
                "system_fee" => $systemFee,
                "merchant_fee" => $merchantFee,
                "fee_amount" => $fees["fee_total"],
                "credited_amount" => $credited,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    /** Instant, merchant-wallet-funded parent top-up. Mirrors merchantSendToStudent(). */
    public function merchantSendToParent(
        int $merchantWalletId,
        int $parentWalletId,
        float $cashAmount,
        int $initiatedBy,
        string $notes = "",
    ): array {
        $this->assertPositive($cashAmount);

        // Fees ride on top — see merchantSendToStudent().
        $fees = self::feeBreakdown($cashAmount, true);
        $systemFee = $fees["system_fee"];
        $merchantFee = $fees["merchant_fee"];
        $credited = $fees["base"];
        $totalDebit = $fees["total"];

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();

            $mStmt = $this->db->prepare(
                "SELECT balance FROM merchant_wallets WHERE id = ? FOR UPDATE",
            );
            $mStmt->execute([$merchantWalletId]);
            $mRow = $mStmt->fetch();

            if (!$mRow || (float) $mRow["balance"] < $totalDebit) {
                throw new \RuntimeException(
                    sprintf(
                        "MERCHANT_INSUFFICIENT_BALANCE: Wallet has ₱%s — sending ₱%s costs ₱%s with fees.",
                        number_format((float) ($mRow["balance"] ?? 0), 2),
                        number_format($credited, 2),
                        number_format($totalDebit, 2),
                    ),
                );
            }

            $this->db
                ->prepare(
                    "UPDATE merchant_wallets SET balance = balance - ? WHERE id = ?",
                )
                ->execute([$totalDebit, $merchantWalletId]);

            $this->db
                ->prepare(
                    "UPDATE parent_wallets SET balance = balance + ? WHERE id = ?",
                )
                ->execute([$credited, $parentWalletId]);

            $this->db
                ->prepare(
                    "UPDATE merchant_wallets SET balance = balance + ? WHERE id = ?",
                )
                ->execute([$merchantFee, $merchantWalletId]);

            $this->db
                ->prepare(
                    "UPDATE system_settings SET cashier_vault_points = cashier_vault_points + ? WHERE id = 1",
                )
                ->execute([$systemFee]);

            $vaultAfter = $settings["cashier_vault_points"] + $systemFee;

            $this->validateCirculation($settings["total_circulation_cap"]);

            $feeNote =
                "Merchant send to parent. Credited: ₱{$credited}, " .
                "System fee (" . self::ratePct(self::FEE_SYSTEM_RATE) . "): ₱{$systemFee}, " .
                "Merchant cut (" . self::ratePct(self::FEE_MERCHANT_RATE) . "): ₱{$merchantFee}, " .
                "Charged to merchant: ₱{$totalDebit}." .
                ($notes !== "" ? " Note: {$notes}" : "");

            $ref = $this->logTransaction(
                self::TXN_CASH_IN,
                $initiatedBy,
                $credited,
                $settings["cashier_vault_points"],
                $vaultAfter,
                parentWalletId: $parentWalletId,
                merchantWalletId: $merchantWalletId,
                notes: $feeNote,
                topUpSource: "merchant",
                baseAmount: $credited,
                feeAmount: $fees["fee_total"],
                creditedAmount: $credited,
            );

            $this->logFeeRevenue(
                $ref,
                "merchant",
                $totalDebit,
                $systemFee,
                $merchantFee,
                $merchantWalletId,
                $initiatedBy,
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return [
                "success" => true,
                "reference" => $ref,
                "cash_amount" => $credited,
                "total_collected" => $totalDebit,
                "system_fee" => $systemFee,
                "merchant_fee" => $merchantFee,
                "fee_amount" => $fees["fee_total"],
                "credited_amount" => $credited,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    /**
     * One-time parent → student transfer. Internal move, cap unchanged, no
     * fee (fees were already taken at cash-in). A single transactions row
     * carries both parent_wallet_id and student_wallet_id, so it's visible
     * in both the parent's sent-history and the student's own ledger — see
     * the plan's note on why p2p_transfer's single-sender-row shape isn't
     * repeated here.
     */
    public function allowanceTransfer(
        int $parentWalletId,
        int $studentWalletId,
        float $amount,
        int $initiatedByUserId,
        string $notes = "",
    ): array {
        $this->assertPositive($amount);

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();

            $pStmt = $this->db->prepare(
                "SELECT balance FROM parent_wallets WHERE id = ? FOR UPDATE",
            );
            $pStmt->execute([$parentWalletId]);
            $parentRow = $pStmt->fetch();

            if (!$parentRow || (float) $parentRow["balance"] < $amount) {
                throw new \RuntimeException(
                    "PARENT_INSUFFICIENT_BALANCE: Wallet has ₱" .
                        number_format((float) ($parentRow["balance"] ?? 0), 2) .
                        " — cannot send ₱" .
                        number_format($amount, 2) .
                        ".",
                );
            }

            $sStmt = $this->db->prepare(
                "SELECT id FROM student_wallets WHERE id = ? FOR UPDATE",
            );
            $sStmt->execute([$studentWalletId]);
            if (!$sStmt->fetch()) {
                throw new \RuntimeException("STUDENT_WALLET_NOT_FOUND: Recipient wallet does not exist.");
            }

            $this->db
                ->prepare("UPDATE parent_wallets SET balance = balance - ? WHERE id = ?")
                ->execute([$amount, $parentWalletId]);

            $this->db
                ->prepare("UPDATE student_wallets SET balance = balance + ? WHERE id = ?")
                ->execute([$amount, $studentWalletId]);

            // Cap doesn't move — vault is untouched, this is an internal transfer.
            $vaultUnchanged = (float) $settings["cashier_vault_points"];

            $this->validateCirculation($settings["total_circulation_cap"]);

            $ref = $this->logTransaction(
                self::TXN_ALLOWANCE,
                $initiatedByUserId,
                $amount,
                $vaultUnchanged,
                $vaultUnchanged,
                studentWalletId: $studentWalletId,
                parentWalletId: $parentWalletId,
                notes: $notes !== "" ? $notes : "Allowance transfer",
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return [
                "success" => true,
                "reference" => $ref,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    public function merchantSettle(
        int $merchantWalletId,
        float $amount,
        int $initiatedBy,
    ): array {
        $this->assertPositive($amount);

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();

            $merchant = $this->db->prepare(
                "SELECT balance FROM merchant_wallets WHERE id = ? FOR UPDATE",
            );
            $merchant->execute([$merchantWalletId]);
            $merchantRow = $merchant->fetch();

            if (!$merchantRow || $merchantRow["balance"] < $amount) {
                throw new RuntimeException(
                    "MERCHANT_INSUFFICIENT_BALANCE: Cannot settle Php " .
                        number_format($amount, 2) .
                        ".",
                );
            }

            $this->db
                ->prepare(
                    "UPDATE merchant_wallets SET balance = balance - ? WHERE id = ?",
                )
                ->execute([$amount, $merchantWalletId]);

            $this->db
                ->prepare(
                    "UPDATE system_settings
                    SET cashier_vault_points = cashier_vault_points + ?
                  WHERE id = 1",
                )
                ->execute([$amount]);

            $vaultAfter = $settings["cashier_vault_points"] + $amount;

            $this->validateCirculation($settings["total_circulation_cap"]);

            $ref = $this->logTransaction(
                self::TXN_MERCHANT_SETTLE,
                $initiatedBy,
                $amount,
                $settings["cashier_vault_points"],
                $vaultAfter,
                merchantWalletId: $merchantWalletId,
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return [
                "success" => true,
                "reference" => $ref,
                "vault_after" => $vaultAfter,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    public function increaseCirculationCap(
        float $increaseBy,
        int $superAdminId,
        string $reason,
    ): array {
        $this->assertPositive($increaseBy);

        if (trim($reason) === "") {
            throw new InvalidArgumentException(
                "A mandatory reason/justification is required to increase the money supply.",
            );
        }

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();
            $oldCap = $settings["total_circulation_cap"];
            $newCap = $oldCap + $increaseBy;

            $this->db
                ->prepare(
                    "UPDATE system_settings
                    SET total_circulation_cap   = ?,
                        cashier_vault_points    = cashier_vault_points + ?,
                        last_cap_increased_by   = ?,
                        last_cap_increased_at   = NOW()
                  WHERE id = 1",
                )
                ->execute([$newCap, $increaseBy, $superAdminId]);

            $legacyAmountAdded = gjc_cap_log_has_legacy_amount_added($this->db);
            $this->db
                ->prepare(
                    "INSERT INTO cap_increase_log
                    (super_admin_id, old_cap, new_cap" . ($legacyAmountAdded ? ", amount_added" : "") . ", reason)
                 VALUES (?, ?, ?, " . ($legacyAmountAdded ? "?, " : "") . "?)",
                )
                ->execute([
                    $superAdminId,
                    $oldCap,
                    $newCap,
                    ...($legacyAmountAdded ? [$increaseBy] : []),
                    $reason,
                ]);

            $vaultAfter = $settings["cashier_vault_points"] + $increaseBy;

            $this->validateCirculation($newCap);

            $ref = $this->logTransaction(
                self::TXN_CAP_INCREASE,
                $superAdminId,
                $increaseBy,
                $settings["cashier_vault_points"],
                $vaultAfter,
                notes: "Cap raised from Php {$oldCap} to Php {$newCap}. Reason: {$reason}",
                // increaseCirculationCap() refuses to run without a
                // justification; until now that justification only reached
                // transactions.notes and never the audit trail.
                audit: [
                    "reason" => $reason,
                    "old" => [
                        "total_circulation_cap" => $oldCap,
                        "cashier_vault_points"  => $settings["cashier_vault_points"],
                    ],
                    "new" => [
                        "total_circulation_cap" => $newCap,
                        "cap_increased_by"      => $increaseBy,
                        "minted_by"             => $superAdminId,
                    ],
                ],
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return [
                "success" => true,
                "old_cap" => $oldCap,
                "new_cap" => $newCap,
                "vault_after" => $vaultAfter,
                "reference" => $ref,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mints a Fee Waiver Credit straight into a student's wallet: raises
     * total_circulation_cap and credits student_wallets directly, vault
     * untouched — money that was never sitting in the vault in the first
     * place (the school forgave/credited it outside GenPay). Same shape as
     * increaseCirculationCap(), except the new coins land in one student's
     * wallet instead of the vault.
     *
     * Caller (gjc_claim_fee_waiver_credit()) is responsible for the
     * claim-before-mint race guard on fee_waiver_credits.status and for
     * widening the transactions.transaction_type / cap_increase_log.source
     * enums before this runs — both are DDL and must happen before any
     * beginTransaction().
     */
    public function feeWaiverClaim(int $studentWalletId, float $amount, int $initiatedBy, int $creditId): array
    {
        $this->assertPositive($amount);

        $this->db->beginTransaction();
        try {
            $settings = $this->lockSettings();
            $oldCap = $settings["total_circulation_cap"];
            $newCap = $oldCap + $amount;

            $this->db
                ->prepare(
                    "UPDATE system_settings
                    SET total_circulation_cap = ?
                  WHERE id = 1",
                )
                ->execute([$newCap]);

            $this->db
                ->prepare(
                    "UPDATE student_wallets SET balance = balance + ? WHERE id = ?",
                )
                ->execute([$amount, $studentWalletId]);

            $legacyAmountAdded = gjc_cap_log_has_legacy_amount_added($this->db);
            $this->db
                ->prepare(
                    "INSERT INTO cap_increase_log
                    (super_admin_id, old_cap, new_cap" . ($legacyAmountAdded ? ", amount_added" : "") . ", reason, source, source_ref_id)
                 VALUES (?, ?, ?, ". ($legacyAmountAdded ? "?, " : "") . "?, 'fee_waiver_claim', ?)",
                )
                ->execute([
                    $initiatedBy,
                    $oldCap,
                    $newCap,
                    ...($legacyAmountAdded ? [$amount] : []),
                    "Fee Waiver Credit #{$creditId} claimed to student wallet.",
                    $creditId,
                ]);

            $this->validateCirculation($newCap);

            $ref = $this->logTransaction(
                self::TXN_FEE_WAIVER_CLAIM,
                $initiatedBy,
                $amount,
                $settings["cashier_vault_points"],
                $settings["cashier_vault_points"],
                studentWalletId: $studentWalletId,
                notes: "Fee Waiver Credit #{$creditId} claimed into wallet. Cap raised from Php {$oldCap} to Php {$newCap}.",
                audit: [
                    "old" => [
                        "total_circulation_cap" => $oldCap,
                    ],
                    "new" => [
                        "total_circulation_cap" => $newCap,
                        "fee_waiver_credit_id" => $creditId,
                        "claimed_by" => $initiatedBy,
                    ],
                ],
            );

            $this->db->commit();
            gjc_audit_commit($this->db);
            return [
                "success" => true,
                "old_cap" => $oldCap,
                "new_cap" => $newCap,
                "reference" => $ref,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            gjc_audit_rollback($this->db, $e->getMessage());
            throw $e;
        }
    }

    public function getCirculationSnapshot(): array
    {
        // Circulation health is computed in PHP (buildCirculationSnapshot),
        // so the app has no dependency on any database view.
        return $this->buildCirculationSnapshot();
    }

    private function validateCirculation(float $expectedCap): void
    {
        // The active-voucher term below is deliberately retained after the
        // visitor feature was removed. Every voucher was expired back into the
        // vault first, so it sums to zero today — but keeping it means that if
        // an 'active' voucher row ever reappears, the money still counts toward
        // circulation instead of silently vanishing from the closed loop.
        $row = $this->db
            ->query(
                "SELECT
                (SELECT cashier_vault_points FROM system_settings WHERE id = 1)
                + COALESCE((SELECT SUM(balance) FROM student_wallets), 0)
                + COALESCE((SELECT SUM(balance) FROM merchant_wallets), 0)
                + COALESCE((SELECT SUM(balance) FROM parent_wallets), 0)
                + COALESCE((SELECT SUM(remaining_balance)
                            FROM vouchers WHERE status = 'active'), 0)
             AS total_in_circulation",
            )
            ->fetchColumn();

        $total = (float) $row;
        $drift = abs($total - $expectedCap);

        if ($drift > 0.01) {
            throw new RuntimeException(
                sprintf(
                    "CIRCULATION_INTEGRITY_FAILURE: Expected cap Php %s but total " .
                        "in circulation is Php %s (drift Php %s). Transaction aborted.",
                    number_format($expectedCap, 2),
                    number_format($total, 2),
                    number_format($drift, 2),
                ),
            );
        }
    }

    private function lockSettings(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM system_settings WHERE id = 1 FOR UPDATE",
        );
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException(
                "SYSTEM_SETTINGS_MISSING: Cannot find singleton row.",
            );
        }
        return $row;
    }

    private function buildCirculationSnapshot(): array
    {
        $row = $this->db
            ->query(
                "SELECT
                ss.total_circulation_cap AS cap,
                ss.cashier_vault_points AS vault,
                COALESCE((SELECT SUM(balance) FROM student_wallets), 0) AS student_wallets_total,
                COALESCE((SELECT SUM(balance) FROM merchant_wallets), 0) AS merchant_wallets_total,
                COALESCE((SELECT SUM(balance) FROM parent_wallets), 0) AS parent_wallets_total,
                COALESCE((SELECT SUM(remaining_balance) FROM vouchers WHERE status = 'active'), 0) AS active_vouchers_total,
                (
                    ss.cashier_vault_points
                    + COALESCE((SELECT SUM(balance) FROM student_wallets), 0)
                    + COALESCE((SELECT SUM(balance) FROM merchant_wallets), 0)
                    + COALESCE((SELECT SUM(balance) FROM parent_wallets), 0)
                    + COALESCE((SELECT SUM(remaining_balance) FROM vouchers WHERE status = 'active'), 0)
                ) AS total_in_circulation,
                (
                    ss.total_circulation_cap
                    - ss.cashier_vault_points
                    - COALESCE((SELECT SUM(balance) FROM student_wallets), 0)
                    - COALESCE((SELECT SUM(balance) FROM merchant_wallets), 0)
                    - COALESCE((SELECT SUM(balance) FROM parent_wallets), 0)
                    - COALESCE((SELECT SUM(remaining_balance) FROM vouchers WHERE status = 'active'), 0)
                ) AS circulation_drift,
                ss.updated_at AS as_of
             FROM system_settings ss
             WHERE ss.id = 1",
            )
            ->fetch();

        return $row ?: [];
    }

    private function logTransaction(
        string $type,
        int $initiatedBy,
        float $amount,
        float $vaultBefore,
        float $vaultAfter,
        int $studentWalletId = null,
        int $merchantWalletId = null,
        int $voucherId = null,
        string $notes = null,
        // Fee-related optional fields (populated only for cashInWithFee calls)
        string $topUpSource = null,
        float $baseAmount = null,
        float $feeAmount = null,
        float $creditedAmount = null,
        int $parentWalletId = null,
        // Audit-only extras. Nothing here touches the ledger or the money maths:
        //   reason  the operator's justification, for actions that demand one
        //   old     the before-state to record as old_value
        //   new     extra fields to merge into new_value
        array $audit = [],
        ?int &$transactionId = null,
    ): string {
        $total = (float) $this->db
            ->query(
                "SELECT
                (SELECT cashier_vault_points FROM system_settings WHERE id = 1)
                + COALESCE((SELECT SUM(balance) FROM student_wallets), 0)
                + COALESCE((SELECT SUM(balance) FROM merchant_wallets), 0)
                + COALESCE((SELECT SUM(balance) FROM parent_wallets), 0)
                + COALESCE((SELECT SUM(remaining_balance)
                            FROM vouchers WHERE status = 'active'), 0)",
            )
            ->fetchColumn();

        $ref =
            "TXN-" .
            strtoupper(date("Ymd")) .
            "-" .
            str_pad((string) random_int(1, 99999), 5, "0", STR_PAD_LEFT);

        $this->db
            ->prepare(
                "INSERT INTO transactions
                (reference_no, transaction_type, initiated_by, student_wallet_id,
                 merchant_wallet_id, voucher_id, amount, vault_before, vault_after,
                 total_in_circulation, status, notes, school_year_id, parent_wallet_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?)",
            )
            ->execute([
                $ref,
                $type,
                $initiatedBy,
                $studentWalletId,
                $merchantWalletId,
                $voucherId,
                $amount,
                $vaultBefore,
                $vaultAfter,
                $total,
                $notes,
                gjc_active_school_year_id($this->db),
                $parentWalletId,
            ]);

        // Later queries (including audit role lookup) can reset PDO's insert ID.
        // Return the ledger ID separately from the public payment reference.
        $transactionId = (int) $this->db->lastInsertId();

        // Persist fee fields if the migration has already added the columns
        if ($topUpSource !== null) {
            try {
                $this->db
                    ->prepare(
                        "UPDATE transactions
                        SET top_up_source   = ?,
                            base_amount     = ?,
                            fee_amount      = ?,
                            credited_amount = ?
                      WHERE id = ?",
                    )
                    ->execute([
                        $topUpSource,
                        $baseAmount,
                        $feeAmount,
                        $creditedAmount,
                        $transactionId,
                    ]);
            } catch (\Throwable $ignored) {
                // Migration hasn't run yet — non-fatal, notes column already has the info
            }
        }

        // old_value used to be null on every money movement, which left the
        // entry with no before-state to compare against.
        $auditOld = $audit["old"] ?? [
            "cashier_vault_points" => $vaultBefore,
        ];

        $auditNew = array_merge([
            "reference_no" => $ref,
            "transaction_type" => $type,
            "amount" => $amount,
            "student_wallet_id" => $studentWalletId,
            "merchant_wallet_id" => $merchantWalletId,
            "voucher_id" => $voucherId,
            "vault_before" => $vaultBefore,
            "vault_after" => $vaultAfter,
            "total_in_circulation" => $total,
            "status" => "completed",
        ], $audit["new"] ?? []);

        logAudit(
            $this->db,
            $initiatedBy,
            gjc_audit_role_from_user($this->db, $initiatedBy),
            "TRANSACTION",
            "transactions",
            $auditOld,
            $auditNew,
            null,
            [
                "target_id" => $ref,
                "reason" => $audit["reason"] ?? null,
            ],
        );

        return $ref;
    }

    private function logFeeRevenue(
        string $ref,
        string $source,
        float $cashAmount,
        float $systemFee,
        float $merchantFee,
        ?int $merchantWalletId,
        int $processedBy,
    ): void {
        try {
            $this->db
                ->prepare(
                    "INSERT INTO fee_revenue_log
                    (transaction_ref, top_up_source, cash_amount,
                     system_fee, merchant_fee, merchant_wallet_id, processed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                )
                ->execute([
                    $ref,
                    $source,
                    $cashAmount,
                    $systemFee,
                    $merchantFee,
                    $merchantWalletId,
                    $processedBy,
                ]);
        } catch (\Throwable $ignored) {
            // Table hasn't been created yet — will self-heal on next page load
        }
    }

    private function assertPositive(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                "Amount must be greater than zero.",
            );
        }
    }
}
