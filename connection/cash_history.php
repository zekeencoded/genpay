<?php

/** Read cash-in history from the same ledger used by student and parent sends. */
function gjc_recent_cash_in_history(PDO $db): array
{
    return $db->query(
        "SELECT t.id, t.reference_no, t.amount, t.top_up_source, t.status, t.created_at,
                COALESCE(p.user_id, sw.user_id) AS recipient_user_id,
                CASE WHEN t.parent_wallet_id IS NOT NULL THEN 'Parent' ELSE 'Student' END AS recipient_role
           FROM transactions t
           LEFT JOIN student_wallets sw ON sw.id = t.student_wallet_id
           LEFT JOIN parent_wallets pw ON pw.id = t.parent_wallet_id
           LEFT JOIN parents p ON p.id = pw.parent_id
          WHERE t.transaction_type = 'cash_in'
          ORDER BY t.created_at DESC, t.id DESC
          LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function gjc_cash_in_loaded_today(PDO $db): float
{
    return (float) $db->query(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
          WHERE transaction_type = 'cash_in' AND status = 'completed'
            AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY"
    )->fetchColumn();
}
