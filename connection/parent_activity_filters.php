<?php

/** Display categories mapped to current and historical ledger types. */
function gjc_parent_activity_types(): array
{
    return [
        'payment' => ['payment'],
        'topup' => ['cash_in', 'topup', 'top_up'],
        'encashment' => ['merchant_settle', 'encashment'],
        'cap_increase' => ['cap_increase'],
        'refund' => ['refund'],
        'allowance' => ['allowance'],
        'fee_waiver_claim' => ['fee_waiver_claim'],
        'p2p_transfer' => ['p2p_transfer'],
        'student_withdraw' => ['student_withdraw'],
    ];
}

function gjc_parent_activity_date(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

/** Build an authorized wallet scope, then apply category and date filters. */
function gjc_parent_activity_where(array $walletIds, int $parentWalletId, string $walletFilter, string $type, string $from, string $to): array
{
    $params = [];
    $scopes = [];
    if ($walletFilter === 'me' && $parentWalletId > 0) {
        $scopes[] = 't.parent_wallet_id = ?';
        $params[] = $parentWalletId;
    } elseif ((int) $walletFilter > 0 && in_array((int) $walletFilter, $walletIds, true)) {
        $scopes[] = 't.student_wallet_id = ?';
        $params[] = (int) $walletFilter;
    } else {
        if ($walletIds) {
            $scopes[] = 't.student_wallet_id IN (' . implode(',', array_fill(0, count($walletIds), '?')) . ')';
            $params = array_merge($params, $walletIds);
        }
        if ($parentWalletId > 0) {
            $scopes[] = 't.parent_wallet_id = ?';
            $params[] = $parentWalletId;
        }
    }
    $where = 'WHERE (' . ($scopes ? implode(' OR ', $scopes) : '1 = 0') . ')';
    $types = gjc_parent_activity_types()[$type] ?? [];
    if ($types) {
        $where .= ' AND t.transaction_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $params = array_merge($params, $types);
    }
    if (gjc_parent_activity_date($from) !== '') {
        $where .= ' AND t.created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if (gjc_parent_activity_date($to) !== '') {
        $where .= ' AND t.created_at < ?';
        $params[] = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
    return [$where, $params];
}
