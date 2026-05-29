<?php
// api/admin/update_transaction.php
// Update status transaksi + approve/reject pembayaran manual.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_method('POST');

$pdo = get_pdo();
$admin = require_admin($pdo);
$data = request_data();

$transactionId = (int) ($data['transaction_id'] ?? 0);
$action = clean_string($data['action'] ?? 'update', 50);
$status = clean_string($data['status'] ?? '', 50);
$paymentStatus = clean_string($data['payment_status'] ?? '', 50);
$confirmationStatus = clean_string($data['confirmation_status'] ?? '', 50);
$adminNote = clean_string($data['admin_note'] ?? '', 1000);

$allowedStatuses = [
    'pending',
    'waiting_payment',
    'paid',
    'processing',
    'success',
    'failed',
    'cancelled',
    'expired',
    'refunded',
];

$allowedPaymentStatuses = [
    'unpaid',
    'pending_confirmation',
    'paid',
    'rejected',
    'refunded',
];

$allowedConfirmationStatuses = [
    '',
    'submitted',
    'approved',
    'rejected',
];

if ($transactionId <= 0) {
    fail('ID transaksi tidak valid.', 422);
}

$stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? LIMIT 1');
$stmt->execute([$transactionId]);
$transaction = $stmt->fetch();

if (!$transaction) {
    fail('Transaksi tidak ditemukan.', 404);
}

if ($action === 'approve_payment') {
    $status = $status !== '' ? $status : 'paid';
    $paymentStatus = 'paid';
    $confirmationStatus = 'approved';
    $adminNote = $adminNote !== '' ? $adminNote : 'Pembayaran manual disetujui admin.';
}

if ($action === 'reject_payment') {
    $status = $status !== '' ? $status : 'failed';
    $paymentStatus = 'rejected';
    $confirmationStatus = 'rejected';
    $adminNote = $adminNote !== '' ? $adminNote : 'Pembayaran manual ditolak admin.';
}

if ($status === '') {
    $status = $transaction['status'];
}

if ($paymentStatus === '') {
    $paymentStatus = $transaction['payment_status'];
}

if (!in_array($status, $allowedStatuses, true)) {
    fail('Status transaksi tidak valid.', 422);
}

if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
    fail('Status pembayaran tidak valid.', 422);
}

if (!in_array($confirmationStatus, $allowedConfirmationStatuses, true)) {
    fail('Status konfirmasi pembayaran tidak valid.', 422);
}

$oldStatus = (string) $transaction['status'];
$oldPaymentStatus = (string) $transaction['payment_status'];
$userId = (int) $transaction['user_id'];

$pdo->beginTransaction();

try {
    $update = $pdo->prepare(
        'UPDATE transactions
         SET status = ?,
             payment_status = ?,
             admin_note = NULLIF(?, ""),
             paid_at = CASE WHEN ? = "paid" AND paid_at IS NULL THEN NOW() ELSE paid_at END,
             updated_at = NOW()
         WHERE id = ?'
    );

    $update->execute([
        $status,
        $paymentStatus,
        $adminNote,
        $paymentStatus,
        $transactionId,
    ]);

    if ($confirmationStatus !== '') {
        $pcStmt = $pdo->prepare(
            'SELECT id
             FROM payment_confirmations
             WHERE transaction_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $pcStmt->execute([$transactionId]);
        $confirmation = $pcStmt->fetch();

        if ($confirmation) {
            $pcUpdate = $pdo->prepare(
                'UPDATE payment_confirmations
                 SET status = ?,
                     confirmed_by = ?,
                     note = NULLIF(?, ""),
                     paid_at = CASE WHEN ? = "approved" AND paid_at IS NULL THEN NOW() ELSE paid_at END,
                     updated_at = NOW()
                 WHERE id = ?'
            );

            $pcUpdate->execute([
                $confirmationStatus,
                (int) $admin['id'],
                $adminNote,
                $confirmationStatus,
                (int) $confirmation['id'],
            ]);
        }
    }

    if ($oldStatus !== $status || $oldPaymentStatus !== $paymentStatus) {
        $log = $pdo->prepare(
            'INSERT INTO transaction_status_logs (transaction_id, old_status, new_status, changed_by, note)
             VALUES (?, ?, ?, ?, ?)'
        );
        $log->execute([
            $transactionId,
            $oldStatus,
            $status,
            (int) $admin['id'],
            'Payment: ' . $oldPaymentStatus . ' -> ' . $paymentStatus . ($adminNote !== '' ? ' | ' . $adminNote : ''),
        ]);
    }

    if ($paymentStatus === 'paid') {
        $notifTitle = 'Pembayaran disetujui';
        $notifMessage = 'Pembayaran untuk invoice ' . $transaction['invoice_no'] . ' sudah disetujui. Transaksi akan diproses.';
    } elseif ($paymentStatus === 'rejected') {
        $notifTitle = 'Pembayaran ditolak';
        $notifMessage = 'Pembayaran untuk invoice ' . $transaction['invoice_no'] . ' ditolak. Silakan cek kembali bukti pembayaran Anda.';
    } else {
        $notifTitle = 'Status transaksi diperbarui';
        $notifMessage = 'Status invoice ' . $transaction['invoice_no'] . ' diperbarui menjadi ' . $status . '.';
    }

    $notif = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, notification_type)
         VALUES (?, ?, ?, "transaction")'
    );
    $notif->execute([$userId, $notifTitle, $notifMessage]);

    audit_log(
        $pdo,
        (int) $admin['id'],
        $action === '' ? 'update_transaction' : $action,
        'transactions',
        $transactionId
    );

    $pdo->commit();

    ok(null, 'Transaksi berhasil diperbarui.');
} catch (Throwable $e) {
    $pdo->rollBack();
    fail('Gagal memperbarui transaksi.', 500);
}
