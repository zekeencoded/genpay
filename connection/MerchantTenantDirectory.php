<?php

class MerchantTenantDirectory
{
    private PDO $db;

    /** lease id => [YYYY-MM => amount], filled lazily or primed in bulk. */
    private array $paymentCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function directoryCards(): array
    {
        if (!gjc_table_exists($this->db, 'merchant')) {
            return [];
        }

        $hasOperationalStatus = in_array('operational_status', gjc_table_columns($this->db, 'merchant'), true);
        $statusSelect = $hasOperationalStatus ? "COALESCE(m.operational_status, 'active')" : "'active'";
        $hasLeases = gjc_table_exists($this->db, 'merchant_leases');
        $leaseSelect = $hasLeases
            ? "ml.id AS lease_id,
                ml.monthly_rent,
                ml.lease_start,
                ml.lease_end,
                ml.status AS lease_status"
            : "NULL AS lease_id,
                0 AS monthly_rent,
                NULL AS lease_start,
                NULL AS lease_end,
                NULL AS lease_status";
        $leaseJoin = $hasLeases
            ? "LEFT JOIN merchant_leases ml ON ml.id = (
                SELECT l2.id
                FROM merchant_leases l2
                WHERE l2.merchant_user_id = m.userID
                ORDER BY
                    CASE WHEN l2.status = 'active' THEN 0 ELSE 1 END,
                    l2.lease_end DESC,
                    l2.id DESC
                LIMIT 1
            )"
            : "";

        $sql = "
            SELECT
                m.merchantID,
                m.userID AS merchant_user_id,
                m.stall_name,
                {$statusSelect} AS operational_status,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS proprietor_name,
                u.email AS proprietor_email,
                {$leaseSelect}
            FROM merchant m
            LEFT JOIN users u ON u.userID = m.userID
            {$leaseJoin}
            ORDER BY m.stall_name ASC, m.merchantID ASC";

        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Same rent-standing verdict the Leases & Rent page shows, so the two
        // screens never disagree about who is behind.
        $this->primePaymentCache(array_column($rows, 'lease_id'));

