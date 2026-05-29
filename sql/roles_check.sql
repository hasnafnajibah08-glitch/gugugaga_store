-- sql/roles_check.sql
-- Cocok dengan schema gugugaga_store_schema.sql

SELECT id, name, description FROM roles ORDER BY id;

-- Schema kamu seharusnya punya data ini:
-- 1 customer
-- 2 admin
-- 3 superadmin

-- Kalau belum ada, jalankan:
INSERT IGNORE INTO roles (id, name, description) VALUES
  (1, 'customer', 'Pelanggan'),
  (2, 'admin', 'Admin toko'),
  (3, 'superadmin', 'Pemilik / super admin');
