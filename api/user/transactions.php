<?php
// api/user/transactions.php
// Riwayat transaksi milik customer yang sedang login.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/midtrans.php';
require_method('GET');

$pdo = get_pdo();
$user = require_customer($pdo);

$midtransOrderSelect = midtrans_column_exists($pdo, 'transactions', 'midtrans_order_id') ? 't.midtrans_order_id' : 'NULL AS midtrans_order_id';
$midtransTokenSelect = midtrans_column_exists($pdo, 'transactions', 'midtrans_snap_token') ? 't.midtrans_snap_token' : 'NULL AS midtrans_snap_token';
$midtransRedirectSelect = midtrans_column_exists($pdo, 'transactions', 'midtrans_redirect_url') ? 't.midtrans_redirect_url' : 'NULL AS midtrans_redirect_url';
$midtransPaymentTypeSelect = midtrans_column_exists($pdo, 'transactions', 'midtrans_payment_type') ? 't.midtrans_payment_type' : 'NULL AS midtrans_payment_type';
$midtransStatusSelect = midtrans_column_exists($pdo, 'transactions', 'midtrans_transaction_status') ? 't.midtrans_transaction_status' : 'NULL AS midtrans_transaction_status';

$stmt = $pdo->prepare(
    'SELECT
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
        ' . $midtransOrderSelect . ',
        ' . $midtransTokenSelect . ',
        ' . $midtransRedirectSelect . ',
        ' . $midtransPaymentTypeSelect . ',
        ' . $midtransStatusSelect . ',
        pm.name AS payment_method,
        pm.code AS payment_method_code,
        pm.method_type,
        pm.qris_image_url,
        pc.status AS confirmation_status,
        pc.proof_file_path,
        pc.amount_paid,
        GROUP_CONCAT(
            CONCAT(g.name, " - ", ti.product_name, " x", ti.quantity, " (", ti.game_user_identifier, IF(ti.game_server IS NULL OR ti.game_server = "", "", CONCAT(" / ", ti.game_server)), ")")
            ORDER BY ti.id SEPARATOR " || "
        ) AS items_summary
     FROM transactions t
     LEFT JOIN payment_methods pm ON pm.id = t.payment_method_id
     LEFT JOIN payment_confirmations pc ON pc.id = (
        SELECT pc2.id
        FROM payment_confirmations pc2
        WHERE pc2.transaction_id = t.id
        ORDER BY pc2.created_at DESC, pc2.id DESC
        LIMIT 1
     )
     LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
     LEFT JOIN games g ON g.id = ti.game_id
     WHERE t.user_id = ?
     GROUP BY
        t.id, t.invoice_no, t.total_amount, t.status, t.payment_status, t.customer_note,
        t.admin_note, t.paid_at, t.expired_at, t.created_at,
        midtrans_order_id, midtrans_snap_token, midtrans_redirect_url, midtrans_payment_type, midtrans_transaction_status,
        pm.name, pm.code, pm.method_type, pm.qris_image_url,
        pc.status, pc.proof_file_path, pc.amount_paid
     ORDER BY t.created_at DESC
     LIMIT 50'
);
$stmt->execute([(int) $user['id']]);

ok([
    'transactions' => $stmt->fetchAll(),
], 'Riwayat transaksi berhasil dimuat.');
