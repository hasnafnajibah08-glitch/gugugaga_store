-- Isi payload QRIS statis merchant kamu di value qris_static_payload.
-- Cara mendapatkan payload: decode QRIS statis merchant menjadi teks EMVCo, lalu paste ke setting_value.
-- Setelah terisi, sistem akan membuat QRIS dinamis sesuai nominal invoice.

INSERT INTO site_settings (setting_key, setting_value, setting_type)
VALUES ('qris_static_payload', '', 'text')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
