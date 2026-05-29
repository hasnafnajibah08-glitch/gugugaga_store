<?php
// api/user/upload_payment_confirmation.php
// Customer upload bukti pembayaran manual.
require_once __DIR__ . '/../../config/bootstrap.php';
require_method('POST');

$pdo = get_pdo();
$user = require_customer($pdo);

$invoiceNo = clean_string($_POST['invoice_no'] ?? '', 40);
$paymentMethodId = (int) ($_POST['payment_method_id'] ?? 0);
$bankAccountId = (int) ($_POST['bank_account_id'] ?? 0);
$senderBank = clean_string($_POST['sender_bank'] ?? '', 80);
$senderAccountName = clean_string($_POST['sender_account_name'] ?? '', 150);
$senderAccountNumber = clean_string($_POST['sender_account_number'] ?? '', 80);
$amountPaid = (float) ($_POST['amount_paid'] ?? 0);
$note = clean_string($_POST['note'] ?? '', 1000);

if ($invoiceNo === '') {
    fail('Invoice wajib diisi.', 422);
}

if ($paymentMethodId <= 0) {
    fail('Metode pembayaran wajib dipilih.', 422);
}

if ($amountPaid <= 0) {
    fail('Nominal pembayaran wajib diisi.', 422);
}

$stmt = $pdo->prepare(
    'SELECT id, invoice_no, user_id, total_amount, status, payment_status
     FROM transactions
     WHERE invoice_no = ? AND user_id = ?
     LIMIT 1'
);
$stmt->execute([$invoiceNo, (int) $user['id']]);
$transaction = $stmt->fetch();

if (!$transaction) {
    fail('Transaksi tidak ditemukan.', 404);
}

if (in_array($transaction['payment_status'], ['paid', 'refunded'], true)) {
    fail('Transaksi ini tidak bisa dikirim bukti pembayaran lagi.', 422);
}

$methodStmt = $pdo->prepare(
    'SELECT id, method_type, name
     FROM payment_methods
     WHERE id = ? AND is_active = 1
     LIMIT 1'
);
$methodStmt->execute([$paymentMethodId]);
$method = $methodStmt->fetch();

if (!$method) {
    fail('Metode pembayaran tidak tersedia.', 404);
}

$bankAccountValue = null;
if ($bankAccountId > 0) {
    $bankStmt = $pdo->prepare(
        'SELECT id FROM bank_accounts WHERE id = ? AND payment_method_id = ? AND is_active = 1 LIMIT 1'
    );
    $bankStmt->execute([$bankAccountId, $paymentMethodId]);
    if (!$bankStmt->fetch()) {
        fail('Rekening tujuan tidak valid.', 422);
    }
    $bankAccountValue = $bankAccountId;
}

if (empty($_FILES['proof_file']) || !is_array($_FILES['proof_file'])) {
    fail('Bukti pembayaran wajib diupload.', 422);
}

$file = $_FILES['proof_file'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail('Upload bukti pembayaran gagal.', 422);
}

if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
    fail('Ukuran bukti pembayaran maksimal 5MB.', 422);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

if (!isset($allowedMimes[$mime])) {
    fail('Format bukti pembayaran harus JPG, PNG, atau WEBP.', 422);
}

$uploadDir = __DIR__ . '/../../uploads/payment_proofs';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'proof_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMimes[$mime];
$targetPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    fail('Gagal menyimpan file bukti pembayaran.', 500);
}

$publicPath = 'uploads/payment_proofs/' . $filename;

$pdo->beginTransaction();

try {
    $confirmStmt = $pdo->prepare(
        'INSERT INTO payment_confirmations
         (transaction_id, payment_method_id, bank_account_id, sender_bank, sender_account_name,
          sender_account_number, amount_paid, proof_file_path, proof_file_mime, status, note, paid_at)
         VALUES (?, ?, ?, NULLIF(?, ""), NULLIF(?, ""), NULLIF(?, ""), ?, ?, ?, "submitted", NULLIF(?, ""), NOW())'
    );
    $confirmStmt->execute([
        (int) $transaction['id'],
        $paymentMethodId,
        $bankAccountValue,
        $senderBank,
        $senderAccountName,
        $senderAccountNumber,
        $amountPaid,
        $publicPath,
        $mime,
        $note,
    ]);

    $oldStatus = $transaction['status'];
    $newStatus = 'waiting_payment';

    $updateStmt = $pdo->prepare(
        'UPDATE transactions
         SET payment_method_id = ?, payment_status = "pending_confirmation", status = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $updateStmt->execute([
        $paymentMethodId,
        $newStatus,
        (int) $transaction['id'],
    ]);

    $logStmt = $pdo->prepare(
        'INSERT INTO transaction_status_logs (transaction_id, old_status, new_status, changed_by, note)
         VALUES (?, ?, ?, ?, ?)'
    );
    $logStmt->execute([
        (int) $transaction['id'],
        $oldStatus,
        $newStatus,
        (int) $user['id'],
        'Customer upload bukti pembayaran. Menunggu approve admin.',
    ]);

    $notifStmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, title, message, notification_type)
         VALUES (NULL, ?, ?, "transaction")'
    );
    $notifStmt->execute([
        'Bukti pembayaran baru',
        'Invoice ' . $invoiceNo . ' menunggu approve pembayaran manual.',
    ]);

    audit_log($pdo, (int) $user['id'], 'upload_payment_confirmation', 'transactions', (int) $transaction['id'], [
        'invoice_no' => $invoiceNo,
        'amount_paid' => $amountPaid,
        'payment_method_id' => $paymentMethodId,
    ]);

    $pdo->commit();

    ok(null, 'Bukti pembayaran berhasil dikirim. Menunggu approve admin.');
} catch (Throwable $e) {
    $pdo->rollBack();
    if (is_file($targetPath)) {
        unlink($targetPath);
    }
    fail(APP_DEBUG ? 'Gagal menyimpan konfirmasi: ' . $e->getMessage() : 'Gagal menyimpan konfirmasi pembayaran.', 500);
}
