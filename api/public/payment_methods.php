<?php
// api/public/payment_methods.php
// Metode pembayaran aktif beserta rekening bank jika ada.
require_once __DIR__ . '/../../config/bootstrap.php';
require_method('GET');

$pdo = get_pdo();

function payment_column_exists(PDO $pdo, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = "payment_methods"
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$qrisPayloadSelect = payment_column_exists($pdo, 'qris_payload')
    ? 'qris_payload'
    : 'NULL AS qris_payload';

$methods = $pdo->query(
    'SELECT id, code, name, method_type, logo_url, qris_image_url, ' . $qrisPayloadSelect . ', instructions
     FROM payment_methods
     WHERE is_active = 1
     ORDER BY sort_order ASC, name ASC'
)->fetchAll();

$bankAccounts = $pdo->query(
    'SELECT id, payment_method_id, bank_name, account_number, account_name, branch_name
     FROM bank_accounts
     WHERE is_active = 1
     ORDER BY bank_name ASC, account_number ASC'
)->fetchAll();

$accountsByMethod = [];
foreach ($bankAccounts as $account) {
    $key = (int) $account['payment_method_id'];
    $accountsByMethod[$key][] = $account;
}

foreach ($methods as &$method) {
    $method['bank_accounts'] = $accountsByMethod[(int) $method['id']] ?? [];
    // Jangan expose payload QRIS mentah ke halaman checkout sebelum invoice dibuat.
    // Payload invoice dinamis dibuat lewat api/user/generate_qris.php.
    unset($method['qris_payload']);
}
unset($method);

ok([
    'payment_methods' => $methods,
], 'Metode pembayaran berhasil dimuat.');
