<?php
// api/public/midtrans_config.php
// Mengekspos Client Key dan URL Snap.js untuk frontend. Server Key tidak pernah dikirim ke browser.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/midtrans.php';
require_method('GET');

ok([
    'enabled' => midtrans_client_key() !== '',
    'is_production' => midtrans_is_production(),
    'client_key' => midtrans_client_key(),
    'snap_js_url' => midtrans_snap_js_url(),
], 'Konfigurasi Midtrans frontend berhasil dimuat.');
