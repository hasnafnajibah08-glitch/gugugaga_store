<?php
// api/user/midtrans_status.php
// Sinkronisasi status invoice user dengan Midtrans Get Status API.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/midtrans.php';
require_method('GET');

$pdo = get_pdo();
$user = require_customer($pdo);

$invoiceNo = clean_string($_GET['invoice_no'] ?? '', 80);

if ($invoiceNo === '') {
    fail('Nomor invoice wajib diisi.', 422);
}

$stmt = $pdo->prepare('SELECT * FROM transactions WHERE invoice_no = ? AND user_id = ? LIMIT 1');
$stmt->execute([$invoiceNo, (int) $user['id']]);
$transaction = $stmt->fetch();

if (!$transaction) {
    fail('Invoice tidak ditemukan.', 404);
}

try {
    $payload = midtrans_get_status($invoiceNo);
    $result = midtrans_apply_payment_status($pdo, $transaction, $payload, 'Get Status Midtrans');

    ok([
        'transaction' => $result,
        'midtrans' => [
            'transaction_status' => $payload['transaction_status'] ?? null,
            'payment_type' => $payload['payment_type'] ?? null,
            'fraud_status' => $payload['fraud_status'] ?? null,
        ],
    ], 'Status Midtrans berhasil disinkronkan.');
} catch (Throwable $e) {
    fail(APP_DEBUG ? $e->getMessage() : 'Gagal mengecek status Midtrans.', 500);
}
