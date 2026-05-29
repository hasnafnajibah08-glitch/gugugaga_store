<?php
// sql/apply_midtrans_snap_integration.php
// Migration aman untuk menambahkan kolom Midtrans dan metode pembayaran Snap.
// Bisa dijalankan via CLI: php sql/apply_midtrans_snap_integration.php
// Atau via browser lokal: http://localhost/NAMA_FOLDER/sql/apply_midtrans_snap_integration.php

require_once __DIR__ . '/../config/bootstrap.php';

$pdo = get_pdo();

function mig_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function mig_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

$columns = [
    'midtrans_order_id' => 'VARCHAR(80) NULL AFTER invoice_no',
    'midtrans_transaction_id' => 'VARCHAR(120) NULL AFTER midtrans_order_id',
    'midtrans_payment_type' => 'VARCHAR(80) NULL AFTER midtrans_transaction_id',
    'midtrans_transaction_status' => 'VARCHAR(80) NULL AFTER midtrans_payment_type',
    'midtrans_fraud_status' => 'VARCHAR(80) NULL AFTER midtrans_transaction_status',
    'midtrans_snap_token' => 'VARCHAR(255) NULL AFTER midtrans_fraud_status',
    'midtrans_redirect_url' => 'TEXT NULL AFTER midtrans_snap_token',
    'midtrans_raw_response' => 'LONGTEXT NULL AFTER midtrans_redirect_url',
    'midtrans_last_payload' => 'LONGTEXT NULL AFTER midtrans_raw_response',
];

$messages = [];

foreach ($columns as $column => $definition) {
    if (!mig_column_exists($pdo, 'transactions', $column)) {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN ' . $column . ' ' . $definition);
        $messages[] = 'Tambah kolom transactions.' . $column;
    } else {
        $messages[] = 'Kolom transactions.' . $column . ' sudah ada';
    }
}

$indexes = [
    'idx_transactions_midtrans_order_id' => 'midtrans_order_id',
    'idx_transactions_midtrans_transaction_id' => 'midtrans_transaction_id',
];

foreach ($indexes as $index => $column) {
    if (!mig_index_exists($pdo, 'transactions', $index)) {
        $pdo->exec('CREATE INDEX ' . $index . ' ON transactions (' . $column . ')');
        $messages[] = 'Tambah index ' . $index;
    } else {
        $messages[] = 'Index ' . $index . ' sudah ada';
    }
}

$stmt = $pdo->prepare('SELECT id FROM payment_methods WHERE code = ? LIMIT 1');
$stmt->execute(['MIDTRANS_SNAP']);
$paymentMethodId = $stmt->fetchColumn();

if ($paymentMethodId) {
    $update = $pdo->prepare(
        'UPDATE payment_methods
         SET name = ?, method_type = ?, instructions = ?, is_active = 1, sort_order = 1
         WHERE id = ?'
    );
    $update->execute([
        'Midtrans Snap Otomatis',
        'midtrans',
        'Bayar otomatis via Midtrans Snap. Status pembayaran diperbarui otomatis oleh webhook.',
        (int) $paymentMethodId,
    ]);
    $messages[] = 'Payment method MIDTRANS_SNAP diperbarui';
} else {
    $insert = $pdo->prepare(
        'INSERT INTO payment_methods
         (code, name, method_type, logo_url, qris_image_url, instructions, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
    );
    $insert->execute([
        'MIDTRANS_SNAP',
        'Midtrans Snap Otomatis',
        'midtrans',
        '',
        '',
        'Bayar otomatis via Midtrans Snap. Status pembayaran diperbarui otomatis oleh webhook.',
    ]);
    $messages[] = 'Payment method MIDTRANS_SNAP ditambahkan';
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "Migration Midtrans Snap selesai.\n\n" . implode("\n", $messages) . "\n";
