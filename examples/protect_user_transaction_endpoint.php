<?php
// examples/protect_user_transaction_endpoint.php
// Contoh penerapan pada endpoint yang membuat transaksi/top up user.
// Jangan dipakai sebagai endpoint langsung; salin guard-nya ke endpoint transaksi kamu.

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/auth.php';
require_method('POST');

$pdo = get_pdo();
$user = require_customer($pdo); // Hanya role customer yang boleh membuat transaksi/top up.

// Lanjutkan proses transaksi/top up milik user di bawah ini.
// Contoh:
// $userId = (int) $user['id'];
