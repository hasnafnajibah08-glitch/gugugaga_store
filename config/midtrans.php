<?php
// config/midtrans.php
// Helper integrasi Midtrans Snap untuk GUGUGAGA.STORE.
// Paling mudah: isi key lewat environment variable, atau edit fallback string kosong di bawah.

if (!function_exists('get_pdo')) {
    require_once __DIR__ . '/bootstrap.php';
}

if (defined('GUGUGAGA_MIDTRANS_HELPER_LOADED')) {
    return;
}

define('GUGUGAGA_MIDTRANS_HELPER_LOADED', true);

// ================================
// Konfigurasi Midtrans
// ================================
// Rekomendasi produksi: simpan key di environment variable, bukan commit ke Git.
// Untuk XAMPP/Laragon lokal, kamu boleh sementara isi fallback string kosong ini.
// Contoh sandbox:
// define('MIDTRANS_SERVER_KEY', getenv('MIDTRANS_SERVER_KEY') ?: 'SB-Mid-server-xxxxxxxx');
// define('MIDTRANS_CLIENT_KEY', getenv('MIDTRANS_CLIENT_KEY') ?: 'SB-Mid-client-xxxxxxxx');

define('MIDTRANS_IS_PRODUCTION', false);
define('MIDTRANS_SERVER_KEY', getenv('MIDTRANS_SERVER_KEY') ?: '');
define('MIDTRANS_CLIENT_KEY', getenv('MIDTRANS_CLIENT_KEY') ?: '');

function midtrans_is_production(): bool
{
    return (bool) MIDTRANS_IS_PRODUCTION;
}

function midtrans_server_key(): string
{
    return trim((string) MIDTRANS_SERVER_KEY);
}

function midtrans_client_key(): string
{
    return trim((string) MIDTRANS_CLIENT_KEY);
}

function midtrans_snap_api_url(): string
{
    return midtrans_is_production()
        ? 'https://app.midtrans.com/snap/v1/transactions'
        : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
}

function midtrans_snap_js_url(): string
{
    return midtrans_is_production()
        ? 'https://app.midtrans.com/snap/snap.js'
        : 'https://app.sandbox.midtrans.com/snap/snap.js';
}

function midtrans_status_api_url(string $orderId): string
{
    $base = midtrans_is_production()
        ? 'https://api.midtrans.com/v2/'
        : 'https://api.sandbox.midtrans.com/v2/';

    return $base . rawurlencode($orderId) . '/status';
}

function midtrans_config_is_ready(bool $needClientKey = false): bool
{
    if (midtrans_server_key() === '') {
        return false;
    }

    if ($needClientKey && midtrans_client_key() === '') {
        return false;
    }

    return true;
}

function midtrans_assert_config(bool $needClientKey = false): void
{
    if (midtrans_server_key() === '') {
        throw new RuntimeException('MIDTRANS_SERVER_KEY belum diisi di config/midtrans.php atau environment variable.');
    }

    if ($needClientKey && midtrans_client_key() === '') {
        throw new RuntimeException('MIDTRANS_CLIENT_KEY belum diisi di config/midtrans.php atau environment variable.');
    }
}

// ================================
// HTTP client Midtrans
// ================================

function midtrans_api_request(string $method, string $url, ?array $payload = null, array $extraHeaders = []): array
{
    midtrans_assert_config(false);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi PHP cURL belum aktif. Aktifkan cURL di XAMPP/Laragon/hosting.');
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode(midtrans_server_key() . ':'),
    ];

    foreach ($extraHeaders as $header) {
        $header = trim((string) $header);
        if ($header !== '') {
            $headers[] = $header;
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Payload Midtrans gagal diubah ke JSON.');
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        throw new RuntimeException('Tidak ada respons dari Midtrans. ' . ($curlError ?: ''));
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Respons Midtrans bukan JSON valid: ' . substr($raw, 0, 300));
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $decoded['error_messages'][0]
            ?? $decoded['status_message']
            ?? $decoded['message']
            ?? 'Request Midtrans gagal.';
        throw new RuntimeException('Midtrans API error HTTP ' . $httpCode . ': ' . $message);
    }

    return $decoded;
}

