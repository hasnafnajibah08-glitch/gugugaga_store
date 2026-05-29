-- sql/midtrans_snap_integration.sql
-- Jalankan setelah database utama sudah ada.
-- Catatan: jika database MySQL/MariaDB kamu belum mendukung IF NOT EXISTS pada ADD COLUMN,
-- pakai script aman: sql/apply_midtrans_snap_integration.php.

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS midtrans_order_id VARCHAR(80) NULL AFTER invoice_no,
  ADD COLUMN IF NOT EXISTS midtrans_transaction_id VARCHAR(120) NULL AFTER midtrans_order_id,
  ADD COLUMN IF NOT EXISTS midtrans_payment_type VARCHAR(80) NULL AFTER midtrans_transaction_id,
  ADD COLUMN IF NOT EXISTS midtrans_transaction_status VARCHAR(80) NULL AFTER midtrans_payment_type,
  ADD COLUMN IF NOT EXISTS midtrans_fraud_status VARCHAR(80) NULL AFTER midtrans_transaction_status,
  ADD COLUMN IF NOT EXISTS midtrans_snap_token VARCHAR(255) NULL AFTER midtrans_fraud_status,
  ADD COLUMN IF NOT EXISTS midtrans_redirect_url TEXT NULL AFTER midtrans_snap_token,
  ADD COLUMN IF NOT EXISTS midtrans_raw_response LONGTEXT NULL AFTER midtrans_redirect_url,
  ADD COLUMN IF NOT EXISTS midtrans_last_payload LONGTEXT NULL AFTER midtrans_raw_response;

INSERT INTO payment_methods
  (code, name, method_type, logo_url, qris_image_url, instructions, is_active, sort_order)
SELECT
  'MIDTRANS_SNAP',
  'Midtrans Snap Otomatis',
  'midtrans',
  '',
  '',
  'Bayar otomatis via Midtrans Snap. Status pembayaran diperbarui otomatis oleh webhook.',
  1,
  1
WHERE NOT EXISTS (
  SELECT 1 FROM payment_methods WHERE code = 'MIDTRANS_SNAP'
);

UPDATE payment_methods
SET
  name = 'Midtrans Snap Otomatis',
  method_type = 'midtrans',
  instructions = 'Bayar otomatis via Midtrans Snap. Status pembayaran diperbarui otomatis oleh webhook.',
  is_active = 1,
  sort_order = 1
WHERE code = 'MIDTRANS_SNAP';
