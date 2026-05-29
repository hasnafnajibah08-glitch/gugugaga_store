<<<<<<< HEAD
# GUGUGAGA.STORE Admin + User Package v13

## Fokus perbaikan v13

- Frame `Transaksi Masuk` di halaman admin dibuat lebih lebar.
- Kolom `Aksi` dibuat sticky di kanan supaya tidak kepotong.
- Background frame `Transaksi Masuk` dibuat gradasi biru tua.
- Background setiap frame/card utama memakai gradasi warna dominan biru.
- Tampilan tetap rapi dan terang; input, tabel, dan area QR tetap putih agar mudah dibaca dan QR tetap bisa discan.
- Cache asset dinaikkan ke `?v=13`.

## Cara pasang

1. Backup project lama.
2. Extract ZIP ini ke root project.
3. Replace semua file.
4. Buka browser lalu tekan `Ctrl + F5`.

## Cara supaya QRIS muncul

Agar QR Code QRIS yang muncul benar-benar bisa discan untuk pembayaran, kamu harus memilih salah satu cara berikut.

### Cara paling cepat — pakai gambar QRIS statis

1. Upload gambar QRIS merchant asli kamu ke folder utama website dengan nama:

```text
qris.png
```

Contoh posisi file:

```text
index.html
admin.html
qris.png
assets/
api/
config/
```

2. Jalankan SQL ini:

```sql
UPDATE payment_methods
SET qris_image_url = 'qris.png'
WHERE code = 'QRIS';
```

Setelah itu checkout pakai QRIS akan menampilkan gambar QRIS tersebut.

### Alternatif — simpan gambar QRIS di uploads/qris

Upload file ke:

```text
uploads/qris/qris-merchant.png
```

Lalu jalankan:

```sql
UPDATE payment_methods
SET qris_image_url = 'uploads/qris/qris-merchant.png'
WHERE code = 'QRIS';
```

### Cara QRIS dinamis berdasarkan nominal invoice

Cara ini paling bagus karena nominal QR mengikuti total invoice.

1. Jalankan:

```sql
sql/add_qris_payload_column.sql
```

2. Isi payload QRIS asli merchant kamu, yaitu teks QRIS yang biasanya diawali `000201`.

Bisa simpan di `site_settings`:

```sql
INSERT INTO site_settings (setting_key, setting_value, setting_type)
VALUES ('qris_static_payload', 'PASTE_PAYLOAD_QRIS_ASLI_DIAWALI_000201', 'text')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
```

Atau simpan di `payment_methods.qris_payload`:

```sql
UPDATE payment_methods
SET qris_payload = 'PASTE_PAYLOAD_QRIS_ASLI_DIAWALI_000201'
WHERE code = 'QRIS';
```

Jangan isi `qris_payload` dengan path gambar seperti `/e-wallet.jpeg`.

## SQL bantuan

File bantuan ada di:

```text
sql/fix_qris_setup.sql
```

File itu berisi template untuk memastikan QRIS aktif dan instruksi update payload/gambar.

## Catatan penting

Seed database awal kamu mengisi QRIS seperti ini:

```sql
qris_image_url = '/e-wallet.jpeg'
```

Kalau `e-wallet.jpeg` bukan gambar QRIS merchant asli, maka gambar tersebut tidak bisa dipakai untuk pembayaran. Ganti dengan gambar QRIS merchant asli atau isi payload QRIS asli.


Update v13:
- Frame Transaksi Masuk di halaman admin dibuat lebih lebar.
- Tabel admin diberi min-width dan kolom Aksi dibuat sticky di kanan agar tidak kepotong.
- Frame Transaksi Masuk memakai gradasi biru tua.
- Cache asset admin dinaikkan ke ?v=13.
=======
# GUGUGAGASTORE
webggaw
>>>>>>> bea71cdc627907af5f105ce8f2fbb546b40e2cf5