function midtrans_create_snap_transaction(array $params, string $idempotencyKey = ''): array
{
    $headers = [];
    $idempotencyKey = preg_replace('/[^A-Za-z0-9_\-.~]/', '-', $idempotencyKey) ?? '';
    if ($idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $response = midtrans_api_request('POST', midtrans_snap_api_url(), $params, $headers);

    if (empty($response['token'])) {
        throw new RuntimeException('Snap token tidak diterima dari Midtrans.');
    }

    return $response;
}

function midtrans_get_status(string $orderId): array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        throw new RuntimeException('Order ID Midtrans kosong.');
    }

    return midtrans_api_request('GET', midtrans_status_api_url($orderId));
}

// ================================
// Helper URL dan database
// ================================

function midtrans_public_root_url(): string
{
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    if (is_string($proto) && $proto !== '') {
        $scheme = strtolower(trim(explode(',', $proto)[0]));
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }

    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = 'http';
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $root = preg_replace('#/api/.*$#', '', $script);
    if (!is_string($root)) {
        $root = '';
    }

    return $scheme . '://' . $host . rtrim($root, '/');
}

function midtrans_finish_url(string $invoiceNo): string
{
    return midtrans_public_root_url() . '/index.html?payment=finish&invoice_no=' . rawurlencode($invoiceNo);
}

function midtrans_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function midtrans_is_snap_payment_method(array $method): bool
{
    $text = strtolower(
        (string) ($method['method_type'] ?? '') . ' ' .
        (string) ($method['code'] ?? '') . ' ' .
        (string) ($method['name'] ?? '')
    );

    return str_contains($text, 'midtrans') || str_contains($text, 'snap');
}

function midtrans_existing_snap_token(PDO $pdo, int $transactionId): ?array
{
    if (!midtrans_column_exists($pdo, 'transactions', 'midtrans_snap_token')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT midtrans_snap_token, midtrans_redirect_url
             FROM transactions
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['midtrans_snap_token'])) {
            return null;
        }

        return [
            'snap_token' => (string) $row['midtrans_snap_token'],
            'redirect_url' => (string) ($row['midtrans_redirect_url'] ?? ''),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function midtrans_store_snap_response(PDO $pdo, int $transactionId, string $orderId, array $response): void
{
    $columnValues = [
        'midtrans_order_id' => $orderId,
        'midtrans_snap_token' => $response['token'] ?? null,
        'midtrans_redirect_url' => $response['redirect_url'] ?? null,
        'midtrans_raw_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    $sets = [];
    $params = [];

    foreach ($columnValues as $column => $value) {
        if (midtrans_column_exists($pdo, 'transactions', $column)) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
    }

    if (!$sets) {
        return;
    }

    $sets[] = 'updated_at = NOW()';
    $params[] = $transactionId;

    $stmt = $pdo->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);
}

function midtrans_find_transaction_by_order_id(PDO $pdo, string $orderId): ?array
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return null;
    }

    $where = 'invoice_no = ?';
    $params = [$orderId];

    if (midtrans_column_exists($pdo, 'transactions', 'midtrans_order_id')) {
        $where = '(' . $where . ' OR midtrans_order_id = ?)';
        $params[] = $orderId;
    }

    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE ' . $where . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

// ================================
// Membuat Snap token
// ================================

function midtrans_build_snap_params(array $transaction, array $user, array $items): array
{
    $invoiceNo = (string) ($transaction['invoice_no'] ?? '');
    $grossAmount = (int) round((float) ($transaction['total_amount'] ?? 0));

    if ($invoiceNo === '' || $grossAmount <= 0) {
        throw new RuntimeException('Data transaksi tidak valid untuk Midtrans Snap.');
    }

    $customerName = clean_string($user['full_name'] ?? $user['username'] ?? 'Customer', 80);
    if ($customerName === '') {
        $customerName = 'Customer';
    }

    $params = [
        'transaction_details' => [
            'order_id' => $invoiceNo,
            'gross_amount' => $grossAmount,
        ],
        'customer_details' => [
            'first_name' => $customerName,
            'email' => clean_string($user['email'] ?? '', 120),
            'phone' => clean_string($user['phone'] ?? '', 30),
        ],
        'callbacks' => [
            'finish' => midtrans_finish_url($invoiceNo),
        ],
        'metadata' => [
            'source' => 'gugugaga_store_php',
            'transaction_id' => (int) ($transaction['id'] ?? 0),
            'invoice_no' => $invoiceNo,
        ],
    ];

    // item_details bersifat opsional. Hanya dikirim kalau total item sama persis dengan gross_amount.
    $itemDetails = [];
    $itemTotal = 0;
    foreach (array_values($items) as $index => $item) {
        $price = (int) round((float) ($item['unit_price'] ?? 0));
        $qty = (int) ($item['quantity'] ?? 1);
        if ($price <= 0 || $qty <= 0) {
            continue;
        }

        $name = clean_string(
            ((string) ($item['game_name'] ?? 'Game')) . ' - ' . ((string) ($item['product_name'] ?? 'Top Up')),
            50
        );

        $productId = (int) ($item['product_id'] ?? 0);
        $itemDetails[] = [
            'id' => 'P' . $productId . '-' . ($index + 1),
            'price' => $price,
            'quantity' => $qty,
            'name' => $name !== '' ? $name : 'Top Up Game',
        ];
        $itemTotal += $price * $qty;
    }

    if ($itemDetails && $itemTotal === $grossAmount) {
        $params['item_details'] = $itemDetails;
    }

    return $params;
}

