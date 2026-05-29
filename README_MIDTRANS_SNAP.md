# Integrasi Midtrans Snap untuk GUGUGAGA.STORE PHP

Patch ini menambahkan flow pembayaran otomatis via Midtrans Snap tanpa menghapus metode pembayaran manual yang sudah ada.

## File yang ditambahkan/diubah

### File baru

- `config/midtrans.php` — konfigurasi Server Key, Client Key, endpoint Snap, helper request Midtrans, helper webhook.
- `api/public/midtrans_config.php` — mengirim Client Key dan URL Snap.js ke frontend.
- `api/user/midtrans_snap_token.php` — membuat/mengambil Snap token untuk invoice user.
- `api/user/midtrans_status.php` — mengecek status invoice ke Midtrans Get Status API.
- `api/midtrans/notification.php` — webhook Midtrans untuk update status transaksi otomatis.
- `sql/midtrans_snap_integration.sql` — migrasi SQL manual.
- `sql/apply_midtrans_snap_integration.php` — migrasi aman via PHP.

### File yang diubah

- `api/user/checkout.php` — jika metode pembayaran adalah Midtrans/Snap, backend langsung membuat Snap token setelah invoice dibuat.
- `api/user/transactions.php` — mengirim data Snap token/status Midtrans ke frontend jika kolom migrasi tersedia.
- `assets/app.js` — load Snap.js, buka popup `snap.pay()`, sinkron status, dan tambah tombol “Bayar via Midtrans” di riwayat transaksi.
- `index.html` — menambahkan modal QRIS yang sebelumnya dipanggil JS tetapi belum ada di HTML.

## Cara pasang

1. Backup project lama.
2. Copy file patch ke root project dan replace file lama yang namanya sama.
3. Jalankan migrasi:

   ```bash
   php sql/apply_midtrans_snap_integration.php
   ```

   Atau buka lewat browser lokal:

   ```text
   http://localhost/NAMA_FOLDER/sql/apply_midtrans_snap_integration.php
   ```

4. Isi key Midtrans di `config/midtrans.php`.

   Untuk sandbox:

   ```php
   define('MIDTRANS_IS_PRODUCTION', false);
   define('MIDTRANS_SERVER_KEY', getenv('MIDTRANS_SERVER_KEY') ?: 'SB-Mid-server-ISI_SERVER_KEY_SANDBOX');
   define('MIDTRANS_CLIENT_KEY', getenv('MIDTRANS_CLIENT_KEY') ?: 'SB-Mid-client-ISI_CLIENT_KEY_SANDBOX');
   ```

   Untuk production, ubah `MIDTRANS_IS_PRODUCTION` menjadi `true` dan gunakan key production.

5. Pastikan metode pembayaran `MIDTRANS_SNAP` aktif di tabel `payment_methods`. Migration PHP otomatis menambahkan/mengaktifkannya.

6. Di dashboard Midtrans, isi Payment Notification URL:

   ```text
   https://domain-kamu.com/NAMA_FOLDER/api/midtrans/notification.php
   ```

   Jika project ada langsung di root domain:

   ```text
   https://domain-kamu.com/api/midtrans/notification.php
   ```

   Webhook Midtrans harus bisa diakses dari internet. `localhost` tidak bisa menerima webhook langsung dari Midtrans.

7. Untuk testing lokal webhook, gunakan tunnel seperti ngrok/Cloudflare Tunnel, lalu pasang URL tunnel di dashboard Midtrans.

## Alur setelah patch

1. User pilih game dan produk.
2. User checkout dan memilih `Midtrans Snap Otomatis`.
3. `api/user/checkout.php` membuat cart, transaction, transaction_items, lalu request Snap token ke Midtrans.
4. Frontend menerima `snap_token`.
5. Frontend load `snap.js` menggunakan Client Key.
6. Frontend menjalankan `window.snap.pay(snap_token)`.
7. User memilih channel pembayaran di popup Snap, misalnya QRIS/e-wallet/VA.
8. Midtrans mengirim webhook ke `api/midtrans/notification.php` saat status berubah.
9. Webhook memvalidasi `signature_key`, mencari invoice berdasarkan `order_id`, lalu update `transactions.payment_status` dan `transactions.status`.
10. Riwayat transaksi user otomatis berubah dari `unpaid/waiting_payment` menjadi `paid/paid` setelah pembayaran berhasil.
11. Admin bisa lanjut memproses top up sampai `success` dari dashboard admin yang sudah ada.

## Mapping status

| Status Midtrans | Status lokal | Payment status lokal |
|---|---|---|
| `capture` dengan fraud accept | `paid` | `paid` |
| `settlement` | `paid` | `paid` |
| `pending` | `waiting_payment` | `unpaid` |
| `deny` | `failed` | `rejected` |
| `cancel` | `cancelled` | `unpaid` |
| `expire` | `expired` | `unpaid` |
| `refund` / `partial_refund` / `chargeback` | `refunded` | `refunded` |

## Catatan keamanan

- Jangan pernah expose Server Key ke JavaScript/browser.
- Yang boleh dikirim ke browser hanya Client Key dan Snap token.
- Webhook memvalidasi `signature_key` dengan rumus Midtrans: `SHA512(order_id + status_code + gross_amount + ServerKey)`.
- Jangan mengandalkan callback frontend `onSuccess` saja. Patch ini tetap mengecek status ke backend, dan sumber utama update status adalah webhook Midtrans.

## Troubleshooting cepat

### Popup Snap tidak muncul

Cek:

- `MIDTRANS_CLIENT_KEY` sudah diisi.
- Browser bisa akses `https://app.sandbox.midtrans.com/snap/snap.js` untuk sandbox.
- Console browser tidak ada error mixed content/CSP.

### Checkout gagal membuat invoice Midtrans

Cek:

- `MIDTRANS_SERVER_KEY` sudah diisi.
- PHP cURL aktif.
- Server bisa akses internet ke endpoint Midtrans.
- Environment key cocok: sandbox key untuk sandbox, production key untuk production.

### Status tidak berubah setelah bayar

Cek:

- Payment Notification URL di dashboard Midtrans sudah benar.
- URL webhook bisa diakses publik, bukan `localhost`.
- Response webhook bukan 500/403.
- Server Key yang dipakai webhook sama dengan environment Midtrans yang mengirim notification.

