<?php
// api/user/generate_qris.php
// v12: QRIS lebih tahan error.
// - Kalau payload QRIS dinamis valid, sistem membuat QRIS sesuai nominal invoice.
// - Kalau payload kosong/invalid, sistem fallback ke gambar QRIS statis dari payment_methods.qris_image_url.
// - Tidak langsung error hanya karena payload setting salah.

require_once __DIR__ . '/../../config/bootstrap.php';
require_method('GET');

$pdo = get_pdo();
$user = require_customer($pdo);

$invoiceNo = clean_string($_GET['invoice_no'] ?? '', 80);

if ($invoiceNo === '') {
    fail('Nomor invoice wajib diisi.', 422);
}

$stmt = $pdo->prepare(
    'SELECT
        t.id,
        t.invoice_no,
        t.total_amount,
        t.status,
        t.payment_status,
        pm.id AS payment_method_id,
        pm.code AS payment_method_code,
        pm.name AS payment_method_name,
        pm.method_type,
        pm.qris_image_url,
        pm.logo_url
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

$isQris = gg_contains((string) $transaction['method_type'], 'qris')
    || gg_contains((string) $transaction['payment_method_code'], 'qris')
    || gg_contains((string) $transaction['payment_method_name'], 'qris');

if (!$isQris) {
    fail('Metode pembayaran invoice ini bukan QRIS.', 422);
}

$amount = (float) $transaction['total_amount'];
$warnings = [];

// 1) Coba payload QRIS dari site_settings terlebih dahulu.
$staticPayload = qris_setting($pdo, [
    'qris_static_payload',
    'qris_payload',
    'qris_merchant_payload',
]);

// 2) Kalau site_settings kosong, coba payload yang disimpan di payment_methods.qris_payload.
if ($staticPayload === '') {
    $staticPayload = qris_method_payload($pdo, (int) $transaction['payment_method_id']);
}

// 3) Kalau ada payload, coba buat QRIS dinamis. Kalau gagal, jangan hentikan proses; fallback ke gambar statis.
if ($staticPayload !== '') {
    try {
        $payload = qris_make_dynamic_payload($staticPayload, $amount);

        ok([
            'qris' => [
                'invoice_no' => $transaction['invoice_no'],
                'total_amount' => $amount,
                'payment_method' => $transaction['payment_method_name'],
                'payload' => $payload,
                'image_url' => null,
                'is_dynamic' => true,
                'mode' => 'dynamic_payload',
            ],
        ], 'QRIS dinamis berhasil dibuat.');
    } catch (Throwable $e) {
        $warnings[] = APP_DEBUG ? $e->getMessage() : 'Payload QRIS dinamis belum valid.';
    }
}

// 4) Fallback utama: tampilkan QRIS statis dari qris_image_url.
$imageUrl = first_available_qris_image($pdo, $transaction);

if ($imageUrl !== '') {
    ok([
        'qris' => [
            'invoice_no' => $transaction['invoice_no'],
            'total_amount' => $amount,
            'payment_method' => $transaction['payment_method_name'],
            'payload' => null,
            'image_url' => $imageUrl,
            'is_dynamic' => false,
            'mode' => 'static_image',
            'warnings' => $warnings,
        ],
    ], 'QRIS statis ditampilkan.');
}

fail(
    'QRIS belum dikonfigurasi. Upload gambar QRIS lalu isi payment_methods.qris_image_url, atau isi payload QRIS asli di site_settings qris_static_payload.',
    422,
    null,
    [
        'invoice_no' => $transaction['invoice_no'],
        'warnings' => $warnings,
    ]
);

function gg_contains(string $haystack, string $needle): bool
{
    return stripos($haystack, $needle) !== false;
}

function qris_setting(PDO $pdo, array $keys): string
{
    if (!$keys || !table_exists($pdo, 'site_settings')) {
        return '';
    }

    try {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare(
            'SELECT setting_key, setting_value
             FROM site_settings
             WHERE setting_key IN (' . $placeholders . ')'
        );
        $stmt->execute($keys);

        $values = [];
        foreach ($stmt->fetchAll() as $row) {
            $values[(string) $row['setting_key']] = normalize_payload((string) ($row['setting_value'] ?? ''));
        }

        foreach ($keys as $key) {
            if (!empty($values[$key])) {
                return $values[$key];
            }
        }
    } catch (Throwable $e) {
        return '';
    }

    return '';
}

function qris_method_payload(PDO $pdo, int $paymentMethodId): string
{
    if (!column_exists($pdo, 'payment_methods', 'qris_payload')) {
        return '';
    }

    try {
        $stmt = $pdo->prepare('SELECT qris_payload FROM payment_methods WHERE id = ? LIMIT 1');
        $stmt->execute([$paymentMethodId]);
        $row = $stmt->fetch();

        return normalize_payload((string) ($row['qris_payload'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function normalize_payload(string $value): string
{
    $value = trim($value);

    // Kalau user tidak sengaja mengisi path gambar di kolom payload, anggap tidak ada payload.
    if ($value === '' || preg_match('/\.(png|jpe?g|webp|gif)$/i', $value) === 1 || str_starts_with_php7($value, '/')) {
        return '';
    }

    return preg_replace('/\s+/', '', $value) ?? '';
}

function first_available_qris_image(PDO $pdo, array $transaction): string
{
    $candidates = [];

    $url = trim((string) ($transaction['qris_image_url'] ?? ''));
    if ($url !== '') {
        $candidates[] = $url;
    }

    foreach (['qris_image_url', 'qris_static_image', 'qris_static_image_url', 'qris_logo_url'] as $key) {
        $fromSetting = setting_value($pdo, $key);
        if ($fromSetting !== '') {
            $candidates[] = $fromSetting;
        }
    }

    // Nama file umum. Kalau user meletakkan qris.png di root project, langsung terbaca tanpa ubah database.
    $candidates = array_merge($candidates, [
        '/qris.png',
        '/qris.jpg',
        '/qris.jpeg',
        '/QRIS.png',
        '/QRIS.jpg',
        '/QRIS.jpeg',
        '/e-wallet.jpeg',
        '/e-wallet.jpg',
        '/e-wallet.png',
    ]);

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        if (isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;

        $url = public_asset_url($candidate);

        // URL eksternal tidak bisa dicek file_exists dari server, jadi langsung pakai.
        if (preg_match('/^(https?:|data:|blob:)/i', $url) === 1) {
            return $url;
        }

        $path = project_public_path($url);
        if ($path !== '' && is_file($path)) {
            return $url;
        }
    }

    // Tetap kembalikan qris_image_url kalau ada, walaupun file_exists gagal karena project berada di subfolder/hosting tertentu.
    $configured = trim((string) ($transaction['qris_image_url'] ?? ''));
    if ($configured !== '') {
        return public_asset_url($configured);
    }

    return '';
}

function setting_value(PDO $pdo, string $key): string
{
    if (!table_exists($pdo, 'site_settings')) {
        return '';
    }

    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool
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

function qris_make_dynamic_payload(string $staticPayload, float $amount): string
{
    $base = normalize_payload($staticPayload);

    if ($base === '') {
        throw new RuntimeException('Payload QRIS statis kosong.');
    }

    if (!str_starts_with_php7($base, '000201')) {
        throw new RuntimeException('Payload QRIS harus diawali 000201.');
    }

    $base = qris_remove_crc($base);
    $items = qris_parse_tlv($base);

    $items = qris_set_tag($items, '01', '12', '00'); // 12 = dynamic QR
    $items = qris_remove_tag($items, '54');
    $items = qris_insert_amount($items, qris_amount_value($amount));

    $withoutCrc = qris_build_tlv($items);
    $forCrc = $withoutCrc . '6304';

    return $forCrc . qris_crc16($forCrc);
}

function qris_amount_value(float $amount): string
{
    if ($amount <= 0) {
        throw new RuntimeException('Nominal QRIS tidak valid.');
    }

    return number_format($amount, 0, '.', '');
}

function qris_remove_crc(string $payload): string
{
    if (preg_match('/6304[0-9A-F]{4}$/i', $payload) === 1) {
        return substr($payload, 0, -8);
    }

    $pos = strripos($payload, '6304');
    if ($pos !== false && $pos >= strlen($payload) - 12) {
        return substr($payload, 0, $pos);
    }

    return $payload;
}

function qris_parse_tlv(string $payload): array
{
    $items = [];
    $i = 0;
    $length = strlen($payload);

    while ($i < $length) {
        if ($i + 4 > $length) {
            throw new RuntimeException('Format TLV QRIS tidak lengkap.');
        }

        $tag = substr($payload, $i, 2);
        $lenRaw = substr($payload, $i + 2, 2);

        if (!ctype_digit($tag) || !ctype_digit($lenRaw)) {
            throw new RuntimeException('Format tag/length QRIS tidak valid.');
        }

        $valueLength = (int) $lenRaw;
        $valueStart = $i + 4;
        $value = substr($payload, $valueStart, $valueLength);

        if (strlen($value) !== $valueLength) {
            throw new RuntimeException('Panjang value QRIS tidak sesuai.');
        }

        $items[] = [
            'tag' => $tag,
            'value' => $value,
        ];

        $i = $valueStart + $valueLength;
    }

    return $items;
}

function qris_build_tlv(array $items): string
{
    $payload = '';

    foreach ($items as $item) {
        $tag = (string) ($item['tag'] ?? '');
        $value = (string) ($item['value'] ?? '');
        $valueLength = strlen($value);

        if (!preg_match('/^\d{2}$/', $tag)) {
            throw new RuntimeException('Tag QRIS tidak valid.');
        }

        if ($valueLength > 99) {
            throw new RuntimeException('Value QRIS terlalu panjang untuk tag ' . $tag . '.');
        }

        $payload .= $tag . str_pad((string) $valueLength, 2, '0', STR_PAD_LEFT) . $value;
    }

    return $payload;
}

function qris_set_tag(array $items, string $tag, string $value, string $insertAfterTag = ''): array
{
    $found = false;

    foreach ($items as &$item) {
        if (($item['tag'] ?? '') === $tag) {
            $item['value'] = $value;
            $found = true;
            break;
        }
    }
    unset($item);

    if ($found) {
        return $items;
    }

    $new = [];
    $inserted = false;

    foreach ($items as $item) {
        $new[] = $item;
        if (!$inserted && $insertAfterTag !== '' && ($item['tag'] ?? '') === $insertAfterTag) {
            $new[] = ['tag' => $tag, 'value' => $value];
            $inserted = true;
        }
    }

    if (!$inserted) {
        array_unshift($new, ['tag' => $tag, 'value' => $value]);
    }

    return $new;
}

function qris_remove_tag(array $items, string $tag): array
{
    return array_values(array_filter($items, static fn (array $item): bool => ($item['tag'] ?? '') !== $tag));
}

function qris_insert_amount(array $items, string $amount): array
{
    $result = [];
    $inserted = false;

    foreach ($items as $item) {
        $result[] = $item;

        if (!$inserted && ($item['tag'] ?? '') === '53') {
            $result[] = ['tag' => '54', 'value' => $amount];
            $inserted = true;
        }
    }

    if ($inserted) {
        return $result;
    }

    $preferredBefore = ['58', '59', '60', '61', '62'];
    $result = [];

    foreach ($items as $item) {
        if (!$inserted && in_array((string) ($item['tag'] ?? ''), $preferredBefore, true)) {
            $result[] = ['tag' => '54', 'value' => $amount];
            $inserted = true;
        }
        $result[] = $item;
    }

    if (!$inserted) {
        $result[] = ['tag' => '54', 'value' => $amount];
    }

    return $result;
}

function qris_crc16(string $payload): string
{
    $crc = 0xFFFF;
    $length = strlen($payload);

    for ($i = 0; $i < $length; $i++) {
        $crc ^= ord($payload[$i]) << 8;

        for ($bit = 0; $bit < 8; $bit++) {
            if (($crc & 0x8000) !== 0) {
                $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFF;
            }
        }
    }

    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

function public_asset_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('/^(https?:|data:|blob:)/i', $url) === 1) {
        return $url;
    }

    return ltrim($url, '/');
}

function project_public_path(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || trim($path) === '') {
        return '';
    }

    $relative = ltrim($path, '/');

    // Struktur project: api/user/generate_qris.php -> root project = ../../
    return realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function str_starts_with_php7(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) === 0;
}
