<?php
// api/midtrans/notification.php
// Endpoint webhook/Payment Notification URL Midtrans.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/midtrans.php';
require_method('POST');

$pdo = get_pdo();
$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);

if (!is_array($payload)) {
    fail('Payload notification tidak valid.', 400);
}

if (!midtrans_signature_is_valid($payload)) {
    fail('Signature Midtrans tidak valid.', 403);
}

$orderId = clean_string($payload['order_id'] ?? '', 80);
if ($orderId === '') {
    fail('Order ID kosong.', 422);
}

$transaction = midtrans_find_transaction_by_order_id($pdo, $orderId);
if (!$transaction) {
    fail('Transaksi tidak ditemukan.', 404);
}

try {
    $result = midtrans_apply_payment_status($pdo, $transaction, $payload, 'Webhook Midtrans');
    ok($result, 'Notification Midtrans berhasil diproses.');
} catch (Throwable $e) {
    fail(APP_DEBUG ? $e->getMessage() : 'Gagal memproses notification Midtrans.', 500);
}
