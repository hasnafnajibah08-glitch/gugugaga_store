<?php
// api/user/checkout.php
// Customer membuat invoice dari keranjang frontend.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/midtrans.php';
require_method('POST');

$pdo = get_pdo();
$user = require_customer($pdo);
$data = request_data();

$paymentMethodId = (int) ($data['payment_method_id'] ?? 0);
$customerNote = clean_string($data['customer_note'] ?? '', 1000);
$items = $data['items'] ?? [];

if ($paymentMethodId <= 0) {
    fail('Metode pembayaran wajib dipilih.', 422);
}

if (!is_array($items) || count($items) < 1) {
    fail('Keranjang masih kosong.', 422);
}

if (count($items) > 20) {
    fail('Maksimal 20 item dalam satu checkout.', 422);
}

$methodStmt = $pdo->prepare(
    'SELECT id, code, name, method_type, logo_url, qris_image_url, instructions
     FROM payment_methods
     WHERE id = ? AND is_active = 1
     LIMIT 1'
);
$methodStmt->execute([$paymentMethodId]);
$paymentMethod = $methodStmt->fetch();

if (!$paymentMethod) {
    fail('Metode pembayaran tidak tersedia.', 404);
}

$isMidtransSnap = midtrans_is_snap_payment_method($paymentMethod);
if ($isMidtransSnap && !midtrans_config_is_ready(true)) {
    fail('Konfigurasi Midtrans belum lengkap. Isi MIDTRANS_SERVER_KEY dan MIDTRANS_CLIENT_KEY di config/midtrans.php.', 500);
}

$normalizedItems = [];
$totalAmount = 0.0;

foreach ($items as $index => $item) {
    if (!is_array($item)) {
        fail('Format item keranjang tidak valid.', 422);
    }

    $productId = (int) ($item['product_id'] ?? 0);
    $identifier = clean_string($item['game_user_identifier'] ?? '', 120);
    $server = clean_string($item['game_server'] ?? '', 120);
    $quantity = (int) ($item['quantity'] ?? 1);

    if ($productId <= 0) {
        fail('Produk pada keranjang tidak valid.', 422);
    }

    if ($identifier === '') {
        fail('ID game wajib diisi pada semua item.', 422);
    }

    $productStmt = $pdo->prepare(
        'SELECT
            p.id AS product_id,
            p.game_id,
            p.name AS product_name,
            p.unit_price,
            p.min_qty,
            p.max_qty,
            g.name AS game_name,
            g.requires_server
         FROM topup_products p
         JOIN games g ON g.id = p.game_id
         WHERE p.id = ? AND p.is_active = 1 AND g.is_active = 1
         LIMIT 1'
    );
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();

    if (!$product) {
        fail('Produk tidak ditemukan atau sedang nonaktif.', 404);
    }

    $minQty = (int) $product['min_qty'];
    $maxQty = (int) $product['max_qty'];

    if ($quantity < $minQty || $quantity > $maxQty) {
        fail('Jumlah item ' . $product['product_name'] . ' harus antara ' . $minQty . ' sampai ' . $maxQty . '.', 422);
    }

    if ((int) $product['requires_server'] === 1 && $server === '') {
        fail('Server wajib diisi untuk ' . $product['game_name'] . '.', 422);
    }

    $unitPrice = (float) $product['unit_price'];
    $subtotal = $unitPrice * $quantity;
    $totalAmount += $subtotal;

    $normalizedItems[] = [
        'game_id' => (int) $product['game_id'],
        'product_id' => (int) $product['product_id'],
        'game_name' => $product['game_name'],
        'product_name' => $product['product_name'],
        'game_user_identifier' => $identifier,
        'game_server' => $server !== '' ? $server : null,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
    ];
}

if ($totalAmount <= 0) {
    fail('Total transaksi tidak valid.', 422);
}

function generate_invoice_no(PDO $pdo): string
{
    do {
        $invoiceNo = 'GG' . date('YmdHis') . random_int(100, 999);
        $stmt = $pdo->prepare('SELECT id FROM transactions WHERE invoice_no = ? LIMIT 1');
        $stmt->execute([$invoiceNo]);
    } while ($stmt->fetch());

    return $invoiceNo;
}