        return array_map(function (array $row): array {
            $leaseId = (int) ($row['lease_id'] ?? 0);
            $account = $leaseId > 0
                ? $this->leaseAccount([
                    'id' => $leaseId,
                    'monthly_rent' => (float) ($row['monthly_rent'] ?? 0),
                    'lease_start' => (string) ($row['lease_start'] ?? ''),
                    'lease_end' => (string) ($row['lease_end'] ?? ''),
                    'status' => (string) ($row['lease_status'] ?? 'pending'),
                ])
                : ['state' => 'none', 'state_label' => 'No lease', 'outstanding' => 0.0];

            return [
                'merchant_id' => (int) $row['merchantID'],
                'merchant_user_id' => (int) $row['merchant_user_id'],
                'stall_name' => (string) $row['stall_name'],
                'proprietor_name' => trim((string) $row['proprietor_name']) ?: ((string) ($row['proprietor_email'] ?? 'Unassigned proprietor')),
                'operational_status' => (string) $row['operational_status'],
                'lease_status' => (string) $account['state_label'],
                'lease_state' => (string) $account['state'],
                'lease_outstanding' => (float) $account['outstanding'],
                'lease_status_raw' => (string) ($row['lease_status'] ?? 'none'),
                'lease_id' => $leaseId,
            ];
        }, $rows);
    }

    public function merchantsForPicker(): array
    {
        if (!gjc_table_exists($this->db, 'merchant')) {
            return [];
        }

        $hasLeases = gjc_table_exists($this->db, 'merchant_leases');
        $activeLeaseSelect = $hasLeases
            ? "EXISTS (SELECT 1 FROM merchant_leases l WHERE l.merchant_user_id = m.userID AND l.status = 'active')"
            : "0";

        $sql = "
            SELECT
                m.userID AS merchant_user_id,
                m.stall_name,
                m.stall_id,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS proprietor_name,
                u.email AS proprietor_email,
                {$activeLeaseSelect} AS has_active_lease
            FROM merchant m
            LEFT JOIN users u ON u.userID = m.userID
            ORDER BY m.stall_name ASC";

        $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): array => [
            'merchant_user_id' => (int) $row['merchant_user_id'],
            'stall_name' => (string) $row['stall_name'],
            'stall_id' => (string) ($row['stall_id'] ?? ''),
            'proprietor_name' => trim((string) $row['proprietor_name']) ?: ((string) ($row['proprietor_email'] ?? 'Unnamed proprietor')),
            'proprietor_email' => (string) ($row['proprietor_email'] ?? ''),
            'has_active_lease' => (bool) $row['has_active_lease'],
        ], $rows);
    }

    public function stallSummary(int $merchantId): ?array
    {
        $hasOperationalStatus = in_array('operational_status', gjc_table_columns($this->db, 'merchant'), true);
        $statusSelect = $hasOperationalStatus ? "COALESCE(m.operational_status, 'active')" : "'active'";

        $stmt = $this->db->prepare(
            "SELECT
                m.merchantID,
                m.userID AS merchant_user_id,
                m.stall_name,
                {$statusSelect} AS operational_status,
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS proprietor_name,
                u.email AS proprietor_email,
                u.contact_number
             FROM merchant m
             LEFT JOIN users u ON u.userID = m.userID
             WHERE m.merchantID = ?
             LIMIT 1"
        );
        $stmt->execute([$merchantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'merchant_id' => (int) $row['merchantID'],
            'merchant_user_id' => (int) $row['merchant_user_id'],
            'stall_name' => (string) $row['stall_name'],
            'operational_status' => (string) $row['operational_status'],
            'proprietor_name' => trim((string) $row['proprietor_name']) ?: ((string) ($row['proprietor_email'] ?? 'Unassigned proprietor')),
            'proprietor_email' => (string) ($row['proprietor_email'] ?? ''),
            'contact_number' => (string) ($row['contact_number'] ?? ''),
        ];
    }

    /** Existing rent calculation, with the existing lease-end fallback. */
    public function derivedNextDueDate(array $lease, ?array $account = null): string
    {
        $account = $account ?? $this->leaseAccount($lease);
        return (string) ($account['next_due_date'] ?? $lease['lease_end']);
    }

    /** Keep result keys intact while projecting the date from current rent facts. */
    public function withDerivedDueDates(array $leases): array
    {
        $this->primePaymentCache(array_column($leases, 'id'));
        foreach ($leases as &$lease) {
            $lease['next_due_date'] = $this->derivedNextDueDate($lease);
        }
        unset($lease);
        return $leases;
    }
    public function activeLease(int $merchantUserId): ?array
    {
        if (!gjc_table_exists($this->db, 'merchant_leases')) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id
             FROM merchant_leases
             WHERE merchant_user_id = ?
             ORDER BY
                CASE WHEN status = 'active' THEN 0 ELSE 1 END,
                lease_end DESC,
                id DESC
             LIMIT 1"
        );
        $stmt->execute([$merchantUserId]);
        $id = (int) $stmt->fetchColumn();

        return $id > 0 ? $this->leaseById($id) : null;
    }

    public function leaseById(int $leaseId): ?array
    {
        if ($leaseId <= 0 || !gjc_table_exists($this->db, 'merchant_leases')) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM merchant_leases WHERE id = ? LIMIT 1");
        $stmt->execute([$leaseId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lease) {
            return null;
        }

        $normalised = [
            'id' => (int) $lease['id'],
            'merchant_user_id' => (int) $lease['merchant_user_id'],
            // The stall code; key kept for existing consumers (3NF: from stall_id).
            'stall_number' => gjc_lease_stall_code($lease),
            'stall_id' => (string) ($lease['stall_id'] ?? ''),
            'stall_name' => (string) $lease['stall_name'],
            'monthly_rent' => (float) $lease['monthly_rent'],
            'deposit_amount' => (float) $lease['deposit_amount'],
            'lease_start' => (string) $lease['lease_start'],
            'lease_end' => (string) $lease['lease_end'],
            'status' => (string) $lease['status'],
            'contract_notes' => (string) ($lease['contract_notes'] ?? ''),
        ];

        $schedule = $this->rentSchedule($normalised);
        $account  = $this->leaseAccount($normalised, $schedule);

        return $normalised + [
            'next_due_date' => $this->derivedNextDueDate($normalised, $account),
            // Retained response key during verification; no current caller uses it.
            'stored_next_due_date' => (string) ($lease['next_due_date'] ?? $this->derivedNextDueDate($normalised, $account)),
            'lifespan_months' => $account['term_months'],
            'schedule' => $schedule,
            'account' => $account,
            // Legacy keys, kept so existing callers keep reading the same names.
            'expected_rent_to_date' => $account['billed_to_date'],
            'paid_total' => $account['collected'],
            'balance_due' => $account['outstanding'],
            'current_month_status' => $account['state_label'],
        ];
    }

    /* -- Rent accounting --------------------------------------------------
       One lease bills exactly one month of rent per billing period. Period 0
       opens on lease_start; period k opens k whole months later; each period
       is labelled with the calendar month it opens in (YYYY-MM) - the very
       label the admin picks in the payment form's "Period covered" field. So
       every payment lands on one specific month's charge.

       Everything the UI shows - balance, months behind, next due date, the
       month-by-month table - is derived from that schedule. Nothing reads the
       stored next_due_date column; syncNextDueDate() writes the derived value
       back into it so other pages (merchant/dashboard.php) stay in agreement.
       -------------------------------------------------------------------- */

    /**
     * One row per billing period, with what was charged, what has been
     * recorded against it, and whether that period has come due yet.
     */
    public function rentSchedule(array $lease, ?string $asOf = null): array
    {
        $rent   = round((float) ($lease['monthly_rent'] ?? 0), 2);
        $start  = (string) ($lease['lease_start'] ?? '');
        $end    = (string) ($lease['lease_end'] ?? '');
        $status = (string) ($lease['status'] ?? 'pending');
        $asOf   = $asOf ?: date('Y-m-d');

        if (!$this->isDate($start) || !$this->isDate($end) || $end < $start) {
            return [];
        }

        $paidByPeriod = $this->paymentsByPeriod((int) ($lease['id'] ?? 0));
        $termMonths   = $this->termPeriods($start, $end);
        $rows         = [];

        for ($k = 0; $k < $termMonths; $k++) {
            $dueDate = $this->addMonths($start, $k);
            $label   = substr($dueDate, 0, 7);
            $paid    = round((float) ($paidByPeriod[$label] ?? 0), 2);
            // A pending contract has not started billing yet.
            $isDue   = $status !== 'pending' && $dueDate <= $asOf;

            $rows[] = [
                'period'    => $label,
                'due_date'  => $dueDate,
                'charged'   => $rent,
                'paid'      => $paid,
                'shortfall' => $isDue ? max(0.0, round($rent - $paid, 2)) : 0.0,
                'overpaid'  => max(0.0, round($paid - $rent, 2)),
                'is_due'    => $isDue,
                'state'     => $this->periodState($rent, $paid, $isDue),
            ];
            unset($paidByPeriod[$label]);
        }

        // Payments filed against a month outside the contract term still have to
        // appear somewhere, or the collected total stops adding up on screen.
        foreach ($paidByPeriod as $label => $paid) {
            $rows[] = [
                'period'    => (string) $label,
                'due_date'  => $label . '-01',
                'charged'   => 0.0,
                'paid'      => round((float) $paid, 2),
                'shortfall' => 0.0,
                'overpaid'  => round((float) $paid, 2),
                'is_due'    => false,
                'state'     => 'off_contract',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['period'], $b['period']));

        return $rows;
    }

    /**
     * Roll the schedule up into the handful of figures the screens actually
     * show, plus a plain-English sentence describing where the lease stands.
     */
    public function leaseAccount(array $lease, ?array $schedule = null): array
    {
        $schedule = $schedule ?? $this->rentSchedule($lease);
        $rent     = round((float) ($lease['monthly_rent'] ?? 0), 2);
        $status   = (string) ($lease['status'] ?? 'pending');
        $today    = date('Y-m-d');

        $billed = $collected = $outstanding = $advance = 0.0;
        $monthsBilled = $monthsBehind = $monthsAhead = 0;
        $oldestUnpaid = null;
        $upcoming = null;

        foreach ($schedule as $row) {
            $collected += $row['paid'];

            if ($row['is_due']) {
                $billed += $row['charged'];
                $monthsBilled++;
                $advance += $row['overpaid'];
                if ($row['shortfall'] > 0.005) {
                    $monthsBehind++;
                    $outstanding += $row['shortfall'];
                    $oldestUnpaid = $oldestUnpaid ?? $row['due_date'];
                }
            } else {
                $advance += $row['paid'];
                if ($row['state'] === 'advance' && $rent > 0 && $row['paid'] + 0.005 >= $rent) {
                    $monthsAhead++;
                }
                // The next charge that has not opened yet. Deliberately separate
                // from next_due_date (the oldest month still owing): a tenant who
                // is behind must still be reminded about the month coming up.
                if ($upcoming === null && $row['state'] !== 'off_contract') {
                    $upcoming = $row;
                }
            }
        }

        // The earliest month still owing money is what to collect next; if
        // nothing is owed, it is the first future month not already paid ahead.
        $nextDue = $oldestUnpaid;
        if ($nextDue === null) {
            foreach ($schedule as $row) {
                if (!$row['is_due'] && $row['state'] !== 'off_contract' && $row['paid'] + 0.005 < $row['charged']) {
                    $nextDue = $row['due_date'];
                    break;
                }
            }
        }

        $daysOverdue = ($oldestUnpaid !== null && $oldestUnpaid < $today)
            ? (int) floor((strtotime($today) - strtotime($oldestUnpaid)) / 86400)
            : 0;
        $daysUntilDue = ($nextDue !== null && $nextDue >= $today)
            ? (int) floor((strtotime($nextDue) - strtotime($today)) / 86400)
            : null;

        $upcomingDue  = $upcoming['due_date'] ?? null;
        $upcomingDays = $upcomingDue !== null
            ? (int) floor((strtotime($upcomingDue) - strtotime($today)) / 86400)
            : null;

        [$state, $stateLabel, $summary] = $this->accountNarrative(
            $status,
            $rent,
            $outstanding,
            $advance,
            $monthsBehind,
            $monthsAhead,
            $nextDue,
            $daysOverdue,
            $daysUntilDue
        );

        return [
            'monthly_rent'    => $rent,
            'term_months'     => count(array_filter($schedule, static fn (array $r): bool => $r['state'] !== 'off_contract')),
            'months_billed'   => $monthsBilled,
            'months_behind'   => $monthsBehind,
            'months_ahead'    => $monthsAhead,
            'billed_to_date'  => round($billed, 2),
            'collected'       => round($collected, 2),
            'outstanding'     => round($outstanding, 2),
            'advance'         => round($advance, 2),
            'next_due_date'   => $nextDue,
            'oldest_unpaid'   => $oldestUnpaid,
            'upcoming_period' => $upcoming['period'] ?? null,
            'upcoming_due'    => $upcomingDue,
            'upcoming_days'   => $upcomingDays,
            // What that upcoming month still needs — zero if already paid ahead.
            'upcoming_amount' => $upcoming !== null ? max(0.0, round($upcoming['charged'] - $upcoming['paid'], 2)) : 0.0,
            'days_overdue'    => $daysOverdue,
            'days_until_due'  => $daysUntilDue,
            'is_overdue'      => $outstanding > 0.005,
            'state'           => $state,
            'state_label'     => $stateLabel,
            'summary'         => $summary,
        ];
    }

    /**
     * Recompute next_due_date from the schedule and write it back, so the
     * compatibility column while present; after CONTRACT this returns the calculated date.
     */
    public function syncNextDueDate(int $leaseId): ?string
    {
        $stmt = $this->db->prepare("SELECT * FROM merchant_leases WHERE id = ? LIMIT 1");
        $stmt->execute([$leaseId]);
        $lease = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lease) {
            return null;
        }

        $account = $this->leaseAccount($lease);
        // Nothing left to bill (paid through end of term): park the column on
        // the lease end date rather than leaving a stale date in the past.
        $nextDue = $account['next_due_date'] ?? (string) $lease['lease_end'];

        // Compatibility write exists only until the approved CONTRACT removes the column.
        if (in_array('next_due_date', gjc_table_columns($this->db, 'merchant_leases'), true)) {
            $this->db->prepare("UPDATE merchant_leases SET next_due_date = ? WHERE id = ?")
                     ->execute([$nextDue, $leaseId]);
        }

        return $nextDue;
    }

    /**
     * Send the "rent due soon" heads-up for any lease whose next charge falls
     * inside the reminder window, at most once per billing period.
     *
     * There is no cron in this app, so this rides the same lazy pattern as the
     * rest of the time-based behaviour here: it runs when the finance rent roll
     * is opened, and when a tenant loads their own dashboard. Passing
     * $merchantUserId scopes it to that one tenant, which is what the merchant
     * side does — a stall owner logging in to run the POS reminds themselves.
     *
     * The rent_reminder_period column holds the last period a tenant was told
     * about, so refreshing the page ten times sends one notification, not ten.
     *
     * @return int how many reminders were actually sent
     */
    public function dispatchRentReminders(?int $merchantUserId = null, int $daysAhead = 7): int
    {
        if (!gjc_table_exists($this->db, 'merchant_leases')) {
            return 0;
        }

        gjc_ensure_rent_reminder_schema($this->db);
        if (!in_array('rent_reminder_period', gjc_table_columns($this->db, 'merchant_leases'), true)) {
            return 0;  // the ALTER did not take; skip rather than crash the page
        }

        $sql = "SELECT * FROM merchant_leases WHERE status = 'active' AND lease_end >= CURDATE()";
        $params = [];
        if ($merchantUserId !== null) {
            $sql .= " AND merchant_user_id = ?";
            $params[] = $merchantUserId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $leases = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$leases) {
            return 0;
        }

        $this->primePaymentCache(array_column($leases, 'id'));
        $mark = $this->db->prepare("UPDATE merchant_leases SET rent_reminder_period = ? WHERE id = ?");
        $sent = 0;

        foreach ($leases as $lease) {
            $account = $this->leaseAccount($lease);
            $period  = $account['upcoming_period'];
            $days    = $account['upcoming_days'];

            if ($period === null || $days === null || $days < 0 || $days > $daysAhead) {
                continue;
            }

            // Already paid that month in advance — nothing to chase.
            if ($account['upcoming_amount'] <= 0.005) {
                continue;
            }

            // Already told them about this month.
            if ((string) ($lease['rent_reminder_period'] ?? '') === $period) {
                continue;
            }

            gjc_notify_rent_due_soon(
                $this->db,
                (int) $lease['merchant_user_id'],
                $account['upcoming_amount'],
                $period,
                (string) $account['upcoming_due'],
                $days,
                $account['outstanding']
            );

            $mark->execute([$period, (int) $lease['id']]);
            $sent++;
        }

        return $sent;
    }

    /** Merchants with no lease record at all - they are invisible on the rent roll. */
    public function merchantsWithoutLease(): array
    {
        if (!gjc_table_exists($this->db, 'merchant')) {
            return [];
        }

        $filter = gjc_table_exists($this->db, 'merchant_leases')
            ? "WHERE NOT EXISTS (SELECT 1 FROM merchant_leases l WHERE l.merchant_user_id = m.userID)"
            : '';

        $sql = "
            SELECT m.userID AS merchant_user_id,
                   m.stall_name,
                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS proprietor_name,
                   u.email AS proprietor_email
            FROM merchant m
            LEFT JOIN users u ON u.userID = m.userID
            {$filter}
            ORDER BY m.stall_name ASC";

        return array_map(static fn (array $row): array => [
            'merchant_user_id' => (int) $row['merchant_user_id'],
            'stall_name'       => (string) $row['stall_name'],
            'proprietor_name'  => trim((string) $row['proprietor_name']) ?: ((string) ($row['proprietor_email'] ?? 'Unnamed proprietor')),
        ], $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Delete a mis-keyed rent payment. Returns the row that was removed. */
    public function voidRentPayment(int $paymentId, int $leaseId): ?array
    {
        if (!gjc_table_exists($this->db, 'merchant_rent_payments')) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM merchant_rent_payments WHERE id = ? AND lease_id = ? LIMIT 1");
        $stmt->execute([$paymentId, $leaseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $this->db->prepare("DELETE FROM merchant_rent_payments WHERE id = ?")->execute([$paymentId]);
        $this->forgetPaymentCache($leaseId);

        return $row;
    }

    public function pagedRentPayments(int $leaseId, string $from, string $to, int $page, int $perPage): array
    {
        if (!gjc_table_exists($this->db, 'merchant_rent_payments')) {
            return ['rows' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1];
        }

        $page = max(1, $page);
        $perPage = min(50, max(5, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['lease_id = ?'];
        $params = [$leaseId];

        if ($from !== '' && $this->isDate($from)) {
            $where[] = 'payment_date >= ?';
            $params[] = $from;
        }

        if ($to !== '' && $this->isDate($to)) {
            $where[] = 'payment_date <= ?';
            $params[] = $to;
        }

        $whereSql = implode(' AND ', $where);
        $hasPaymentMethod = in_array('payment_method', gjc_table_columns($this->db, 'merchant_rent_payments'), true);
        $paymentMethodSelect = $hasPaymentMethod ? 'payment_method' : "'cash' AS payment_method";

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM merchant_rent_payments WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataStmt = $this->db->prepare(
            "SELECT id, amount_paid, period_covered, payment_date, {$paymentMethodSelect}, reference_no, notes, created_at
             FROM merchant_rent_payments
             WHERE {$whereSql}
             ORDER BY payment_date DESC, id DESC
             LIMIT ? OFFSET ?"
        );

        $i = 1;
        foreach ($params as $param) {
            $dataStmt->bindValue($i++, $param);
        }
        $dataStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue($i, $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'rows' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function pagedInventory(int $merchantUserId, string $search, string $category, string $restriction, int $page, int $perPage): array
    {
        if (!gjc_table_exists($this->db, 'merchant_inventory')) {
            return ['rows' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1];
        }

        $page = max(1, $page);
        $perPage = min(50, max(5, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['merchant_user_id = ?'];
        $params = [$merchantUserId];

        if ($search !== '') {
            $where[] = '(product_name LIKE ? OR sku LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        if ($category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }

        if ($restriction === 'restricted') {
            $where[] = 'is_restricted = 1';
        } elseif ($restriction === 'allowed') {
            $where[] = 'is_restricted = 0';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM merchant_inventory WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataStmt = $this->db->prepare(
            "SELECT id, sku, product_name, category, unit, price, stock_qty, is_available,
                    is_restricted, restriction_note, updated_at
             FROM merchant_inventory
             WHERE {$whereSql}
             ORDER BY product_name ASC, id ASC
             LIMIT ? OFFSET ?"
        );

        $i = 1;
        foreach ($params as $param) {
            $dataStmt->bindValue($i++, $param);
        }
        $dataStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue($i, $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'rows' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Paged merchant management activity from the systemic audit trail — the
     * same action set the dashboard notification badge counts: product/menu
     * changes, staff and profile changes, and banned-item attempts, by the
     * stall owner or their staff. Routine sales are excluded on purpose,
     * matching the stall detail view's revenue-privacy rule. $since limits it
     * to entries after that timestamp — what the badge was counting.
     */
    public function pagedActivity(int $merchantUserId, int $page, int $perPage, ?string $since = null): array
    {
        if (!gjc_table_exists($this->db, 'systemic_audit_trail')) {
            return ['rows' => [], 'page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 1];
        }

        $page = max(1, $page);
        $perPage = min(50, max(5, $perPage));
        $offset = ($page - 1) * $perPage;

        $actorSql = in_array('merchant_owner_id', gjc_table_columns($this->db, 'users'), true)
            ? 'a.user_id IN (SELECT u2.userID FROM users u2 WHERE u2.userID = ? OR u2.merchant_owner_id = ?)'
            : 'a.user_id = ?';
        $params = $actorSql === 'a.user_id = ?' ? [$merchantUserId] : [$merchantUserId, $merchantUserId];

        $whereSql = "{$actorSql} AND a.action_type IN ('MENU_MUTATION', 'USER_ACCOUNT', 'MERCHANT_PROFILE_UPDATE', 'PRODUCT_RESTRICTION')";
        if ($since !== null) {
            $whereSql .= ' AND a.timestamp > ?';
            $params[] = $since;
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM systemic_audit_trail a WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataStmt = $this->db->prepare(
            "SELECT a.log_id, a.user_role, a.action_type, a.affected_table,
                    a.old_value, a.new_value, a.timestamp,
                    TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS actor_name
             FROM systemic_audit_trail a
             LEFT JOIN users u ON u.userID = a.user_id
             WHERE {$whereSql}
             ORDER BY a.timestamp DESC, a.log_id DESC
             LIMIT ? OFFSET ?"
        );

        $i = 1;
        foreach ($params as $param) {
            $dataStmt->bindValue($i++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $dataStmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue($i, $offset, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'rows' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function toggleProductRestriction(int $itemId, bool $restricted, int $adminId, string $note): bool
    {
        if (!gjc_table_exists($this->db, 'merchant_inventory')) {
            return false;
        }

        $set = [
            'is_restricted = ?',
            'is_available = CASE WHEN ? = 1 THEN 0 ELSE is_available END',
            'restriction_note = ?',
        ];
        $params = [
            $restricted ? 1 : 0,
            $restricted ? 1 : 0,
            $restricted ? ($note ?: 'Restricted by school nutritional compliance review.') : null,
        ];

        $columns = gjc_table_columns($this->db, 'merchant_inventory');
        if (in_array('restricted_by', $columns, true)) {
            $set[] = 'restricted_by = ?';
            $params[] = $adminId;
        }
        if (in_array('restricted_at', $columns, true)) {
            $set[] = 'restricted_at = NOW()';
        }

        $params[] = $itemId;

        $stmt = $this->db->prepare(
            'UPDATE merchant_inventory SET ' . implode(', ', $set) . ' WHERE id = ?'
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function recordRentPayment(int $leaseId, float $amount, string $period, string $paymentDate, string $method, string $notes, int $adminId): string
    {
        $reference = 'RENT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $columns = ['lease_id', 'amount_paid', 'period_covered', 'payment_date'];
        $values = ['?', '?', '?', '?'];
        $params = [
            $leaseId,
            $amount,
            $period,
            $paymentDate,
        ];

        if (in_array('payment_method', gjc_table_columns($this->db, 'merchant_rent_payments'), true)) {
            $columns[] = 'payment_method';
            $values[] = '?';
            $params[] = $method;
        }

        array_push($columns, 'received_by', 'reference_no', 'notes');
        array_push($values, '?', '?', '?');
        array_push($params, $adminId, $reference, $notes ?: null);

        $stmt = $this->db->prepare(
            'INSERT INTO merchant_rent_payments (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')'
        );
        $stmt->execute($params);
        $this->forgetPaymentCache($leaseId);

        return $reference;
    }

    /**
     * Load the rent payments for a whole page of leases in one query, so
     * rendering a 20-row rent roll does not fire 20 separate lookups.
     */
    public function primePaymentCache(array $leaseIds): void
    {
        $leaseIds = array_values(array_unique(array_filter(array_map('intval', $leaseIds))));
        if (!$leaseIds) {
            return;
        }

        if (!gjc_table_exists($this->db, 'merchant_rent_payments')) {
            foreach ($leaseIds as $id) {
                $this->paymentCache[$id] = [];
            }
            return;
        }

        foreach ($leaseIds as $id) {
            $this->paymentCache[$id] = [];
        }

        $placeholders = implode(',', array_fill(0, count($leaseIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT lease_id, period_covered, SUM(amount_paid) AS total
             FROM merchant_rent_payments
             WHERE lease_id IN ({$placeholders})
             GROUP BY lease_id, period_covered"
        );
        $stmt->execute($leaseIds);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->paymentCache[(int) $row['lease_id']][substr((string) $row['period_covered'], 0, 7)] = (float) $row['total'];
        }
    }

    /** Drop cached payment totals for a lease after it has been written to. */
    public function forgetPaymentCache(int $leaseId): void
    {
        unset($this->paymentCache[$leaseId]);
    }

    private function paymentsByPeriod(int $leaseId): array
    {
        if (array_key_exists($leaseId, $this->paymentCache)) {
            return $this->paymentCache[$leaseId];
        }

        if ($leaseId <= 0 || !gjc_table_exists($this->db, 'merchant_rent_payments')) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT period_covered, SUM(amount_paid) AS total
             FROM merchant_rent_payments
             WHERE lease_id = ?
             GROUP BY period_covered"
        );
        $stmt->execute([$leaseId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[substr((string) $row['period_covered'], 0, 7)] = (float) $row['total'];
        }

        return $this->paymentCache[$leaseId] = $out;
    }

    private function periodState(float $rent, float $paid, bool $isDue): string
    {
        if (!$isDue) {
            return $paid > 0.005 ? 'advance' : 'upcoming';
        }
        if ($rent <= 0) {
            return 'no_charge';
        }
        if ($paid - $rent > 0.005) {
            return 'overpaid';
        }
        if ($paid + 0.005 >= $rent) {
            return 'paid';
        }

        return $paid > 0.005 ? 'partial' : 'unpaid';
    }

    /**
     * @return array{0:string,1:string,2:string} state key, short badge label, one-sentence summary
     */
    private function accountNarrative(
        string $status,
        float $rent,
        float $outstanding,
        float $advance,
        int $monthsBehind,
        int $monthsAhead,
        ?string $nextDue,
        int $daysOverdue,
        ?int $daysUntilDue
    ): array {
        $due = $nextDue ? date('M j, Y', strtotime($nextDue)) : null;

        if ($status === 'pending') {
            return ['pending', 'Not started',
                'This contract is not active yet, so no rent is being billed. Switch it to Active once the stall opens.'];
        }

        if ($status === 'terminated' || $status === 'expired') {
            $word = $status === 'terminated' ? 'terminated' : 'expired';
            return ['closed', ucfirst($word), $outstanding > 0.005
                ? 'Contract ' . $word . ' with ' . gjc_money_plain($outstanding) . ' still uncollected.'
                : 'Contract ' . $word . ' and fully settled.'];
        }

        if ($rent <= 0) {
            return ['settled', 'No rent', 'No monthly rent is set on this lease, so nothing is being billed.'];
        }

        if ($outstanding > 0.005) {
            $months = $monthsBehind === 1 ? '1 month' : $monthsBehind . ' months';
            $late   = $daysOverdue > 0
                ? ' - ' . $daysOverdue . ' day' . ($daysOverdue === 1 ? '' : 's') . ' past due'
                : '';
            return ['overdue', 'Owes rent',
                'Owes ' . gjc_money_plain($outstanding) . ' across ' . $months . $late .
                ($due ? '. Oldest unpaid month was due ' . $due . '.' : '.')];
        }

        if ($monthsAhead > 0) {
            $months = $monthsAhead === 1 ? '1 month' : $monthsAhead . ' months';
            return ['ahead', 'Paid ahead',
                'Fully paid and ' . $months . ' ahead' . ($due ? '. Next payment due ' . $due . '.' : '.')];
        }

        if ($advance > 0.005) {
            return ['ahead', 'Paid ahead',
                'Fully paid, with ' . gjc_money_plain($advance) . ' credited beyond what has been billed so far' .
                ($due ? '. Next payment due ' . $due . '.' : '.')];
        }

        if ($due === null) {
            return ['settled', 'Settled', 'Every month in this contract has been paid.'];
        }

        $when = $daysUntilDue === null
            ? ''
            : ($daysUntilDue === 0 ? ' - that is today' : ' - in ' . $daysUntilDue . ' day' . ($daysUntilDue === 1 ? '' : 's'));

        return ['settled', 'Up to date',
            'Up to date. Next payment of ' . gjc_money_plain($rent) . ' is due ' . $due . $when . '.'];
    }

    /** Whole-month arithmetic that clamps instead of overflowing (Jan 31 + 1 month = Feb 28). */
    private function addMonths(string $date, int $months): string
    {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt) {
            return $date;
        }

        $day = (int) $dt->format('j');
        $dt->setDate((int) $dt->format('Y'), (int) $dt->format('n'), 1);
        $dt->modify(($months >= 0 ? '+' : '-') . abs($months) . ' months');
        $dt->setDate((int) $dt->format('Y'), (int) $dt->format('n'), min($day, (int) $dt->format('t')));

        return $dt->format('Y-m-d');
    }

    /**
     * How many monthly rent charges the contract term contains. lease_end is
     * the closing boundary, not a billable day: a Jun 20 -> Jun 20 next-year
     * lease bills 12 months, not 13.
     */
    private function termPeriods(string $start, string $end): int
    {
        $startTs = strtotime($start);
        $endTs   = strtotime($end);
        if (!$startTs || !$endTs || $endTs <= $startTs) {
            return 0;
        }

        $months = ((int) date('Y', $endTs) - (int) date('Y', $startTs)) * 12
                + ((int) date('n', $endTs) - (int) date('n', $startTs));
        if ((int) date('j', $endTs) <= (int) date('j', $startTs)) {
            $months--;
        }

        return max(1, $months + 1);
    }

    private function isDate(string $date): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt && $dt->format('Y-m-d') === $date;
    }
}
