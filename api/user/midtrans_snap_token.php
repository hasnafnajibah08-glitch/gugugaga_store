<?php
// api/user/midtrans_snap_token.php
// Membuat/mengambil Snap token untuk invoice Midtrans milik customer.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/midtrans.php';
require_method('POST');

$pdo = get_pdo();
$user = require_customer($pdo);
$data = request_data();

$invoiceNo = clean_string($data['invoice_no'] ?? '', 80);

if ($invoiceNo === '') {
    fail('Nomor invoice wajib diisi.', 422);
}

$stmt = $pdo->prepare(
    'SELECT
        t.id,
        t.invoice_no,
        t.user_id,
        t.total_amount,
        t.status,
        t.payment_status,
        t.expired_at,
        pm.id AS payment_method_id,
        pm.code AS payment_method_code,
        pm.name AS payment_method_name,
        pm.method_type
     FROM transactions t
     JOIN payment_methods pm ON pm.id = t.payment_method_id
     WHERE t.invoice_no = ? AND t.user_id = ?
     LIMIT 1'
);
$stmt->execute([$invoiceNo, (int) $user['id']]);
$transaction = $stmt->fetch();

if (!$transaction) {
    fail('Invoice tidak ditemukan.', 404);
}

$method = [
    'id' => $transaction['payment_method_id'],
    'code' => $transaction['payment_method_code'],
    'name' => $transaction['payment_method_name'],
    'method_type' => $transaction['method_type'],
];

if (!midtrans_is_snap_payment_method($method)) {
    fail('Invoice ini bukan pembayaran Midtrans Snap.', 422);
}

if (($transaction['payment_status'] ?? '') === 'paid') {
    ok([
        'invoice_no' => $invoiceNo,
        'status' => $transaction['status'],
        'payment_status' => $transaction['payment_status'],
    ], 'Invoice ini sudah dibayar.');
}

if (!empty($transaction['expired_at']) && strtotime((string) $transaction['expired_at']) < time()) {
    fail('Invoice sudah kedaluwarsa. Silakan checkout ulang.', 422);
}

$itemStmt = $pdo->prepare(
    'SELECT
        ti.product_id,
        ti.product_name,
        ti.quantity,
        ti.unit_price,
        g.name AS game_name
     FROM transaction_items ti
     LEFT JOIN games g ON g.id = ti.game_id
     WHERE ti.transaction_id = ?
     ORDER BY ti.id ASC'
);
$itemStmt->execute([(int) $transaction['id']]);
$items = $itemStmt->fetchAll();

try {
    $snap = midtrans_create_snap_for_transaction($pdo, $transaction, $user, $items, true);

    ok([
        'invoice_no' => $invoiceNo,
        'snap_token' => $snap['snap_token'],
        'redirect_url' => $snap['redirect_url'],
        'order_id' => $snap['order_id'],
        'reused' => $snap['reused'],
    ], 'Snap token berhasil dibuat.');
} catch (Throwable $e) {
    fail(APP_DEBUG ? $e->getMessage() : 'Gagal membuat Snap token Midtrans.', 500);
}
