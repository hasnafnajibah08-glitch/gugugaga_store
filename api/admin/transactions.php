<?php
// api/admin/transactions.php
// List transaksi admin, cocok dengan schema gugugaga_store_schema.sql.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_method('GET');

$pdo = get_pdo();
require_admin($pdo);

$search = clean_string($_GET['search'] ?? '', 150);
$status = clean_string($_GET['status'] ?? '', 50);
$paymentStatus = clean_string($_GET['payment_status'] ?? '', 50);
$approval = clean_string($_GET['approval'] ?? '', 50); // needs_approval

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(
        t.invoice_no LIKE ?
        OR u.username LIKE ?
        OR u.email LIKE ?
        OR u.full_name LIKE ?
        OR u.phone LIKE ?
    )';
    $keyword = '%' . $search . '%';
    array_push($params, $keyword, $keyword, $keyword, $keyword, $keyword);
}

if ($status !== '') {
    $where[] = 't.status = ?';
    $params[] = $status;
}

if ($paymentStatus !== '') {
    $where[] = 't.payment_status = ?';
    $params[] = $paymentStatus;
}

if ($approval === 'needs_approval') {
    $where[] = '(t.payment_status = "pending_confirmation" OR pc.status = "submitted")';
}

$sql = '
    SELECT
        t.id,
        t.invoice_no,
        t.total_amount,
        t.status,
        t.payment_status,
        t.customer_note,
        t.admin_note,
        t.paid_at,
        t.expired_at,
        t.created_at,
        t.updated_at,

        u.username,
        u.email,
        u.phone,
        u.full_name,

        pm.name AS payment_method,
        pm.method_type AS payment_method_type,

        pc.id AS payment_confirmation_id,
        pc.status AS confirmation_status,
        pc.amount_paid,
        pc.proof_file_path,
        pc.sender_bank,
        pc.sender_account_name,
        pc.sender_account_number,
        pc.note AS confirmation_note,
        pc.paid_at AS confirmation_paid_at,
        pc.created_at AS confirmation_created_at,

        item_summary.items_summary
    FROM transactions t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN payment_methods pm ON pm.id = t.payment_method_id
    LEFT JOIN (
        SELECT pc1.*
        FROM payment_confirmations pc1
        JOIN (
            SELECT transaction_id, MAX(id) AS max_id
            FROM payment_confirmations
            GROUP BY transaction_id
        ) latest_pc ON latest_pc.max_id = pc1.id
    ) pc ON pc.transaction_id = t.id
    LEFT JOIN (
        SELECT
            ti.transaction_id,
            GROUP_CONCAT(
                CONCAT(
                    g.name,
                    " - ",
                    ti.product_name,
                    " x",
                    ti.quantity,
                    " | ID: ",
                    ti.game_user_identifier,
                    IF(ti.game_server IS NULL OR ti.game_server = "", "", CONCAT(" | Server: ", ti.game_server))
                )
                ORDER BY ti.id ASC
                SEPARATOR " || "
            ) AS items_summary
        FROM transaction_items ti
        JOIN games g ON g.id = ti.game_id
        GROUP BY ti.transaction_id
    ) item_summary ON item_summary.transaction_id = t.id
';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY t.created_at DESC LIMIT 150';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

ok([
    'transactions' => $stmt->fetchAll(),
]);
