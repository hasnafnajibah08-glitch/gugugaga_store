-- QRIS setup helper v12.
-- Jalankan file ini kalau tombol "Lihat QRIS" belum menampilkan QR.

-- 1) Pastikan payment method QRIS aktif dan bertipe qris.
UPDATE payment_methods
SET method_type = 'qris', is_active = 1
WHERE code = 'QRIS';

-- 2) Pastikan setting payload QRIS tersedia.
INSERT INTO site_settings (setting_key, setting_value, setting_type)
VALUES ('qris_static_payload', '', 'text')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- 3) OPSI CEPAT QRIS STATIS:
-- Upload gambar QRIS asli merchant kamu ke root project dengan nama qris.png.
-- Setelah itu aktifkan baris ini:
-- UPDATE payment_methods
-- SET qris_image_url = 'qris.png'
-- WHERE code = 'QRIS';

-- Atau upload ke folder uploads/qris/qris-merchant.png lalu aktifkan baris ini:
-- UPDATE payment_methods
-- SET qris_image_url = 'uploads/qris/qris-merchant.png'
-- WHERE code = 'QRIS';

-- 4) OPSI QRIS DINAMIS:
-- Kalau kolom qris_payload belum ada, jalankan sql/add_qris_payload_column.sql dulu.
-- Setelah itu isi salah satu dari dua tempat ini dengan payload QRIS asli merchant kamu.
-- Payload QRIS asli biasanya berupa teks panjang diawali 000201.
-- Jangan isi dengan path gambar seperti /e-wallet.jpeg.

-- Opsi A: simpan payload di site_settings
-- UPDATE site_settings
-- SET setting_value = '00020101021126...6304ABCD'
-- WHERE setting_key = 'qris_static_payload';

-- Opsi B: simpan payload di payment_methods.qris_payload
-- UPDATE payment_methods
-- SET qris_payload = '00020101021126...6304ABCD'
-- WHERE code = 'QRIS';
