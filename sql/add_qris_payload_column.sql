-- Tambahan untuk QRIS dinamis.
-- Jalankan sekali saja di database GUGUGAGA.STORE.

ALTER TABLE payment_methods
  ADD COLUMN qris_payload TEXT NULL AFTER qris_image_url;

-- Setelah kolom dibuat, isi qris_payload dengan payload QRIS merchant asli dari penyedia QRIS kamu.
-- Payload biasanya berupa teks panjang yang diawali 000201...
-- Contoh format perintah:
-- UPDATE payment_methods
-- SET qris_payload = '00020101021126...6304ABCD'
-- WHERE code = 'QRIS';

-- Jangan isi dengan path gambar seperti /e-wallet.jpeg.
-- qris_payload harus payload QRIS/EMV asli agar QR Code yang dihasilkan bisa discan untuk pembayaran.