function midtrans_create_snap_for_transaction(PDO $pdo, array $transaction, array $user, array $items, bool $reuseExistingToken = true): array
{
    midtrans_assert_config(true);

    $transactionId = (int) ($transaction['id'] ?? 0);
    $invoiceNo = (string) ($transaction['invoice_no'] ?? '');

    if ($reuseExistingToken && $transactionId > 0) {
        $existing = midtrans_existing_snap_token($pdo, $transactionId);
        if ($existing) {
            return [
                'snap_token' => $existing['snap_token'],
                'redirect_url' => $existing['redirect_url'],
                'order_id' => $invoiceNo,
                'reused' => true,
            ];
        }
    }

    $params = midtrans_build_snap_params($transaction, $user, $items);
    $response = midtrans_create_snap_transaction($params, $invoiceNo);
    midtrans_store_snap_response($pdo, $transactionId, $invoiceNo, $response);

    return [
        'snap_token' => (string) $response['token'],
        'redirect_url' => (string) ($response['redirect_url'] ?? ''),
        'order_id' => $invoiceNo,
        'reused' => false,
    ];
}

// ================================
// Validasi dan update status pembayaran
// ================================

function midtrans_signature_is_valid(array $payload): bool
{
    $required = ['order_id', 'status_code', 'gross_amount', 'signature_key'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $payload)) {
            return false;
        }
    }

    $expected = hash(
        'sha512',
        (string) $payload['order_id'] .
        (string) $payload['status_code'] .
        (string) $payload['gross_amount'] .
        midtrans_server_key()
    );

    return hash_equals($expected, (string) $payload['signature_key']);
}

function midtrans_map_local_status(array $payload): array
{
    $transactionStatus = strtolower((string) ($payload['transaction_status'] ?? ''));
    $fraudStatus = strtolower((string) ($payload['fraud_status'] ?? ''));

    if ($transactionStatus === 'capture') {
        if ($fraudStatus === 'challenge') {
            return ['waiting_payment', 'pending_confirmation', 'Pembayaran kartu perlu pengecekan/challenge.'];
        }
        return ['paid', 'paid', 'Pembayaran berhasil dicapture.'];
    }

    if ($transactionStatus === 'settlement') {
        return ['paid', 'paid', 'Pembayaran sudah settlement.'];
    }

    if ($transactionStatus === 'pending') {
        return ['waiting_payment', 'unpaid', 'Menunggu customer menyelesaikan pembayaran.'];
    }

    if ($transactionStatus === 'deny') {
        return ['failed', 'rejected', 'Pembayaran ditolak Midtrans/payment provider.'];
    }

    if ($transactionStatus === 'cancel') {
        return ['cancelled', 'unpaid', 'Pembayaran dibatalkan.'];
    }

    if ($transactionStatus === 'expire') {
        return ['expired', 'unpaid', 'Waktu pembayaran kedaluwarsa.'];
    }

    if (in_array($transactionStatus, ['refund', 'partial_refund', 'chargeback', 'partial_chargeback'], true)) {
        return ['refunded', 'refunded', 'Pembayaran direfund/chargeback.'];
    }

    return ['waiting_payment', 'unpaid', 'Status Midtrans belum dikenali: ' . ($transactionStatus ?: '-') . '.'];
}