$pdo->beginTransaction();

try {
    $cartStmt = $pdo->prepare('INSERT INTO carts (user_id, status) VALUES (?, "checked_out")');
    $cartStmt->execute([(int) $user['id']]);
    $cartId = (int) $pdo->lastInsertId();

    $cartItemStmt = $pdo->prepare(
        'INSERT INTO cart_items
         (cart_id, game_id, product_id, game_user_identifier, game_server, quantity, unit_price)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($normalizedItems as $item) {
        $cartItemStmt->execute([
            $cartId,
            $item['game_id'],
            $item['product_id'],
            $item['game_user_identifier'],
            $item['game_server'],
            $item['quantity'],
            $item['unit_price'],
        ]);
    }

    $invoiceNo = generate_invoice_no($pdo);
    $status = 'waiting_payment';
    $paymentStatus = 'unpaid';
    $expiredAt = date('Y-m-d H:i:s', time() + 86400);

    $transactionStmt = $pdo->prepare(
        'INSERT INTO transactions
         (invoice_no, user_id, cart_id, payment_method_id, total_amount, status, payment_status, customer_note, expired_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, ""), ?)'
    );
    $transactionStmt->execute([
        $invoiceNo,
        (int) $user['id'],
        $cartId,
        $paymentMethodId,
        $totalAmount,
        $status,
        $paymentStatus,
        $customerNote,
        $expiredAt,
    ]);

    $transactionId = (int) $pdo->lastInsertId();

    $transactionItemStmt = $pdo->prepare(
        'INSERT INTO transaction_items
         (transaction_id, game_id, product_id, game_user_identifier, game_server, product_name, quantity, unit_price)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($normalizedItems as $item) {
        $transactionItemStmt->execute([
            $transactionId,
            $item['game_id'],
            $item['product_id'],
            $item['game_user_identifier'],
            $item['game_server'],
            $item['product_name'],
            $item['quantity'],
            $item['unit_price'],
        ]);
    }

    $midtransSnap = null;
    if ($isMidtransSnap) {
        $midtransSnap = midtrans_create_snap_for_transaction(
            $pdo,
            [
                'id' => $transactionId,
                'invoice_no' => $invoiceNo,
                'total_amount' => $totalAmount,
            ],
            $user,
            $normalizedItems,
            false
        );
    }

    $logStmt = $pdo->prepare(
        'INSERT INTO transaction_status_logs (transaction_id, old_status, new_status, changed_by, note)
         VALUES (?, NULL, ?, ?, ?)'
    );
    $logStmt->execute([
        $transactionId,
        $status,
        (int) $user['id'],
        'Invoice dibuat oleh customer.',
    ]);

    $notifStmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, notification_type)
         VALUES (?, ?, ?, "transaction")'
    );
    $notifStmt->execute([
        (int) $user['id'],
        'Invoice dibuat',
        'Invoice ' . $invoiceNo . ' berhasil dibuat. Silakan lakukan pembayaran.',
    ]);

    audit_log($pdo, (int) $user['id'], 'create_transaction', 'transactions', $transactionId, [
        'invoice_no' => $invoiceNo,
        'total_amount' => $totalAmount,
        'payment_method_id' => $paymentMethodId,
    ]);

    $pdo->commit();

    ok([
        'transaction' => [
            'id' => $transactionId,
            'invoice_no' => $invoiceNo,
            'total_amount' => $totalAmount,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'payment_method_code' => $paymentMethod['code'] ?? null,
            'method_type' => $paymentMethod['method_type'] ?? null,
            'qris_image_url' => $paymentMethod['qris_image_url'] ?? null,
            'expired_at' => $expiredAt,
        ],
        'midtrans' => $midtransSnap,
    ], $isMidtransSnap ? 'Invoice berhasil dibuat. Lanjutkan pembayaran di Midtrans Snap.' : 'Invoice berhasil dibuat.', 201);
} catch (Throwable $e) {
    $pdo->rollBack();
    fail(APP_DEBUG ? 'Gagal membuat invoice: ' . $e->getMessage() : 'Gagal membuat invoice.', 500);
}
