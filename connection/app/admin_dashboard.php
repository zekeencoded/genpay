<?php

// Admin dashboard counts, money flow and finance queues.

function gjc_count_users_by_role(PDO $db, string $roleName): int
{
    if (!gjc_table_exists($db, "users")) {
        return 0;
    }

    $roleName = strtolower(trim($roleName));

    if (gjc_table_exists($db, "role")) {
        $stmt = $db->prepare(
            "SELECT COUNT(*)
               FROM users u
               LEFT JOIN role r ON u.roleID = r.roleID
              WHERE LOWER(COALESCE(r.role_name, '')) = ?",
        );
        $stmt->execute([$roleName]);
        return (int) $stmt->fetchColumn();
    }

    $roleMap = [
        "student" => 1,
        "merchant" => 2,
        "finance" => 4,
        "admin" => 4,
        "super-admin" => 4,
    ];

    if (
        isset($roleMap[$roleName]) &&
        in_array("roleID", gjc_table_columns($db, "users"), true)
    ) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE roleID = ?");
        $stmt->execute([$roleMap[$roleName]]);
        return (int) $stmt->fetchColumn();
    }

    return 0;
}

/**
 * Daily money flow for the dashboard chart, from completed ledger rows:
 * loaded (top-ups, credits), spent (stall payments), cashed_out (encashments, withdrawals).
 */
function gjc_dashboard_money_flow(PDO $db, int $days = 7): array
{
    $days = max(1, $days);
    $labels = [];
    $flow = [];
    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $date = date("Y-m-d", strtotime("-{$offset} days"));
        $labels[] = date("M d", strtotime($date));
        $flow[$date] = ["loaded" => 0.0, "spent" => 0.0, "cashed_out" => 0.0];
    }

    $bucketOf = [
        "cash_in" => "loaded",
        "tuition_credit" => "loaded",
        "payment" => "spent",
        "merchant_settle" => "cashed_out",
        "student_withdraw" => "cashed_out",
    ];

    if (gjc_table_exists($db, "transactions")) {
        $marks = implode(",", array_fill(0, count($bucketOf), "?"));
        $stmt = $db->prepare(
            "SELECT DATE(created_at) AS d, transaction_type, SUM(amount) AS total
               FROM transactions
              WHERE status = 'completed'
                AND created_at >= ?
                AND transaction_type IN ($marks)
              GROUP BY DATE(created_at), transaction_type",
        );
        $stmt->execute([
            date("Y-m-d", strtotime("-" . ($days - 1) . " days")) . " 00:00:00",
            ...array_keys($bucketOf),
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bucket = $bucketOf[$row["transaction_type"]] ?? null;
            if ($bucket !== null && isset($flow[$row["d"]])) {
                $flow[$row["d"]][$bucket] += (float) $row["total"];
            }
        }
    }

    $series = ["loaded" => [], "spent" => [], "cashed_out" => []];
    foreach ($flow as $day) {
        foreach ($series as $key => $_) {
            $series[$key][] = round($day[$key], 2);
        }
    }

    return ["labels" => $labels, "series" => $series];
}

/** Finance queue counts for the dashboard and sidebar badges. Never throws. */
function gjc_finance_queue_counts(PDO $db): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $counts = [
        'encashments' => 0,
        'stall_applications' => 0,
    ];

    $queries = [
        'encashments' => ['encashment_requests', "SELECT COUNT(*) FROM encashment_requests WHERE status = 'pending'"],
        'stall_applications' => ['stall_applications', "SELECT COUNT(*) FROM stall_applications WHERE status = 'pending_verification'"],
    ];

    foreach ($queries as $key => [$table, $sql]) {
        try {
            if (gjc_table_exists($db, $table)) {
                $counts[$key] = (int) $db->query($sql)->fetchColumn();
            }
        } catch (Throwable $e) {
            // Leave it at zero: a broken count must not break the page.
        }
    }

    $cached = $counts;
    return $counts;
}

function gjc_admin_dashboard_data(PDO $db): array
{
    gjc_ensure_operational_tables($db);

    $snapshot = [];
    if (gjc_table_exists($db, "system_settings")) {
        try {
            require_once __DIR__ . "/../CirculationEngine.php";
            $engine = new CirculationEngine($db);
            $snapshot = $engine->getCirculationSnapshot();
        } catch (\Throwable) {
            $snapshot = [];
        }
    }

    $transactions = gjc_fetch_admin_transactions($db, [], 0);
    $stats = gjc_admin_transaction_stats($transactions);
    $recentTransactions = array_slice($transactions, 0, 10);
    $moneyFlow = gjc_dashboard_money_flow($db, 7);

    $studentWalletsTotal = (float) ($snapshot["student_wallets_total"] ?? 0);
    $merchantWalletsTotal = (float) ($snapshot["merchant_wallets_total"] ?? 0);
    $circulatingBalance =
        $studentWalletsTotal + $merchantWalletsTotal;

    $pendingEncashments = 0;
    if (gjc_table_exists($db, "encashment_requests")) {
        $pendingEncashments = (int) $db
            ->query(
                "SELECT COUNT(*) FROM encashment_requests WHERE status = 'pending'",
            )
            ->fetchColumn();
    }

    $totalUsers = 0;
    if (gjc_table_exists($db, "users")) {
        $totalUsers = gjc_table_exists($db, "role")
            ? (int) $db->query(
                "SELECT COUNT(*)
                   FROM users u
                   LEFT JOIN role r ON u.roleID = r.roleID
                  WHERE LOWER(COALESCE(r.role_name, '')) != 'finance'"
            )->fetchColumn()
            : (int) $db->query(
                "SELECT COUNT(*) FROM users WHERE roleID NOT IN (3, 4)"
            )->fetchColumn();
    }

    return [
        "system_financials" => [
            "circulating_balance" => $circulatingBalance,
            "todays_volume" => (float) $stats["todays_volume"],
            "pending_encashments" => $pendingEncashments,
        ],
        "user_demographics" => [
            "total_users" => $totalUsers,
            "active_students" => gjc_count_users_by_role($db, "student"),
            "active_merchants" => gjc_count_users_by_role($db, "merchant"),
        ],
        "recent_transactions" => $recentTransactions,
        "money_flow" => $moneyFlow,
    ];
}