function midtrans_payload_datetime(array $payload): ?string
{
    foreach (['settlement_time', 'transaction_time'] as $key) {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }
    }

    return null;
}

function midtrans_safe_status_log(PDO $pdo, int $transactionId, ?string $oldStatus, string $newStatus, ?int $changedBy, string $note): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO transaction_status_logs (transaction_id, old_status, new_status, changed_by, note)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$transactionId, $oldStatus, $newStatus, $changedBy, $note]);
    } catch (Throwable $e) {
        // Log tidak boleh menggagalkan update status pembayaran.
    }
}

function midtrans_safe_notification(PDO $pdo, ?int $userId, string $title, string $message): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, title, message, notification_type)
             VALUES (?, ?, ?, "transaction")'
        );
        $stmt->execute([$userId, $title, $message]);
    } catch (Throwable $e) {
        // Notifikasi tidak boleh menggagalkan update status pembayaran.
    }
}

function midtrans_apply_payment_status(PDO $pdo, array $transaction, array $payload, string $source = 'Midtrans'): array
{
    [$mappedStatus, $mappedPaymentStatus, $mappedNote] = midtrans_map_local_status($payload);

    $transactionId = (int) $transaction['id'];
    $invoiceNo = (string) $transaction['invoice_no'];
    $oldStatus = (string) $transaction['status'];
    $oldPaymentStatus = (string) $transaction['payment_status'];

    // Jangan downgrade invoice yang sudah paid hanya karena callback pending datang terlambat.
    $newStatus = $mappedStatus;
    $newPaymentStatus = $mappedPaymentStatus;
    if ($oldPaymentStatus === 'paid' && !in_array($mappedPaymentStatus, ['paid', 'refunded'], true)) {
        $newStatus = $oldStatus;
        $newPaymentStatus = $oldPaymentStatus;
    }

    $sets = [
        'status = ?',
        'payment_status = ?',
        'paid_at = CASE WHEN ? = "paid" AND paid_at IS NULL THEN ? ELSE paid_at END',
        'updated_at = NOW()',
    ];
    $params = [
        $newStatus,
        $newPaymentStatus,
        $newPaymentStatus,
        $newPaymentStatus === 'paid' ? (midtrans_payload_datetime($payload) ?? date('Y-m-d H:i:s')) : null,
    ];

    $optionalColumns = [
        'midtrans_order_id' => $payload['order_id'] ?? null,
        'midtrans_transaction_id' => $payload['transaction_id'] ?? null,
        'midtrans_payment_type' => $payload['payment_type'] ?? null,
        'midtrans_transaction_status' => $payload['transaction_status'] ?? null,
        'midtrans_fraud_status' => $payload['fraud_status'] ?? null,
        'midtrans_last_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    foreach ($optionalColumns as $column => $value) {
        if (midtrans_column_exists($pdo, 'transactions', $column)) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
    }

    $params[] = $transactionId;
    $stmt = $pdo->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);

    if ($oldStatus !== $newStatus || $oldPaymentStatus !== $newPaymentStatus) {
        midtrans_safe_status_log(
            $pdo,
            $transactionId,
            $oldStatus,
            $newStatus,
            null,
            $source . ': payment ' . $oldPaymentStatus . ' -> ' . $newPaymentStatus . '. ' . $mappedNote
        );

        if ($newPaymentStatus === 'paid') {
            midtrans_safe_notification($pdo, (int) $transaction['user_id'], 'Pembayaran berhasil', 'Pembayaran invoice ' . $invoiceNo . ' berhasil diterima. Pesanan akan diproses.');
        } elseif (in_array($newStatus, ['failed', 'cancelled', 'expired'], true)) {
            midtrans_safe_notification($pdo, (int) $transaction['user_id'], 'Pembayaran belum berhasil', 'Invoice ' . $invoiceNo . ' berstatus ' . $newStatus . '. Silakan cek kembali pembayaran Anda.');
        }
    }

    return [
        'invoice_no' => $invoiceNo,
        'old_status' => $oldStatus,
        'old_payment_status' => $oldPaymentStatus,
        'status' => $newStatus,
        'payment_status' => $newPaymentStatus,
        'midtrans_transaction_status' => (string) ($payload['transaction_status'] ?? ''),
        'midtrans_payment_type' => (string) ($payload['payment_type'] ?? ''),
        'note' => $mappedNote,
    ];
}
