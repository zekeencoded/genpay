<?php

// Stall registry and lease stall lookups.

/** Claim a stall for a merchant inside the caller's transaction. False if reserved or taken. */
function gjc_claim_stall(PDO $db, string $stallId, int $merchantId): bool
{
    if (!$db->inTransaction()) {
        throw new LogicException('Claiming a stall requires an open transaction.');
    }
    $stmt = $db->prepare("SELECT status FROM stalls WHERE stall_id = ? FOR UPDATE");
    $stmt->execute([$stallId]);
    $status = $stmt->fetchColumn();
    $others = $db->prepare("SELECT COUNT(*) FROM merchant WHERE stall_id = ? AND merchantID <> ?");
    $others->execute([$stallId, $merchantId]);
    if ($status !== 'vacant' || (int) $others->fetchColumn() !== 0) {
        return false;
    }
    if (in_array('merchant_id', gjc_table_columns($db, 'stalls'), true)) {
        $db->prepare("UPDATE stalls SET status = 'occupied', merchant_id = ?, pending_expires_at = NULL WHERE stall_id = ?")
           ->execute([$merchantId, $stallId]);
    } else {
        $db->prepare("UPDATE stalls SET pending_expires_at = NULL WHERE stall_id = ?")->execute([$stallId]);
    }
    return true;
}

/** Whether merchant_leases still has the legacy stall_number column. */
function gjc_lease_has_stall_number(PDO $db): bool
{
    return in_array('stall_number', gjc_table_columns($db, 'merchant_leases'), true);
}

/** The stall code shown for a lease row. */
function gjc_lease_stall_code(array $lease): string
{
    $id = (string) ($lease['stall_id'] ?? '');
    return $id !== '' ? $id : (string) ($lease['stall_number'] ?? '');
}

/** SQL for a lease's stall code, for WHERE/ORDER clauses on merchant_leases \$alias. */
function gjc_lease_stall_sql(PDO $db, string $alias = 'ml'): string
{
    return gjc_lease_has_stall_number($db) ? "COALESCE({$alias}.stall_id, {$alias}.stall_number)" : "{$alias}.stall_id";
}

/** Stall picker <option>s labelled with the current occupant. */
function gjc_stall_registry_options_html(PDO $db, string $selected = ''): string
{
    if (!gjc_table_exists($db, 'stalls')) {
        return '';
    }
    $rows = $db->query(
        "SELECT s.stall_id, s.label, m.stall_name AS occupant
           FROM stalls s LEFT JOIN merchant m ON m.stall_id = s.stall_id
          ORDER BY s.row_label, s.col_number, s.stall_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $html = '';
    foreach ($rows as $row) {
        $occupant = trim((string) ($row['occupant'] ?? ''));
        $html .= '<option value="' . $e($row['stall_id']) . '"' . ((string) $row['stall_id'] === $selected ? ' selected' : '') . '>'
            . $e($row['stall_id']) . ' — ' . $e($row['label']) . ' · ' . ($occupant !== '' ? $e($occupant) : 'Vacant')
            . '</option>';
    }
    return $html;
}

/** A registry stall code, or null if it does not exist. */
function gjc_registry_stall_id(PDO $db, string $stallId): ?string
{
    $stmt = $db->prepare("SELECT stall_id FROM stalls WHERE stall_id = ? LIMIT 1");
    $stmt->execute([$stallId]);
    $found = $stmt->fetchColumn();
    return $found === false ? null : (string) $found;
}
